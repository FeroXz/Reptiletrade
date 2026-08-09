<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;

/**
 * Wem ein Menueeintrag gezeigt wird.
 *
 * Das ist Darstellung, nicht Zugriffsschutz: Wer den Pfad kennt, ruft ihn
 * auch ohne Menueeintrag auf. Der Schutz sitzt in den Controllern — hier geht
 * es darum, dass "Registrieren" fuer Angemeldete verschwindet.
 */
enum MenuVisibility: string
{
    case Alle = 'alle';
    case Angemeldet = 'angemeldet';
    case Gast = 'gast';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Alle => 'Alle',
            self::Angemeldet => 'Nur Angemeldete',
            self::Gast => 'Nur Nichtangemeldete',
            self::Admin => 'Nur Verwaltung',
        };
    }

    public function allows(?User $user): bool
    {
        return match ($this) {
            self::Alle => true,
            self::Angemeldet => $user !== null,
            self::Gast => $user === null,
            self::Admin => $user?->role === Role::Admin,
        };
    }
}
