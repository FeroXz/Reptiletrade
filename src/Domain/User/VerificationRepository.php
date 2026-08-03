<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\User;

use DateTimeImmutable;

/**
 * Schreibzugriffe auf die Verifizierungs- und 2FA-Felder des Kontos.
 *
 * Bewusst getrennt von UserRepository: Diese Felder aendert nur die
 * Kontoverwaltung, und der Rest der Anwendung soll sie gar nicht erst
 * anfassen koennen.
 */
interface VerificationRepository
{
    public function markEmailVerified(int $userId, DateTimeImmutable $at): void;

    public function setPhone(int $userId, string $phone): void;

    public function markPhoneVerified(int $userId, DateTimeImmutable $at): void;

    public function markIdentityVerified(int $userId, DateTimeImmutable $at): void;

    public function revokeIdentityVerification(int $userId): void;

    public function storeTotpSecret(int $userId, string $secret): void;

    /**
     * Das Geheimnis, auch wenn es noch nicht bestaetigt wurde.
     */
    public function totpSecret(int $userId): ?string;

    /**
     * Nur das bestaetigte Geheimnis — fuer die Pruefung beim Anmelden.
     */
    public function confirmedTotpSecret(int $userId): ?string;

    public function confirmTotp(int $userId, DateTimeImmutable $at): void;

    public function clearTotp(int $userId): void;

    public function clearLoginFailures(int $userId): void;

    public function setRole(int $userId, Role $role): void;

    public function setCommercial(int $userId, bool $isCommercial, ?string $erlaubnisNumber, ?string $imprint): void;
}
