<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

/**
 * Ein Hinweis zu einer Verpaarung.
 *
 * Die Warnungen sind der eigentliche Ertrag des Simulators. Prozentzahlen kann
 * ein Zuechter auch selbst ausrechnen; dass eine Verpaarung ein Viertel des
 * Geleges nicht ueberleben laesst oder ein tierschutzrelevantes Merkmal
 * verdoppelt, uebersieht man dagegen leicht.
 */
final readonly class GeneticWarning
{
    public const string TYPE_LETHAL = 'letal';

    public const string TYPE_WELFARE = 'tierschutz';

    public const string TYPE_ALLELIC = 'allelisch';

    public const string TYPE_POLYGENIC = 'polygen';

    public const string TYPE_NOT_HERITABLE = 'nicht_vererbbar';

    public const string TYPE_SEX = 'geschlecht';

    public const string TYPE_UNCERTAIN = 'unsicher';

    /**
     * @param ?float $frequency Anteil der betroffenen Nachkommen, sofern berechenbar
     */
    public function __construct(
        public string $type,
        public string $message,
        public WarningSeverity $severity = WarningSeverity::Warnung,
        public ?float $frequency = null,
    ) {}

    public function withFrequency(?float $frequency): self
    {
        return new self($this->type, $this->message, $this->severity, $frequency);
    }

    /**
     * Schluessel zur Entdopplung: Derselbe Hinweis zum selben Genort steht
     * einmal im Bericht, auch wenn er aus mehreren Teilrechnungen stammt.
     */
    public function key(): string
    {
        return $this->type . '|' . $this->message;
    }

    /**
     * @return array{typ: string, meldung: string, schwere: string, anteil: ?float}
     */
    public function toArray(): array
    {
        return [
            'typ' => $this->type,
            'meldung' => $this->message,
            'schwere' => $this->severity->value,
            'anteil' => $this->frequency,
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $frequency = $data['anteil'] ?? null;

        return new self(
            \is_string($data['typ'] ?? null) ? $data['typ'] : 'hinweis',
            \is_string($data['meldung'] ?? null) ? $data['meldung'] : '',
            WarningSeverity::tryFrom(\is_string($data['schwere'] ?? null) ? $data['schwere'] : '') ?? WarningSeverity::Warnung,
            is_numeric($frequency) ? (float) $frequency : null,
        );
    }
}
