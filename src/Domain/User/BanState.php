<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\User;

use DateTimeImmutable;

/**
 * Eine Kontosperre: seit wann, bis wann, warum.
 *
 * "bis wann" ist null bei einer unbefristeten Sperre. Der Unterschied ist
 * keine Formalie — eine befristete Sperre hebt ein Job von selbst wieder auf,
 * eine unbefristete bleibt, bis jemand entscheidet.
 */
final readonly class BanState
{
    public function __construct(
        public DateTimeImmutable $since,
        public ?DateTimeImmutable $until,
        public ?string $reason,
        public ?int $byUserId = null,
    ) {}

    public function isPermanent(): bool
    {
        return $this->until === null;
    }

    public function hasExpired(DateTimeImmutable $now): bool
    {
        return $this->until !== null && $this->until <= $now;
    }

    /**
     * Was der Gesperrte zu lesen bekommt. Der Grund steht drin: Wer nicht
     * erfaehrt, warum er ausgesperrt ist, kann weder nachfragen noch etwas
     * aendern.
     */
    public function explanation(): string
    {
        $text = $this->isPermanent()
            ? 'Dieses Konto ist gesperrt.'
            : \sprintf('Dieses Konto ist bis zum %s gesperrt.', $this->until?->format('d.m.Y H:i') ?? '');

        if ($this->reason !== null && $this->reason !== '') {
            $text .= ' Grund: ' . $this->reason;
        }

        return $text . ' Fragen dazu über das Kontaktformular.';
    }
}
