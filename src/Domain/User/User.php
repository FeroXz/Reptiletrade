<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\User;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Geo\Country;

final readonly class User
{
    public function __construct(
        public ?int $id,
        public string $email,
        public string $displayName,
        public Role $role = Role::Seller,
        public UserStatus $status = UserStatus::Aktiv,
        public ?DateTimeImmutable $emailVerifiedAt = null,
        public ?string $phone = null,
        public ?DateTimeImmutable $phoneVerifiedAt = null,
        public ?DateTimeImmutable $identityVerifiedAt = null,
        public bool $isCommercial = false,
        public ?string $erlaubnis11Number = null,
        public ?string $imprint = null,
        public ?string $postalCode = null,
        public ?Country $country = null,
    ) {}

    public function emailCanonical(): string
    {
        return mb_strtolower(trim($this->email), 'UTF-8');
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Aktiv;
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->emailVerifiedAt !== null;
    }

    public function hasImprint(): bool
    {
        return $this->imprint !== null && trim($this->imprint) !== '';
    }
}
