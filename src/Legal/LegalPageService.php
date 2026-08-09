<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal;

use Reptilienmarkt\Domain\Audit\AuditEntry;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Domain\Content\MarkdownRenderer;
use Reptilienmarkt\Domain\Site\SiteIdentity;
use Reptilienmarkt\Support\Clock;

/**
 * Die Fliesstexte der Rechtsseiten — bearbeitbar, aber nicht beliebig.
 *
 * Die Abschnitte liegen in legal_texts, derselben Tabelle wie die
 * Rechtshinweise der LegalGuard. Das ist kein Zufall: Sie bringt genau das mit,
 * was ein Rechtstext braucht — eine Fundstelle und ein Pruefdatum, aus dem die
 * Verwaltung die Warnung "seit ueber zwoelf Monaten nicht geprueft" bekommt.
 *
 * Zwei Regeln schuetzen die Seiten vor dem Bearbeiten:
 *
 * 1. **Platzhalter muessen erhalten bleiben.** Steht im ausgelieferten Text
 *    {hoster}, muss er auch im geaenderten stehen. Sonst verschwindet der
 *    Auftragsverarbeiter aus der Datenschutzerklaerung, und auffallen wuerde
 *    das erst der Aufsichtsbehoerde. Dieselbe Regel wie bei den
 *    Oberflaechentexten.
 * 2. **Ein Abschnitt kann nicht geleert werden.** Wer ihn nicht will, loescht
 *    ihn — dann ist es eine Entscheidung und keine leere Ueberschrift.
 *
 * Zurueckgesetzt wird auf data/legal_texts.json: Die Datei geht beim
 * Deployment mit und ist damit die einzige Fassung, die einen verlorenen
 * Datenbestand ueberlebt.
 */
