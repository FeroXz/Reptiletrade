<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Billing;

use InvalidArgumentException;

/**
 * Betrag in der kleinsten Waehrungseinheit.
 *
 * Ganzzahlig, nie float: 0.1 + 0.2 ergibt in Fliesskomma nicht 0.3, und bei
 * Geld faellt genau das irgendwann jemandem auf die Fuesse.
 */
final readonly class Money
{
    public function __construct(
        public int $cents,
        public string $currency = 'EUR',
    ) {
        if (\strlen($currency) !== 3) {
            throw new InvalidArgumentException('Waehrungscode muss dreistellig sein.');
        }
    }

    public static function zero(string $currency = 'EUR'): self
    {
        return new self(0, $currency);
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents + $other->cents, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents - $other->cents, $this->currency);
    }

    /**
     * Der im Bruttopreis enthaltene Steueranteil, kaufmaennisch gerundet.
     */
    public function taxPortion(float $ratePercent): self
    {
        if ($ratePercent <= 0.0) {
            return self::zero($this->currency);
        }

        $net = $this->cents / (1 + $ratePercent / 100);

        return new self((int) round($this->cents - $net), $this->currency);
    }

    public function format(): string
    {
        return number_format($this->cents / 100, 2, ',', '.') . ' ' . $this->currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException(\sprintf(
                'Betraege in %s und %s lassen sich nicht verrechnen.',
                $this->currency,
                $other->currency,
            ));
        }
    }
}
