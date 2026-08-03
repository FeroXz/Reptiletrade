<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\VerificationRepository;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoVerificationRepository implements VerificationRepository
{
    public function __construct(private Database $database) {}

    public function markEmailVerified(int $userId, DateTimeImmutable $at): void
    {
        $this->touch($userId, 'email_verified_at = :at', ['at' => Timestamp::utc($at)]);
    }

    public function setPhone(int $userId, string $phone): void
    {
        // Eine geaenderte Nummer ist eine unbestaetigte Nummer.
        $this->touch($userId, 'phone = :phone, phone_verified_at = NULL', ['phone' => $phone]);
    }

    public function markPhoneVerified(int $userId, DateTimeImmutable $at): void
    {
        $this->touch($userId, 'phone_verified_at = :at', ['at' => Timestamp::utc($at)]);
    }

    public function markIdentityVerified(int $userId, DateTimeImmutable $at): void
    {
        $this->touch($userId, 'identity_verified_at = :at', ['at' => Timestamp::utc($at)]);
    }

    public function revokeIdentityVerification(int $userId): void
    {
        $this->touch($userId, 'identity_verified_at = NULL');
    }

    public function storeTotpSecret(int $userId, string $secret): void
    {
        // Beim Neuanlegen faellt die Bestaetigung weg: Das alte Geraet soll
        // nach dem Wechsel nicht weiter gelten.
        $this->touch($userId, 'totp_secret = :secret, totp_confirmed_at = NULL', ['secret' => $secret]);
    }

    public function totpSecret(int $userId): ?string
    {
        $value = $this->database->scalar('SELECT totp_secret FROM users WHERE id = :id', ['id' => $userId]);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    public function confirmedTotpSecret(int $userId): ?string
    {
        $value = $this->database->scalar(
            'SELECT totp_secret FROM users WHERE id = :id AND totp_confirmed_at IS NOT NULL',
            ['id' => $userId],
        );

        return \is_string($value) && $value !== '' ? $value : null;
    }

    public function confirmTotp(int $userId, DateTimeImmutable $at): void
    {
        $this->touch($userId, 'totp_confirmed_at = :at', ['at' => Timestamp::utc($at)]);
    }

    public function clearTotp(int $userId): void
    {
        $this->touch($userId, 'totp_secret = NULL, totp_confirmed_at = NULL');
    }

    public function clearLoginFailures(int $userId): void
    {
        $this->touch($userId, 'failed_login_attempts = 0, locked_until = NULL');
    }

    public function setRole(int $userId, Role $role): void
    {
        $this->touch($userId, 'role = :role', ['role' => $role->value]);
    }

    public function setCommercial(int $userId, bool $isCommercial, ?string $erlaubnisNumber, ?string $imprint): void
    {
        $this->touch(
            $userId,
            'is_commercial = :commercial, erlaubnis_11_number = :nummer, imprint = :impressum',
            [
                'commercial' => $isCommercial ? 1 : 0,
                'nummer' => $erlaubnisNumber,
                'impressum' => $imprint,
            ],
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function touch(int $userId, string $assignments, array $parameters = []): void
    {
        $this->database->execute(
            \sprintf('UPDATE users SET %s, updated_at = :updated WHERE id = :id', $assignments),
            $parameters + ['updated' => Timestamp::now(), 'id' => $userId],
        );
    }
}
