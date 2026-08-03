<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\User;

enum Role: string
{
    case Buyer = 'buyer';
    case Seller = 'seller';
    case Breeder = 'breeder';
    case Moderator = 'moderator';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Buyer => 'Käufer',
            self::Seller => 'Verkäufer',
            self::Breeder => 'Züchter',
            self::Moderator => 'Moderation',
            self::Admin => 'Administration',
        };
    }

    public function mayModerate(): bool
    {
        return $this === self::Moderator || $this === self::Admin;
    }

    public function mayCreateListings(): bool
    {
        return $this !== self::Buyer;
    }
}
