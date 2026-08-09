<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Site;

use Reptilienmarkt\Domain\Audit\AuditEntry;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Support\Clock;

/**
 * Die Impressumsangaben aendern, ohne die Anwendung neu auszurollen.
 *
 * Was die Verwaltung eintraegt, ist eine **Ueberschreibung**: Der ausgelieferte
 * Stand aus config/impressum.php bleibt daneben stehen und ist je Feld wieder
 * herstellbar — dieselbe Aufteilung wie bei den Oberflaechentexten.
 *
 * Drei Regeln halten das zusammen:
 *
 * 1. **Nur bekannte Felder.** FIELDS ist eine Allowlist. Ohne sie waere jedes
 *    Formularfeld ein Schreibzugriff auf einen beliebigen Konfigurationspfad.
 * 2. **Pflichtangaben duerfen nicht geleert werden.** Ein Impressum ohne
 *    Anschrift ist kein Impressum, und ein leeres Feld faellt beim Speichern
 *    niemandem auf — auf der Seite dagegen erst dem Abmahner.
 * 3. **Der ausgelieferte Wert wird nicht als Ueberschreibung gespeichert.**
 *    Wer ihn wieder eintraegt, meint "zurueck auf Anfang" und bekommt keinen
 *    Eintrag "geaendert auf denselben Wert".
 *
 * Jede Aenderung steht im Audit-Trail. Wer haftet, muss belegen koennen, was
 * wann auf der Seite stand.
 */
