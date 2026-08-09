<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Mail;

enum MailStatus: string
{
    case Wartend = 'wartend';
    case Gesendet = 'gesendet';
    case Fehlgeschlagen = 'fehlgeschlagen';

    public function label(): string
    {
        return match ($this) {
            self::Wartend => 'Wartend',
            self::Gesendet => 'Gesendet',
            self::Fehlgeschlagen => 'Fehlgeschlagen',
        };
    }
}
