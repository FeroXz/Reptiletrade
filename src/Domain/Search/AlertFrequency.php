<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Search;

/**
 * Wie oft eine gespeicherte Suche sich meldet.
 *
 * Die Werte stehen so schon in der Pruefbedingung der Tabelle (Migration 0008)
 * — das Enum bildet sie ab, es erfindet sie nicht. "sofort" und "woechentlich"
 * sind vorbereitet, eingeplant ist bisher nur der taegliche Lauf.
 */
enum AlertFrequency: string
{
    case Aus = 'aus';
    case Sofort = 'sofort';
    case Taeglich = 'taeglich';
    case Woechentlich = 'woechentlich';

    public function notifies(): bool
    {
        return $this !== self::Aus;
    }

    public function labelKey(): string
    {
        return 'suchen.frequenz.' . $this->value;
    }
}
