<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\User\AdminUserRow;
use Reptilienmarkt\Domain\User\BanState;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Domain\User\UserRepository;
use Reptilienmarkt\Domain\User\UserStatus;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoUserRepository implements UserRepository
{
    private const string COLUMNS = 'id, email, display_name, role, status, email_verified_at, phone, phone_verified_at, '
        . 'identity_verified_at, is_commercial, erlaubnis_11_number, imprint, postal_code, country, created_at, '
        . 'totp_confirmed_at';

    public function __construct(private Database $database) {}

    public function findById(int $id): ?User
    {
        $row = $this->database->selectOne('SELECT ' . self::COLUMNS . ' FROM users WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->map($row);
    }

    public function findByEmail(string $email): ?User
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE email_canonical = :email',
            ['email' => mb_strtolower(trim($email), 'UTF-8')],
        );

        return $row === null ? null : $this->map($row);
    }

    public function passwordHashFor(int $userId): ?string
    {
        $value = $this->database->scalar('SELECT password_hash FROM users WHERE id = :id', ['id' => $userId]);

        return \is_string($value) ? $value : null;
    }

    public function create(User $user, string $passwordHash): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $this->database->execute(
            'INSERT INTO users (email, email_canonical, password_hash, display_name, role, status,
                                is_commercial, postal_code, country, unsubscribe_secret, unsubscribe_secret_at,
                                created_at, updated_at)
             VALUES (:email, :canonical, :hash, :name, :role, :status,
                     :commercial, :postal_code, :country, :unsubscribe_secret, :now,
                     :now, :now)',
            [
                'email' => trim($user->email),
                'canonical' => $user->emailCanonical(),
                'hash' => $passwordHash,
                'name' => $user->displayName,
                'role' => $user->role->value,
                'status' => $user->status->value,
                'commercial' => $user->isCommercial ? 1 : 0,
                'postal_code' => $user->postalCode,
                'country' => $user->country?->value,
                // Das Geheimnis, aus dem der Abmeldelink abgeleitet wird,
                // entsteht mit dem Konto. Es erst beim ersten Versand
                // nachzuziehen hiesse, dass ausgerechnet die erste Mail eines
                // Kontos doch wieder in users schreibt.
                'unsubscribe_secret' => bin2hex(random_bytes(32)),
                'now' => $now,
            ],
        );

        return $this->database->lastInsertId();
    }

    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        $this->database->execute(
            'UPDATE users SET password_hash = :hash, updated_at = :now WHERE id = :id',
            ['hash' => $passwordHash, 'now' => gmdate('Y-m-d\TH:i:s\Z'), 'id' => $userId],
        );
    }

    public function recordSuccessfulLogin(int $userId): void
    {
        $this->database->execute(
            'UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = :now WHERE id = :id',
            ['now' => gmdate('Y-m-d\TH:i:s\Z'), 'id' => $userId],
        );
    }

    public function recordFailedLogin(int $userId): int
    {
        $this->database->execute(
            'UPDATE users SET failed_login_attempts = failed_login_attempts + 1 WHERE id = :id',
            ['id' => $userId],
        );

        $value = $this->database->scalar('SELECT failed_login_attempts FROM users WHERE id = :id', ['id' => $userId]);

        return (int) (is_numeric($value) ? $value : 0);
    }

    public function lockUntil(int $userId, string $until): void
    {
        $this->database->execute(
            'UPDATE users SET locked_until = :until WHERE id = :id',
            ['until' => $until, 'id' => $userId],
        );
    }

    public function lockedUntil(int $userId): ?string
    {
        $value = $this->database->scalar('SELECT locked_until FROM users WHERE id = :id', ['id' => $userId]);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    public function salesStatistics(int $userId): array
    {
        $row = $this->database->selectOne(
            <<<'SQL'
                SELECT
                    (SELECT COUNT(*) FROM listings
                      WHERE user_id = :user_id AND status IN ('aktiv','reserviert')) AS aktive_anzeigen,
                    (SELECT COUNT(*) FROM listings
                      WHERE user_id = :user_id AND status = 'verkauft' AND updated_at >= :seit) AS verkaeufe
                SQL,
            [
                'user_id' => $userId,
                'seit' => gmdate('Y-m-d\TH:i:s\Z', time() - 365 * 86400),
            ],
        );

        return [
            'aktive_anzeigen' => (int) ($row['aktive_anzeigen'] ?? 0),
            'verkaeufe_12_monate' => (int) ($row['verkaeufe'] ?? 0),
        ];
    }

    public function publishedListingCount(int $userId): int
    {
        $value = $this->database->scalar(
            "SELECT COUNT(*) FROM listings WHERE user_id = :user AND status <> 'entwurf'",
            ['user' => $userId],
        );

        return (int) (is_numeric($value) ? $value : 0);
    }

    public function findByDisplayName(string $displayName): ?User
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE display_name = :name LIMIT 1',
            ['name' => $displayName],
        );

        return $row === null ? null : $this->map($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): User
    {
        $country = $row['country'];

        return new User(
            (int) $row['id'],
            (string) $row['email'],
            (string) $row['display_name'],
            Role::from((string) $row['role']),
            UserStatus::from((string) $row['status']),
            $this->date($row['email_verified_at']),
            $row['phone'] === null ? null : (string) $row['phone'],
            $this->date($row['phone_verified_at']),
            $this->date($row['identity_verified_at']),
            (bool) $row['is_commercial'],
            $row['erlaubnis_11_number'] === null ? null : (string) $row['erlaubnis_11_number'],
            $row['imprint'] === null ? null : (string) $row['imprint'],
            $row['postal_code'] === null ? null : (string) $row['postal_code'],
            \is_string($country) ? Country::tryFrom($country) : null,
            $this->date($row['created_at']),
            $row['totp_confirmed_at'] !== null,
        );
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        return \is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }

    // --------------------------------------------------------- Kontosperren

    public function ban(int $userId, DateTimeImmutable $at, ?DateTimeImmutable $until, string $reason, ?int $byUserId): void
    {
        $this->database->execute(
            "UPDATE users
                SET status = 'gesperrt',
                    banned_at = :at,
                    banned_until = :until,
                    banned_reason = :reason,
                    banned_by = :by,
                    updated_at = :at
              WHERE id = :id",
            [
                'at' => Timestamp::utc($at),
                'until' => Timestamp::utcOrNull($until),
                'reason' => $reason,
                'by' => $byUserId,
                'id' => $userId,
            ],
        );
    }

    public function unban(int $userId): void
    {
        // Der Status geht auf "aktiv" zurueck, nicht auf den Zustand davor:
        // Gesperrt wird nur, was vorher aktiv war, und ein geloeschtes Konto
        // faengt die Pruefung im Dienst ab.
        $this->database->execute(
            "UPDATE users
                SET status = 'aktiv',
                    banned_at = NULL,
                    banned_until = NULL,
                    banned_reason = NULL,
                    banned_by = NULL,
                    updated_at = :now
              WHERE id = :id AND status = 'gesperrt'",
            ['now' => Timestamp::now(), 'id' => $userId],
        );
    }

    public function banState(int $userId): ?BanState
    {
        $row = $this->database->selectOne(
            'SELECT banned_at, banned_until, banned_reason, banned_by FROM users WHERE id = :id',
            ['id' => $userId],
        );

        if ($row === null || !\is_string($row['banned_at'])) {
            return null;
        }

        return new BanState(
            Timestamp::parse($row['banned_at']) ?? new DateTimeImmutable($row['banned_at']),
            \is_string($row['banned_until']) ? Timestamp::parse($row['banned_until']) : null,
            \is_string($row['banned_reason']) && $row['banned_reason'] !== '' ? $row['banned_reason'] : null,
            $row['banned_by'] === null ? null : (int) $row['banned_by'],
        );
    }

    public function withExpiredBan(DateTimeImmutable $now): array
    {
        $rows = $this->database->select(
            "SELECT " . self::COLUMNS . " FROM users
              WHERE status = 'gesperrt' AND banned_until IS NOT NULL AND banned_until <= :now
              LIMIT 500",
            ['now' => Timestamp::utc($now)],
        );

        return array_map(fn(array $row): User => $this->map($row), $rows);
    }

    public function forAdmin(array $filters = [], int $limit = 100): array
    {
        $bedingungen = [];
        $parameter = ['limit' => $limit];

        if (isset($filters['status']) && UserStatus::tryFrom($filters['status']) !== null) {
            $bedingungen[] = 'u.status = :status';
            $parameter['status'] = $filters['status'];
        }

        if (($filters['nur_gesperrt'] ?? false) === true) {
            $bedingungen[] = "u.status = 'gesperrt'";
        }

        if (isset($filters['suche']) && trim($filters['suche']) !== '') {
            $bedingungen[] = '(u.display_name LIKE :suche OR u.email LIKE :suche)';
            $parameter['suche'] = '%' . trim($filters['suche']) . '%';
        }

        $where = $bedingungen === [] ? '' : ' WHERE ' . implode(' AND ', $bedingungen);

        $rows = $this->database->select(
            'SELECT u.id, u.email, u.display_name, u.role, u.status, u.created_at, u.last_login_at,
                    u.banned_at, u.banned_until, u.banned_reason, u.banned_by,
                    (SELECT COUNT(*) FROM listings l WHERE l.user_id = u.id) AS anzeigen,
                    (SELECT COUNT(*) FROM reports r
                      WHERE r.target_type = \'user\' AND r.target_id = u.id AND r.status = \'offen\') AS meldungen
               FROM users u'
            . $where
            . ' ORDER BY u.created_at DESC LIMIT :limit',
            $parameter,
        );

        return array_map($this->mapAdminRow(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapAdminRow(array $row): AdminUserRow
    {
        $sperre = null;

        if (\is_string($row['banned_at'])) {
            $sperre = new BanState(
                Timestamp::parse($row['banned_at']) ?? new DateTimeImmutable($row['banned_at']),
                \is_string($row['banned_until']) ? Timestamp::parse($row['banned_until']) : null,
                \is_string($row['banned_reason']) && $row['banned_reason'] !== '' ? $row['banned_reason'] : null,
                $row['banned_by'] === null ? null : (int) $row['banned_by'],
            );
        }

        return new AdminUserRow(
            (int) $row['id'],
            (string) $row['email'],
            (string) $row['display_name'],
            Role::from((string) $row['role']),
            UserStatus::from((string) $row['status']),
            new DateTimeImmutable((string) $row['created_at']),
            \is_string($row['last_login_at']) ? new DateTimeImmutable($row['last_login_at']) : null,
            (int) $row['anzeigen'],
            (int) $row['meldungen'],
            $sperre,
        );
    }
}
