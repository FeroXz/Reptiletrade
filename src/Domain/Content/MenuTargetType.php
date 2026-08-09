<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

/**
 * Worauf ein Menueeintrag zeigt.
 *
 * "entry" traegt die ID eines Inhalts — der Pfad wird beim Rendern
 * nachgeschlagen und wandert damit von selbst mit, wenn der Slug sich aendert.
 * "route" ist ein fester Pfad der Anwendung (/markt/, /tarife), "url" eine
 * fremde Adresse.
 */
enum MenuTargetType: string
{
    case Entry = 'entry';
    case Route = 'route';
    case Url = 'url';

    public function label(): string
    {
        return match ($this) {
            self::Entry => 'Inhalt',
            self::Route => 'Seite der Anwendung',
            self::Url => 'Fremde Adresse',
        };
    }
}