final readonly class LegalPageService
{
    /**
     * Die Seiten, deren Abschnitte hier gepflegt werden.
     *
     * @var array<string, string> Seitenschluessel => Beschriftung
     */
    public const array PAGES = [
        'impressum' => 'Impressum',
        'datenschutz' => 'Datenschutzerklärung',
        'nutzungsbedingungen' => 'Nutzungsbedingungen',
    ];

    private const string PREFIX = 'seite.';

    public function __construct(
        private LegalTextRepository $repository,
        private MarkdownRenderer $markdown,
        private AuditLog $audit,
        private Clock $clock,
        private string $seedFile,
    ) {}

    /**
     * Die Abschnitte einer Seite in Reihenfolge.
     *
     * Sortiert wird ueber den Schluessel: "seite.datenschutz.20_verarbeitung"
     * steht vor "...30_cookies". Eine eigene Sortierspalte waere ein zweites
     * Feld, das mit dem Schluessel auseinanderlaufen kann.
     *
     * @return list<LegalText>
     */
    public function sections(string $page): array
    {
        $prefix = self::PREFIX . $page . '.';

        $abschnitte = array_values(array_filter(
            $this->repository->all(),
            static fn(LegalText $text): bool => str_starts_with($text->key, $prefix),
        ));

        usort($abschnitte, static fn(LegalText $a, LegalText $b): int => strcmp($a->key, $b->key));

        return $abschnitte;
    }

    /**
     * Die Abschnitte einer Seite, fertig gerendert.
     *
     * Die Platzhalter werden hier eingesetzt und nicht im Template: Ein
     * Template, das sie selbst ersetzte, muesste jede Seite einzeln kennen.
     *
     * @return list<array{titel: string, html: string, fundstelle: ?string}>
     */
    public function rendered(string $page, SiteIdentity $identity): array
    {
        $ersetzungen = self::placeholders($identity);
        $gerendert = [];

        foreach ($this->sections($page) as $abschnitt) {
            $gerendert[] = [
                'titel' => $abschnitt->title,
                'html' => $this->markdown->render(strtr($abschnitt->body, $ersetzungen)),
                'fundstelle' => $abschnitt->sourceReference,
            ];
        }

        return $gerendert;
    }

    /**
     * Speichert einen Abschnitt.
     *
     * @throws LegalPageException
     */
    public function save(string $key, string $title, string $body, ?string $sourceReference, int $actorId): void
    {
        $vorhanden = $this->repository->find($key);

        if ($vorhanden === null) {
            throw new LegalPageException(\sprintf('Den Abschnitt "%s" gibt es nicht.', $key));
        }

        $title = trim($title);
        $body = trim($body);

        if ($title === '') {
            throw new LegalPageException('Ohne Überschrift geht es nicht — sie steht später über dem Abschnitt.');
        }

        if ($body === '') {
            throw new LegalPageException(
                'Ein leerer Abschnitt hinterlässt eine Überschrift ohne Inhalt. Lösche ihn, wenn er weg soll.',
            );
        }

        $this->guardPlaceholders($key, $body);

        $this->repository->save(new LegalText(
            $vorhanden->id,
            $key,
            $title,
            $body,
            $vorhanden->jurisdiction,
            $sourceReference === null || trim($sourceReference) === '' ? null : trim($sourceReference),
            // Wer einen Rechtstext aendert, hat ihn damit auch geprueft.
            $this->clock->now(),
        ));

        $this->audit->record(new AuditEntry(
            'legal.section_updated',
            'legal_text',
            $vorhanden->id,
            ['schluessel' => $key, 'titel' => $title],
            $actorId,
        ));
    }

    /**
     * Setzt einen Abschnitt auf den ausgelieferten Stand zurueck.
     *
     * @throws LegalPageException
     */
    public function reset(string $key, int $actorId): void
    {
        $vorhanden = $this->repository->find($key);
        $ausgeliefert = $this->delivered()[$key] ?? null;

        if ($vorhanden === null || $ausgeliefert === null) {
            throw new LegalPageException(\sprintf('Zu "%s" gibt es keinen ausgelieferten Stand.', $key));
        }

        $this->repository->save(new LegalText(
            $vorhanden->id,
            $key,
            (string) ($ausgeliefert['title'] ?? $vorhanden->title),
            (string) ($ausgeliefert['body'] ?? $vorhanden->body),
            $vorhanden->jurisdiction,
            isset($ausgeliefert['source_reference']) && \is_string($ausgeliefert['source_reference'])
                ? $ausgeliefert['source_reference']
                : null,
            $vorhanden->lastReviewedAt,
        ));

        $this->audit->record(new AuditEntry(
            'legal.section_reset',
            'legal_text',
            $vorhanden->id,
            ['schluessel' => $key],
            $actorId,
        ));
    }

    /**
     * Bestaetigt, dass ein Abschnitt geprueft wurde — ohne ihn zu aendern.
     */
    public function markReviewed(string $key, int $actorId): void
    {
        $vorhanden = $this->repository->find($key);

        if ($vorhanden === null) {
            throw new LegalPageException(\sprintf('Den Abschnitt "%s" gibt es nicht.', $key));
        }

        $this->repository->markReviewed($key, $vorhanden->jurisdiction, $actorId);

        $this->audit->record(new AuditEntry(
            'legal.section_reviewed',
            'legal_text',
            $vorhanden->id,
            ['schluessel' => $key],
            $actorId,
        ));
    }

    /**
     * Weicht ein Abschnitt vom ausgelieferten Stand ab?
     */
    public function isChanged(LegalText $text): bool
    {
        $ausgeliefert = $this->delivered()[$text->key] ?? null;

        if ($ausgeliefert === null) {
            return false;
        }

        return ($ausgeliefert['body'] ?? null) !== $text->body
            || ($ausgeliefert['title'] ?? null) !== $text->title;
    }

    /**
     * Die Platzhalter, die ein Abschnitt verwenden darf.
     *
     * @return array<string, string>
     */
    public static function placeholders(SiteIdentity $identity): array
    {
        $hosting = $identity->hosting();
        $ort = $hosting['ort'] ?? '';

        return [
            '{hoster}' => $hosting['anbieter'] ?? '',
            // Der Ort haengt am Hoster: Ohne ihn soll auch das " in " weg.
            '{hoster_ort}' => $ort === '' ? '' : ' in ' . $ort,
            '{datenschutz_aufsicht}' => $identity->privacy()['aufsichtsbehoerde'] ?? '',
            '{anbieter}' => $identity->provider()['name'] ?? '',
            '{kontakt_email}' => $identity->contact()['email'] ?? '',
        ];
    }

    /**
     * @throws LegalPageException
     */
    private function guardPlaceholders(string $key, string $body): void
    {
        $ausgeliefert = $this->delivered()[$key]['body'] ?? null;

        if (!\is_string($ausgeliefert)) {
            return;
        }

        $fehlend = [];

        foreach (array_keys(self::placeholders(new SiteIdentity([]))) as $platzhalter) {
            if (str_contains($ausgeliefert, $platzhalter) && !str_contains($body, $platzhalter)) {
                $fehlend[] = $platzhalter;
            }
        }

        if ($fehlend !== []) {
            throw new LegalPageException(\sprintf(
                'Diese Platzhalter fehlen im geänderten Text: %s. Sie setzen die Angaben aus dem Impressum ein — '
                . 'ohne sie stünde dort nichts, und auffallen würde es erst der Aufsichtsbehörde.',
                implode(', ', $fehlend),
            ));
        }
    }

    /**
     * Der ausgelieferte Stand aus data/legal_texts.json.
     *
     * @return array<string, array<string, mixed>>
     */
    private function delivered(): array
    {
        $inhalt = @file_get_contents($this->seedFile);

        if ($inhalt === false) {
            return [];
        }

        /** @var mixed $daten */
        $daten = json_decode($inhalt, true);

        if (!\is_array($daten) || !\is_array($daten['texte'] ?? null)) {
            return [];
        }

        $texte = [];

        foreach ($daten['texte'] as $eintrag) {
            if (\is_array($eintrag) && \is_string($eintrag['key'] ?? null)) {
                $texte[$eintrag['key']] = $eintrag;
            }
        }

        return $texte;
    }
}
