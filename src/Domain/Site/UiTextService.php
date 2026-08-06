<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Site;

use Reptilienmarkt\Domain\Audit\AuditActorType;
use Reptilienmarkt\Domain\Audit\AuditEntry;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Support\Clock;
use Reptilienmarkt\Support\Translator;

/**
 * Oberflaechentexte aendern, ohne die Anwendung neu auszurollen.
 *
 * Was die Verwaltung aendert, ist eine Ueberschreibung — der ausgelieferte
 * Text bleibt daneben stehen und ist jederzeit wieder herstellbar. Drei
 * Regeln halten das Ganze zusammen:
 *
 * 1. Nur bekannte Schluessel. Ein Text, den kein Template abruft, waere ein
 *    Eintrag, den niemand je zu Gesicht bekommt.
 * 2. Platzhalter bleiben erhalten. Wer aus "Noch {anzahl} Tage" ein "Noch
 *    wenige Tage" macht, verliert die Zahl — und niemand merkt es, bis sich
 *    ein Nutzer wundert. Deshalb wird das abgelehnt und nicht gespeichert.
 * 3. Der ausgelieferte Text wird nicht als Ueberschreibung gespeichert. Wer
 *    ihn wiederherstellt, bekommt keinen Eintrag "geaendert auf denselben
 *    Text", sondern gar keinen.
 *
 * Jede Aenderung steht im Audit-Log: Ein Text, der ploetzlich anders lautet,
 * soll sich zurueckverfolgen lassen.
 */
final readonly class UiTextService
{
    /**
     * Laenger als das ist kein Oberflaechentext — dort gehoert ein
     * Rechtstext oder eine Seite hin, keine Beschriftung.
     */
    public const int MAX_LENGTH = 2000;

    public function __construct(
        private Translator $translator,
        private TextOverrideRepository $overrides,
        private AuditLog $audit,
        private Clock $clock,
    ) {}

    /**
     * Alle Texte, wahlweise gefiltert.
     *
     * @return list<UiText>
     */
    public function all(?string $search = null, ?string $section = null): array
    {
        $overrides = $this->overrides->all($this->locale());
        $texts = [];

        foreach ($this->translator->keys($this->locale()) as $key) {
            $original = $this->translator->original($key, $this->locale()) ?? '';
            $override = $overrides[$key] ?? null;

            $text = new UiText(
                $key,
                $original,
                $override === null ? $original : $override->value,
                $override !== null,
                $override?->updatedAt,
            );

            if ($section !== null && $section !== '' && $text->section() !== $section) {
                continue;
            }

            if ($search !== null && !$text->matches($search)) {
                continue;
            }

            $texts[] = $text;
        }

        usort($texts, static fn(UiText $a, UiText $b): int => strcmp($a->key, $b->key));

        return $texts;
    }

    public function find(string $key): ?UiText
    {
        foreach ($this->all() as $text) {
            if ($text->key === $key) {
                return $text;
            }
        }

        return null;
    }

    /**
     * Die Bereiche, nach denen sich die Ansicht gliedern laesst.
     *
     * @return list<string>
     */
    public function sections(): array
    {
        $sections = [];

        foreach ($this->all() as $text) {
            $sections[$text->section()] = true;
        }

        $names = array_keys($sections);
        sort($names);

        return $names;
    }

    public function changedCount(): int
    {
        return \count($this->overrides->all($this->locale()));
    }

    /**
     * @throws TextException
     */
    public function update(string $key, string $text, ?int $adminId = null): UiText
    {
        $original = $this->translator->original($key, $this->locale());

        if ($original === null) {
            throw new TextException(\sprintf('Den Textschlüssel "%s" gibt es nicht.', $key));
        }

        $text = trim($text);

        if ($text === '') {
            throw new TextException(\sprintf('Der Text zu "%s" darf nicht leer sein.', $key));
        }

        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw new TextException(\sprintf(
                'Der Text zu "%s" ist länger als %d Zeichen.',
                $key,
                self::MAX_LENGTH,
            ));
        }

        $fehlend = array_values(array_diff(UiText::placeholdersIn($original), UiText::placeholdersIn($text)));

        if ($fehlend !== []) {
            throw new TextException(\sprintf(
                'Im Text zu "%s" fehlen die Platzhalter %s. Ohne sie steht dort später eine Lücke.',
                $key,
                '{' . implode('}, {', $fehlend) . '}',
            ));
        }

        // Der ausgelieferte Text wird nicht als Aenderung gespeichert.
        if ($text === $original) {
            $this->reset($key, $adminId);

            return new UiText($key, $original, $original);
        }

        $now = $this->clock->now();
        $this->overrides->save(new TextOverride($this->locale(), $key, $text, $now, $adminId));

        $this->audit->record(new AuditEntry(
            'text.updated',
            'ui_text',
            null,
            ['schluessel' => $key, 'sprache' => $this->locale()],
            $adminId,
            AuditActorType::Admin,
        ));

        return new UiText($key, $original, $text, true, $now);
    }

    /**
     * Zurueck auf den ausgelieferten Text.
     */
    public function reset(string $key, ?int $adminId = null): void
    {
        if (!isset($this->overrides->all($this->locale())[$key])) {
            return;
        }

        $this->overrides->delete($this->locale(), $key);

        $this->audit->record(new AuditEntry(
            'text.reset',
            'ui_text',
            null,
            ['schluessel' => $key, 'sprache' => $this->locale()],
            $adminId,
            AuditActorType::Admin,
        ));
    }

    private function locale(): string
    {
        return $this->translator->locale();
    }
}
