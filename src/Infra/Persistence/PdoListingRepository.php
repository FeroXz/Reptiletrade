<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Listing\AdminListingRow;
use Reptilienmarkt\Domain\Listing\CbStatus;
use Reptilienmarkt\Domain\Listing\Handover;
use Reptilienmarkt\Domain\Listing\Listing;
use Reptilienmarkt\Domain\Listing\ListingRepository;
use Reptilienmarkt\Domain\Listing\ListingStatus;
use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Domain\Listing\MorphSelection;
use Reptilienmarkt\Domain\Listing\PauseActor;
use Reptilienmarkt\Domain\Listing\PauseState;
use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Listing\Zygosity;
use Reptilienmarkt\Domain\Species\Inheritance;
use Reptilienmarkt\Domain\Species\Morph;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoListingRepository implements ListingRepository
{
    private const string COLUMNS = 'id, user_id, type, species_id, title, description, price_cents, currency, negotiable, '
        . 'trade_wanted, sex, hatch_date, weight_g, count_available, cb_status, status, postal_code, country, lat, lng, '
        . 'handover, legal_confirmations, expires_at';

    public function __construct(private Database $database) {}

    public function findById(int $id): ?Listing
    {
        $row = $this->database->selectOne('SELECT ' . self::COLUMNS . ' FROM listings WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->map($row);
    }

    public function findLatestDraft(int $userId): ?Listing
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . " FROM listings
              WHERE user_id = :user_id AND status = 'entwurf'
              ORDER BY updated_at DESC LIMIT 1",
            ['user_id' => $userId],
        );

        return $row === null ? null : $this->map($row);
    }

    public function create(Listing $listing): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $this->database->execute(
            'INSERT INTO listings (user_id, type, species_id, title, description, price_cents, currency, negotiable,
                                   trade_wanted, sex, hatch_date, weight_g, count_available, cb_status, status,
                                   postal_code, country, lat, lng, handover, legal_confirmations,
                                   created_at, updated_at, expires_at)
             VALUES (:user_id, :type, :species_id, :title, :description, :price, :currency, :negotiable,
                     :trade_wanted, :sex, :hatch_date, :weight, :count, :cb, :status,
                     :postal_code, :country, :lat, :lng, :handover, :legal,
                     :now, :now, :expires)',
            $this->parameters($listing) + ['now' => $now],
        );

        return $this->database->lastInsertId();
    }

    public function save(Listing $listing): void
    {
        if ($listing->id === null) {
            return;
        }

        $this->database->execute(
            'UPDATE listings SET
                type = :type, species_id = :species_id, title = :title, description = :description,
                price_cents = :price, currency = :currency, negotiable = :negotiable, trade_wanted = :trade_wanted,
                sex = :sex, hatch_date = :hatch_date, weight_g = :weight, count_available = :count,
                cb_status = :cb, status = :status, postal_code = :postal_code, country = :country,
                lat = :lat, lng = :lng, handover = :handover, legal_confirmations = :legal,
                expires_at = :expires, updated_at = :now
              WHERE id = :id AND user_id = :user_id',
            $this->parameters($listing) + ['now' => gmdate('Y-m-d\TH:i:s\Z'), 'id' => $listing->id],
        );
    }

    public function updateStatus(int $listingId, ListingStatus $status): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        // bumped_at nur beim Wechsel auf "aktiv" setzen: Sonst wandert eine
        // Anzeige durch jede Statusaenderung nach oben.
        // Die Pausenangaben haengen am Zustand und werden mit ihm abgeraeumt.
        // Sonst bleibt nach einer Freigabe durch die Moderation, nach dem
        // Ablauf oder nach dem Archivieren ein "pausiert von der Verwaltung"
        // an einer laufenden Anzeige kleben — und die Verwaltungsliste zeigt
        // sie weiter als angehalten.
        $this->database->execute(
            'UPDATE listings
                SET status = :status,
                    updated_at = :now,
                    bumped_at = CASE WHEN :status = \'aktiv\' AND status <> \'aktiv\' THEN :now ELSE bumped_at END,
                    paused_by = CASE WHEN :status = \'pausiert\' THEN paused_by ELSE NULL END,
                    paused_at = CASE WHEN :status = \'pausiert\' THEN paused_at ELSE NULL END,
                    paused_reason = CASE WHEN :status = \'pausiert\' THEN paused_reason ELSE NULL END,
                    status_before_pause = CASE WHEN :status = \'pausiert\' THEN status_before_pause ELSE NULL END
              WHERE id = :id',
            ['status' => $status->value, 'now' => $now, 'id' => $listingId],
        );
    }

    public function delete(int $listingId): void
    {
        $this->database->execute('DELETE FROM listings WHERE id = :id', ['id' => $listingId]);
    }

    public function morphSelections(int $listingId): array
    {
        $rows = $this->database->select(
            'SELECT m.id, m.species_id, m.name, m.aliases, m.inheritance, m.allele_group, m.is_lethal_combo,
                    m.description, lm.zygosity
               FROM listing_morphs lm
               JOIN morphs m ON m.id = lm.morph_id
              WHERE lm.listing_id = :listing_id
              ORDER BY m.name',
            ['listing_id' => $listingId],
        );

        $selections = [];
        foreach ($rows as $row) {
            /** @var mixed $decoded */
            $decoded = json_decode((string) $row['aliases'], true);
            $aliases = [];
            if (\is_array($decoded)) {
                foreach ($decoded as $alias) {
                    if (\is_string($alias)) {
                        $aliases[] = $alias;
                    }
                }
            }

            $selections[] = new MorphSelection(
                new Morph(
                    (int) $row['id'],
                    (int) $row['species_id'],
                    (string) $row['name'],
                    Inheritance::from((string) $row['inheritance']),
                    $aliases,
                    $row['allele_group'] === null ? null : (string) $row['allele_group'],
                    (bool) $row['is_lethal_combo'],
                    $row['description'] === null ? null : (string) $row['description'],
                ),
                Zygosity::from((string) $row['zygosity']),
            );
        }

        return $selections;
    }

    public function replaceMorphs(int $listingId, array $selection): void
    {
        $this->database->transaction(function (Database $database) use ($listingId, $selection): void {
            $database->execute('DELETE FROM listing_morphs WHERE listing_id = :listing_id', ['listing_id' => $listingId]);

            $statement = $database->pdo()->prepare(
                'INSERT INTO listing_morphs (listing_id, morph_id, zygosity) VALUES (:listing_id, :morph_id, :zygosity)',
            );

            foreach ($selection as $morphId => $zygosity) {
                $statement->execute([
                    'listing_id' => $listingId,
                    'morph_id' => $morphId,
                    'zygosity' => $zygosity->value,
                ]);
            }
        });
    }

    public function forUser(int $userId, int $limit = 50): array
    {
        $rows = $this->database->select(
            'SELECT ' . self::COLUMNS . ' FROM listings WHERE user_id = :user_id ORDER BY updated_at DESC LIMIT :limit',
            ['user_id' => $userId, 'limit' => $limit],
        );

        return array_map(fn(array $row): Listing => $this->map($row), $rows);
    }

    public function activeForUser(int $userId, int $limit = 12): array
    {
        // Dieselbe Sortierung wie in der Trefferliste, damit das Profil nicht
        // eine andere Reihenfolge zeigt als die Suche.
        $rows = $this->database->select(
            'SELECT ' . self::COLUMNS . " FROM listings
              WHERE user_id = :user_id AND status IN ('aktiv','reserviert')
              ORDER BY is_featured DESC, bumped_at DESC, id DESC
              LIMIT :limit",
            ['user_id' => $userId, 'limit' => $limit],
        );

        return array_map(fn(array $row): Listing => $this->map($row), $rows);
    }

    public function inStatus(ListingStatus $status, int $limit = 25): array
    {
        $rows = $this->database->select(
            'SELECT ' . self::COLUMNS . ' FROM listings WHERE status = :status
              ORDER BY updated_at ASC LIMIT :limit',
            ['status' => $status->value, 'limit' => $limit],
        );

        return array_map(fn(array $row): Listing => $this->map($row), $rows);
    }

    public function setFeatured(int $listingId, bool $featured): void
    {
        $this->database->execute(
            'UPDATE listings SET is_featured = :featured, updated_at = :now WHERE id = :id',
            ['featured' => $featured ? 1 : 0, 'now' => Timestamp::now(), 'id' => $listingId],
        );
    }

    public function isFeatured(int $listingId): bool
    {
        return (bool) $this->database->scalar(
            'SELECT is_featured FROM listings WHERE id = :id',
            ['id' => $listingId],
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    private function parameters(Listing $listing): array
    {
        return [
            'user_id' => $listing->userId,
            'type' => $listing->type->value,
            'species_id' => $listing->speciesId,
            'title' => $listing->title,
            'description' => $listing->description,
            'price' => $listing->priceCents,
            'currency' => $listing->currency,
            'negotiable' => $listing->negotiable ? 1 : 0,
            'trade_wanted' => $listing->tradeWanted,
            'sex' => $listing->sex->value,
            'hatch_date' => $listing->hatchDate?->format('Y-m-d'),
            'weight' => $listing->weightG,
            'count' => $listing->countAvailable,
            'cb' => $listing->cbStatus->value,
            'status' => $listing->status->value,
            'postal_code' => $listing->postalCode,
            'country' => $listing->country?->value,
            'lat' => $listing->latitude,
            'lng' => $listing->longitude,
            'handover' => $listing->handover->value,
            'legal' => json_encode($listing->legalConfirmations, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
            'expires' => Timestamp::utcOrNull($listing->expiresAt),
        ];
    }

    // ------------------------------------------------------------- Pause

    public function pause(int $listingId, PauseActor $actor, ?string $reason, ListingStatus $previousStatus): void
    {
        $this->database->execute(
            <<<'SQL'
                UPDATE listings
                   SET status = 'pausiert',
                       paused_by = :actor,
                       paused_at = :now,
                       paused_reason = :reason,
                       status_before_pause = :previous,
                       updated_at = :now
                 WHERE id = :id
                SQL,
            [
                'actor' => $actor->value,
                'now' => Timestamp::now(),
                'reason' => $reason,
                'previous' => $previousStatus->value,
                'id' => $listingId,
            ],
        );
    }

    public function resume(int $listingId, ListingStatus $status): void
    {
        // bumped_at bleibt stehen: Eine Pause soll die Anzeige nicht nach oben
        // spuelen. Wer oben stehen will, bucht eine Top-Platzierung.
        $this->database->execute(
            <<<'SQL'
                UPDATE listings
                   SET status = :status,
                       paused_by = NULL,
                       paused_at = NULL,
                       paused_reason = NULL,
                       status_before_pause = NULL,
                       updated_at = :now
                 WHERE id = :id
                SQL,
            ['status' => $status->value, 'now' => Timestamp::now(), 'id' => $listingId],
        );
    }

    public function pauseState(int $listingId): ?PauseState
    {
        $row = $this->database->selectOne(
            'SELECT paused_by, paused_at, paused_reason, status_before_pause FROM listings WHERE id = :id',
            ['id' => $listingId],
        );

        if ($row === null || !\is_string($row['paused_by']) || !\is_string($row['paused_at'])) {
            return null;
        }

        $actor = PauseActor::tryFrom($row['paused_by']);

        if ($actor === null) {
            return null;
        }

        $vorher = \is_string($row['status_before_pause'])
            ? ListingStatus::tryFrom($row['status_before_pause'])
            : null;

        return new PauseState(
            $actor,
            Timestamp::parse($row['paused_at']) ?? new DateTimeImmutable($row['paused_at']),
            \is_string($row['paused_reason']) && $row['paused_reason'] !== '' ? $row['paused_reason'] : null,
            $vorher ?? ListingStatus::Aktiv,
        );
    }

    // -------------------------------------------------------- Bearbeitungen

    public function recordEdit(int $listingId, int $editorId, array $changed): void
    {
        $now = Timestamp::now();

        $this->database->execute(
            'INSERT INTO listing_edits (listing_id, edited_by, changed_json, edited_at)
             VALUES (:listing, :editor, :changed, :now)',
            [
                'listing' => $listingId,
                'editor' => $editorId > 0 ? $editorId : null,
                'changed' => json_encode($changed, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
                'now' => $now,
            ],
        );

        // Der Zaehler wird in SQL erhoeht, nicht aus einem gelesenen Wert
        // gesetzt: Zwei gleichzeitige Bearbeitungen wuerden sich sonst
        // gegenseitig ueberschreiben.
        $this->database->execute(
            'UPDATE listings SET edit_count = edit_count + 1, edited_at = :now WHERE id = :id',
            ['now' => $now, 'id' => $listingId],
        );
    }

    public function editCount(int $listingId): int
    {
        return $this->count('SELECT edit_count FROM listings WHERE id = :id', $listingId);
    }

    public function conversationCount(int $listingId): int
    {
        return $this->count('SELECT COUNT(*) FROM conversations WHERE listing_id = :id', $listingId);
    }

    public function reviewCount(int $listingId): int
    {
        return $this->count('SELECT COUNT(*) FROM reviews WHERE listing_id = :id', $listingId);
    }

    // ------------------------------------------------------------ Verwaltung

    public function forAdmin(array $filters = [], int $limit = 100): array
    {
        $bedingungen = [];
        $parameter = ['limit' => $limit];

        if (isset($filters['status']) && ListingStatus::tryFrom($filters['status']) !== null) {
            $bedingungen[] = 'l.status = :status';
            $parameter['status'] = $filters['status'];
        }

        if (($filters['nur_pausiert'] ?? false) === true) {
            $bedingungen[] = 'l.paused_at IS NOT NULL';
        }

        if (isset($filters['suche']) && trim($filters['suche']) !== '') {
            // Bewusst LIKE und nicht der Volltextindex: Die Verwaltung sucht
            // auch in Anzeigen, die gar nicht im Index stehen — pausierte,
            // gesperrte, Entwuerfe.
            $bedingungen[] = '(l.title LIKE :suche OR u.display_name LIKE :suche OR u.email LIKE :suche)';
            $parameter['suche'] = '%' . trim($filters['suche']) . '%';
        }

        $where = $bedingungen === [] ? '' : ' WHERE ' . implode(' AND ', $bedingungen);

        $rows = $this->database->select(
            'SELECT l.id, l.title, l.status, l.user_id, l.created_at, l.paused_by, l.paused_at, l.paused_reason,
                    l.edit_count, u.display_name, s.common_name_de,
                    (SELECT COUNT(*) FROM reports r
                      WHERE r.target_type = \'listing\' AND r.target_id = l.id AND r.status = \'offen\') AS meldungen
               FROM listings l
               JOIN users u ON u.id = l.user_id
               JOIN species s ON s.id = l.species_id'
            . $where
            . ' ORDER BY l.created_at DESC LIMIT :limit',
            $parameter,
        );

        return array_map($this->mapAdminRow(...), $rows);
    }

    public function countsByStatus(): array
    {
        $counts = [];

        foreach ($this->database->select('SELECT status, COUNT(*) AS anzahl FROM listings GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['anzahl'];
        }

        return $counts;
    }

    public function recordView(int $listingId): void
    {
        $tag = gmdate('Y-m-d');

        // Beide Zaehler in einem Schritt: view_count traegt die Gesamtzahl an
        // der Anzeige, listing_views den Verlauf. Der Upsert kommt ohne
        // vorheriges SELECT aus — zwei gleichzeitige Aufrufe zaehlen sonst
        // denselben Stand hoch und einer geht verloren.
        $this->database->execute(
            'INSERT INTO listing_views (listing_id, day, views) VALUES (:id, :tag, 1)
             ON CONFLICT(listing_id, day) DO UPDATE SET views = views + 1',
            ['id' => $listingId, 'tag' => $tag],
        );

        $this->database->execute(
            'UPDATE listings SET view_count = view_count + 1 WHERE id = :id',
            ['id' => $listingId],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapAdminRow(array $row): AdminListingRow
    {
        $pausedBy = \is_string($row['paused_by']) ? PauseActor::tryFrom($row['paused_by']) : null;
        $pausedAt = \is_string($row['paused_at']) ? Timestamp::parse($row['paused_at']) : null;

        return new AdminListingRow(
            (int) $row['id'],
            (string) $row['title'],
            ListingStatus::from((string) $row['status']),
            (int) $row['user_id'],
            (string) $row['display_name'],
            (string) $row['common_name_de'],
            new DateTimeImmutable((string) $row['created_at']),
            $pausedBy,
            $pausedAt,
            \is_string($row['paused_reason']) && $row['paused_reason'] !== '' ? $row['paused_reason'] : null,
            (int) $row['edit_count'],
            (int) $row['meldungen'],
        );
    }

    private function count(string $sql, int $listingId): int
    {
        $value = $this->database->scalar($sql, ['id' => $listingId]);

        return (int) (is_numeric($value) ? $value : 0);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Listing
    {
        /** @var mixed $confirmations */
        $confirmations = json_decode((string) ($row['legal_confirmations'] ?? '{}'), true);

        $clean = [];
        if (\is_array($confirmations)) {
            foreach ($confirmations as $key => $value) {
                if (\is_string($key) && (\is_scalar($value) || $value === null)) {
                    $clean[$key] = $value;
                }
            }
        }

        $country = $row['country'];
        $hatchDate = $row['hatch_date'];
        $expires = $row['expires_at'];

        return new Listing(
            (int) $row['id'],
            (int) $row['user_id'],
            ListingType::from((string) $row['type']),
            (int) $row['species_id'],
            (string) $row['title'],
            (string) $row['description'],
            $row['price_cents'] === null ? null : (int) $row['price_cents'],
            (string) $row['currency'],
            (bool) $row['negotiable'],
            $row['trade_wanted'] === null ? null : (string) $row['trade_wanted'],
            Sex::from((string) $row['sex']),
            \is_string($hatchDate) && $hatchDate !== '' ? new DateTimeImmutable($hatchDate) : null,
            $row['weight_g'] === null ? null : (int) $row['weight_g'],
            (int) $row['count_available'],
            CbStatus::from((string) $row['cb_status']),
            ListingStatus::from((string) $row['status']),
            $row['postal_code'] === null ? null : (string) $row['postal_code'],
            \is_string($country) ? Country::tryFrom($country) : null,
            $row['lat'] === null ? null : (float) $row['lat'],
            $row['lng'] === null ? null : (float) $row['lng'],
            Handover::from((string) $row['handover']),
            $clean,
            \is_string($expires) && $expires !== '' ? new DateTimeImmutable($expires) : null,
        );
    }
}
