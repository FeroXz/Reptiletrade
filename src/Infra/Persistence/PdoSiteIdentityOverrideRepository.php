<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Site\SiteIdentityOverrideRepository;
use Reptilienmarkt\Domain\Site\TextOverride;
use Reptilienmarkt\Support\Timestamp;

final class PdoSiteIdentityOverrideRepository implements SiteIdentityOverrideRepository
{
    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(private readonly Database $database) {}

    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $werte = [];

        foreach ($this->database->select('SELECT field_key, value FROM site_identity_overrides') as $row) {
            $werte[(string) $row['field_key']] = (string) $row['value'];
        }

        return $this->cache = $werte;
    }

    public function withMeta(): array
    {
        $eintraege = [];

        foreach ($this->database->select(
            'SELECT field_key, value, updated_at FROM site_identity_overrides',
        ) as $row) {
            $eintraege[(string) $row['field_key']] = new TextOverride(
                'de-DE',
                (string) $row['field_key'],
                (string) $row['value'],
                Timestamp::parse((string) $row['updated_at']) ?? new DateTimeImmutable(),
            );
        }

        return $eintraege;
    }

    public function set(string $fieldKey, string $value, DateTimeImmutable $moment, ?int $userId): void
    {
        $this->database->execute(
            'INSERT INTO site_identity_overrides (field_key, value, updated_at, updated_by)
             VALUES (:key, :value, :now, :by)
             ON CONFLICT (field_key) DO UPDATE SET value = :value, updated_at = :now, updated_by = :by',
            ['key' => $fieldKey, 'value' => $value, 'now' => Timestamp::utc($moment), 'by' => $userId],
        );

        // Der Zwischenspeicher gilt nur fuer die Dauer einer Anfrage — nach
        // einer Aenderung waere er die alte Wahrheit.
        $this->cache = null;
    }

    public function remove(string $fieldKey): void
    {
        $this->database->execute('DELETE FROM site_identity_overrides WHERE field_key = :key', ['key' => $fieldKey]);

        $this->cache = null;
    }

    public function count(): int
    {
        return (int) $this->database->scalar('SELECT COUNT(*) FROM site_identity_overrides');
    }
}
