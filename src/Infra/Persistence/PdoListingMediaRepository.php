<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use Reptilienmarkt\Domain\Listing\ListingMediaItem;
use Reptilienmarkt\Domain\Listing\ListingMediaRepository;

final readonly class PdoListingMediaRepository implements ListingMediaRepository
{
    private const string COLUMNS = 'id, listing_id, media_type, path, sort_order, is_primary, width, height, '
        . 'byte_size, variant_widths';

    public function __construct(private Database $database) {}

    public function forListing(int $listingId): array
    {
        $rows = $this->database->select(
            'SELECT ' . self::COLUMNS . ' FROM listing_media WHERE listing_id = :id ORDER BY is_primary DESC, sort_order, id',
            ['id' => $listingId],
        );

        return array_map(fn(array $row): ListingMediaItem => $this->map($row), $rows);
    }

    public function find(int $mediaId): ?ListingMediaItem
    {
        $row = $this->database->selectOne('SELECT ' . self::COLUMNS . ' FROM listing_media WHERE id = :id', ['id' => $mediaId]);

        return $row === null ? null : $this->map($row);
    }

    public function add(ListingMediaItem $item): int
    {
        $this->database->execute(
            'INSERT INTO listing_media (listing_id, media_type, path, sort_order, is_primary, width, height, byte_size,
                                        variant_widths, created_at)
             VALUES (:listing_id, :type, :path, :sort, :primary, :width, :height, :bytes, :breiten, :now)',
            [
                'listing_id' => $item->listingId,
                'type' => $item->mediaType,
                'path' => $item->path,
                'sort' => $item->sortOrder,
                'primary' => $item->isPrimary ? 1 : 0,
                'width' => $item->width,
                'height' => $item->height,
                'bytes' => $item->byteSize,
                'breiten' => $item->variantWidths,
                'now' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
        );

        return $this->database->lastInsertId();
    }

    public function delete(int $mediaId): void
    {
        $this->database->execute('DELETE FROM listing_media WHERE id = :id', ['id' => $mediaId]);
    }

    public function countImages(int $listingId): int
    {
        $value = $this->database->scalar(
            "SELECT COUNT(*) FROM listing_media WHERE listing_id = :id AND media_type = 'bild'",
            ['id' => $listingId],
        );

        return (int) (is_numeric($value) ? $value : 0);
    }

    public function hasVideo(int $listingId): bool
    {
        $value = $this->database->scalar(
            "SELECT COUNT(*) FROM listing_media WHERE listing_id = :id AND media_type = 'video'",
            ['id' => $listingId],
        );

        return (int) (is_numeric($value) ? $value : 0) > 0;
    }

    public function setPrimary(int $listingId, int $mediaId): void
    {
        // Erst abwaehlen, dann setzen: Der Teilindex uq_listing_media_primary
        // laesst nur ein Titelbild je Anzeige zu.
        $this->database->transaction(static function (Database $database) use ($listingId, $mediaId): void {
            $database->execute(
                'UPDATE listing_media SET is_primary = 0 WHERE listing_id = :listing_id AND is_primary = 1',
                ['listing_id' => $listingId],
            );

            $database->execute(
                'UPDATE listing_media SET is_primary = 1 WHERE id = :id AND listing_id = :listing_id',
                ['id' => $mediaId, 'listing_id' => $listingId],
            );
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): ListingMediaItem
    {
        return new ListingMediaItem(
            (int) $row['id'],
            (int) $row['listing_id'],
            (string) $row['media_type'],
            (string) $row['path'],
            (int) $row['sort_order'],
            (bool) $row['is_primary'],
            $row['width'] === null ? null : (int) $row['width'],
            $row['height'] === null ? null : (int) $row['height'],
            $row['byte_size'] === null ? null : (int) $row['byte_size'],
            ($row['variant_widths'] ?? null) === null ? null : (string) $row['variant_widths'],
        );
    }
}
