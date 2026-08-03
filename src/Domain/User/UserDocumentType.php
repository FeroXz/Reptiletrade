<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\User;

enum UserDocumentType: string
{
    case Ausweis = 'ausweis';
    case Gewerbenachweis = 'gewerbenachweis';

    public function label(): string
    {
        return match ($this) {
            self::Ausweis => 'Ausweisdokument',
            self::Gewerbenachweis => 'Gewerbenachweis',
        };
    }

    /**
     * Was die Pruefung dieses Nachweises freischaltet.
     */
    public function grants(): VerificationLevel
    {
        return VerificationLevel::Identitaet;
    }
}
