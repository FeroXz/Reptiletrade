<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Site;

/**
 * Die Angaben aus config/impressum.php, gelesen und geprueft.
 *
 * Der Sinn der Pruefung: Ein Impressum mit Platzhaltern sieht aus wie ein
 * fertiges. Solange Pflichtangaben fehlen, sagt die Seite das sichtbar — und
 * bin/doctor.php meldet es auf der Kommandozeile.
 */
final readonly class SiteIdentity
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private array $config) {}

    /**
     * @return array<string, string>
     */
    public function provider(): array
    {
        return $this->section('anbieter');
    }

    /**
     * @return array<string, string>
     */
    public function contact(): array
    {
        return $this->section('kontakt');
    }

    /**
     * @return array<string, string>
     */
    public function register(): array
    {
        return $this->section('register');
    }

    /**
     * @return array<string, string>
     */
    public function privacy(): array
    {
        return $this->section('datenschutz');
    }

    /**
     * @return array<string, string>
     */
    public function hosting(): array
    {
        return $this->section('hosting');
    }

    public function value(string $key, string $default = ''): string
    {
        $value = $this->config[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : $default;
    }

    public function readyForDisputeResolution(): bool
    {
        $streit = $this->config['streitschlichtung'] ?? [];

        return \is_array($streit) && ($streit['bereit'] ?? false) === true;
    }

    public function disputeBody(): string
    {
        $streit = $this->config['streitschlichtung'] ?? [];

        return \is_array($streit) && \is_string($streit['stelle'] ?? null) ? $streit['stelle'] : '';
    }

    /**
     * Die Anschrift als Zeilen — so, wie sie im Impressum steht.
     *
     * @return list<string>
     */
    public function addressLines(): array
    {
        $anbieter = $this->provider();

        $zeilen = [$anbieter['name'] ?? ''];

        foreach (['rechtsform', 'vertreten_durch', 'strasse'] as $feld) {
            if (($anbieter[$feld] ?? '') !== '') {
                $zeilen[] = $anbieter[$feld];
            }
        }

        $ort = trim(($anbieter['plz'] ?? '') . ' ' . ($anbieter['ort'] ?? ''));

        if ($ort !== '') {
            $zeilen[] = $ort;
        }

        if (($anbieter['land'] ?? '') !== '') {
            $zeilen[] = $anbieter['land'];
        }

        return array_values(array_filter($zeilen, static fn(string $zeile): bool => $zeile !== ''));
    }

    /**
     * Was noch fehlt. Leer heisst: Alle Pflichtangaben stehen.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $fehlt = [];
        $anbieter = $this->provider();

        foreach ([
            'name' => 'Name oder Firma des Anbieters (§ 5 DDG)',
            'strasse' => 'Straße und Hausnummer — ein Postfach genügt nicht',
            'ort' => 'Ort',
        ] as $feld => $bezeichnung) {
            if (($anbieter[$feld] ?? '') === '' || str_contains($anbieter[$feld] ?? '', 'AUSFÜLLEN')) {
                $fehlt[] = $bezeichnung;
            }
        }

        if (($anbieter['plz'] ?? '') === '' || ($anbieter['plz'] ?? '') === '00000') {
            $fehlt[] = 'Postleitzahl';
        }

        $email = $this->contact()['email'] ?? '';

        if ($email === '' || str_contains($email, 'AUSFÜLLEN') || filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            $fehlt[] = 'E-Mail-Adresse für den unmittelbaren Kontakt (§ 5 DDG)';
        }

        if (str_contains($this->hosting()['anbieter'] ?? '', 'AUSFÜLLEN')) {
            $fehlt[] = 'Hosting-Anbieter für die Datenschutzerklärung';
        }

        return $fehlt;
    }

    public function isComplete(): bool
    {
        return $this->missing() === [] && ($this->config['unvollstaendig'] ?? true) !== true;
    }

    /**
     * @return array<string, string>
     */
    private function section(string $key): array
    {
        $section = $this->config[$key] ?? [];
        $werte = [];

        if (\is_array($section)) {
            foreach ($section as $name => $value) {
                if (\is_string($name) && \is_scalar($value)) {
                    $werte[$name] = (string) $value;
                }
            }
        }

        return $werte;
    }
}