final readonly class SiteIdentityService
{
    /**
     * Laenger als das ist keine Impressumsangabe. Fliesstext gehoert in die
     * Abschnitte, nicht in ein Stammdatenfeld.
     */
    public const int MAX_LENGTH = 500;

    /**
     * Die Allowlist: Feldschluessel => [Gruppe, Beschriftung, Hinweis, Pflicht].
     *
     * Der Schluessel ist der Pfad in config/impressum.php mit Punkt getrennt.
     * Die Gruppenbeschriftung steht hier und nicht als Uebersetzungsschluessel
     * im Template: Ein dort zusammengebauter Schluessel entzieht sich der
     * Katalogpruefung, und ein fehlender Text faellt dann erst auf der Seite auf.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: bool}>
     */
    private const array FIELDS = [
        'anbieter.name' => ['Anbieter (§ 5 DDG)', 'Name oder Firma', 'Bei Gesellschaften die Firma samt Rechtsform (§ 5 DDG).', true],
        'anbieter.rechtsform' => ['Anbieter (§ 5 DDG)', 'Rechtsform', 'Nur, wenn sie nicht schon im Namen steht.', false],
        'anbieter.vertreten_durch' => ['Anbieter (§ 5 DDG)', 'Vertreten durch', 'Geschäftsführung oder Vorstand, sofern vorhanden.', false],
        'anbieter.strasse' => ['Anbieter (§ 5 DDG)', 'Straße und Hausnummer', 'Ein Postfach genügt nicht — § 5 DDG verlangt eine ladungsfähige Anschrift.', true],
        'anbieter.plz' => ['Anbieter (§ 5 DDG)', 'Postleitzahl', '', true],
        'anbieter.ort' => ['Anbieter (§ 5 DDG)', 'Ort', '', true],
        'anbieter.land' => ['Anbieter (§ 5 DDG)', 'Land', '', false],

        'kontakt.email' => ['Kontakt', 'E-Mail', 'Pflicht nach § 5 DDG. Muss tatsächlich gelesen werden.', true],
        'kontakt.telefon' => ['Kontakt', 'Telefon', 'Zweiter Weg für unmittelbare Kommunikation. Fehlt er, zählt das Kontaktformular nur bei zügiger Antwort.', false],

        'register.gericht' => ['Registereintrag', 'Registergericht', 'Nur bei Eintragung.', false],
        'register.nummer' => ['Registereintrag', 'Registernummer', '', false],

        'umsatzsteuer_id' => ['Steuer und Verantwortung', 'Umsatzsteuer-ID', 'Nach § 27a UStG. Die Steuernummer gehört NICHT ins Impressum.', false],
        'inhaltlich_verantwortlich' => ['Steuer und Verantwortung', 'Redaktionell verantwortlich', 'Name und Anschrift nach § 18 Abs. 2 MStV. Leer = wie Anbieter.', false],
        'aufsichtsbehoerde' => ['Steuer und Verantwortung', 'Aufsichtsbehörde', 'Nur, wenn die Tätigkeit einer Zulassung bedarf.', false],

        'streitschlichtung.stelle' => ['Verbraucherstreitbeilegung', 'Verbraucherschlichtungsstelle', 'Nur auszufüllen, wenn die Teilnahme unten bejaht wird.', false],

        'datenschutz.verantwortlicher' => ['Datenschutz', 'Verantwortlicher (Datenschutz)', 'Leer = der Anbieter oben.', false],
        'datenschutz.beauftragter' => ['Datenschutz', 'Datenschutzbeauftragte Person', 'Nur unter den Voraussetzungen des Art. 37 DSGVO / § 38 BDSG Pflicht.', false],
        'datenschutz.beauftragter_email' => ['Datenschutz', 'E-Mail der beauftragten Person', '', false],
        'datenschutz.aufsichtsbehoerde' => ['Datenschutz', 'Datenschutz-Aufsichtsbehörde', 'Für die Beschwerde nach Art. 77 DSGVO — die für deinen Sitz zuständige Landesbehörde.', true],

        'hosting.anbieter' => ['Hosting', 'Hosting-Anbieter', 'Steht in der Datenschutzerklärung unter „Empfänger".', true],
        'hosting.ort' => ['Hosting', 'Ort des Hostings', '', false],
    ];

    /**
     * Schalter — sie sind keine Textfelder und werden getrennt behandelt.
     *
     * @var array<string, string>
     */
    private const array FLAGS = [
        'streitschlichtung.bereit' => 'Wir nehmen an einem Streitbeilegungsverfahren teil',
        'hosting.avv_geschlossen' => 'Auftragsverarbeitungsvertrag mit dem Hoster geschlossen (Art. 28 DSGVO)',
        'unvollstaendig' => 'Angaben sind noch unvollständig — Warnhinweis auf allen Rechtsseiten zeigen',
    ];

    /**
     * @param array<string, mixed> $config der ausgelieferte Stand
     */
    public function __construct(
        private array $config,
        private SiteIdentityOverrideRepository $overrides,
        private AuditLog $audit,
        private Clock $clock,
    ) {}

    /**
     * Alle Felder mit ausgeliefertem und geltendem Wert.
     *
     * @return list<SiteIdentityField>
     */
    public function fields(): array
    {
        $overrides = $this->overrides->withMeta();
        $felder = [];

        foreach (self::FIELDS as $key => [$group, $label, $hint, $required]) {
            $delivered = self::dig($this->config, $key);
            $override = $overrides[$key] ?? null;

            $felder[] = new SiteIdentityField(
                key: $key,
                group: $group,
                label: $label,
                hint: $hint,
                delivered: $delivered,
                effective: $override->value ?? $delivered,
                overridden: $override !== null,
                required: $required,
                updatedAt: $override?->updatedAt,
            );
        }

        return $felder;
    }

    /**
     * Die Schalter mit ihrem geltenden Wert.
     *
     * @return array<string, array{label: string, value: bool}>
     */
    public function flags(): array
    {
        $overrides = $this->overrides->all();
        $schalter = [];

        foreach (self::FLAGS as $key => $label) {
            $wert = \array_key_exists($key, $overrides)
                ? $overrides[$key] === '1'
                : self::digBool($this->config, $key);

            $schalter[$key] = ['label' => $label, 'value' => $wert];
        }

        return $schalter;
    }

    /**
     * Speichert die geaenderten Felder.
     *
     * @param array<string, string> $values    Feldschluessel => Wert
     * @param array<string, bool>   $flagValues
     *
     * @return int Anzahl der tatsaechlich geaenderten Eintraege
     *
     * @throws SiteIdentityException
     */
    public function save(array $values, array $flagValues, int $actorId): int
    {
        $geaendert = [];
        $now = $this->clock->now();

        foreach ($values as $key => $value) {
            if (!isset(self::FIELDS[$key])) {
                // Laut, nicht still: Ein unbekanntes Feld im Formular ist kein
                // Tippfehler, sondern ein manipuliertes Formular.
                throw new SiteIdentityException(\sprintf('Unbekanntes Feld "%s".', $key));
            }

            $value = trim($value);
            [, , , $required] = self::FIELDS[$key];

            if (mb_strlen($value) > self::MAX_LENGTH) {
                throw new SiteIdentityException(\sprintf(
                    'Das Feld "%s" ist länger als %d Zeichen. Fließtext gehört in die Abschnitte.',
                    self::FIELDS[$key][1],
                    self::MAX_LENGTH,
                ));
            }

            if ($required && $value === '') {
                throw new SiteIdentityException(\sprintf(
                    'Das Feld "%s" ist eine Pflichtangabe und darf nicht leer bleiben.',
                    self::FIELDS[$key][1],
                ));
            }

            if ($key === 'kontakt.email' && $value !== '' && filter_var($value, \FILTER_VALIDATE_EMAIL) === false) {
                throw new SiteIdentityException('Die E-Mail-Adresse im Impressum ist keine gültige Adresse.');
            }

            $delivered = self::dig($this->config, $key);
            $bisher = $this->overrides->all()[$key] ?? $delivered;

            if ($value === $bisher) {
                continue;
            }

            if ($value === $delivered) {
                // Zurueck auf den ausgelieferten Wert heisst: kein Eintrag.
                $this->overrides->remove($key);
            } else {
                $this->overrides->set($key, $value, $now, $actorId);
            }

            $geaendert[] = $key;
        }

        foreach ($flagValues as $key => $value) {
            if (!isset(self::FLAGS[$key])) {
                throw new SiteIdentityException(\sprintf('Unbekannter Schalter "%s".', $key));
            }

            $delivered = self::digBool($this->config, $key);
            $overrides = $this->overrides->all();
            $bisher = \array_key_exists($key, $overrides) ? $overrides[$key] === '1' : $delivered;

            if ($value === $bisher) {
                continue;
            }

            if ($value === $delivered) {
                $this->overrides->remove($key);
            } else {
                $this->overrides->set($key, $value ? '1' : '0', $now, $actorId);
            }

            $geaendert[] = $key;
        }

        if ($geaendert !== []) {
            $this->audit->record(new AuditEntry(
                'legal.identity_updated',
                'site_identity',
                null,
                ['felder' => $geaendert],
                $actorId,
            ));
        }

        return \count($geaendert);
    }

    /**
     * Setzt ein Feld auf den ausgelieferten Stand zurueck.
     */
    public function reset(string $key, int $actorId): void
    {
        if (!isset(self::FIELDS[$key]) && !isset(self::FLAGS[$key])) {
            throw new SiteIdentityException(\sprintf('Unbekanntes Feld "%s".', $key));
        }

        $this->overrides->remove($key);

        $this->audit->record(new AuditEntry(
            'legal.identity_reset',
            'site_identity',
            null,
            ['feld' => $key],
            $actorId,
        ));
    }

    public function changedCount(): int
    {
        return $this->overrides->count();
    }

    /**
     * Der geltende Stand — Konfiguration plus Ueberschreibungen.
     */
    public function identity(): SiteIdentity
    {
        return SiteIdentity::merged($this->config, $this->overrides->all());
    }

    /**
     * Liest einen Punktpfad aus der Konfiguration.
     *
     * @param array<string, mixed> $config
     */
    private static function dig(array $config, string $key): string
    {
        $wert = self::walk($config, $key);

        return \is_scalar($wert) ? (string) $wert : '';
    }

    /**
     * Folgt einem Punktpfad durch die verschachtelte Konfiguration.
     *
     * @param array<string, mixed> $config
     */
    private static function walk(array $config, string $key): mixed
    {
        $teile = explode('.', $key);
        $letzter = array_pop($teile);

        foreach ($teile as $teil) {
            $naechste = $config[$teil] ?? null;

            if (!\is_array($naechste)) {
                return null;
            }

            $config = $naechste;
        }

        return $config[$letzter] ?? null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function digBool(array $config, string $key): bool
    {
        return self::walk($config, $key) === true;
    }
}
