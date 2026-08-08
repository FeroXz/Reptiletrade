<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use Reptilienmarkt\Domain\Content\Media;
use Reptilienmarkt\Domain\Content\MediaRepository;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoMediaRepository implements MediaRepository
{
    private const string COLUMNS = 'id, sha256, path, original_filename, mime, width, height,
             byte_size, alt_text, caption, uploaded_by, created_at';

    public function __construct(private Database $database) {}

    public function findById(int $id): ?Media
    {
        $row = $this->database->selectOne('SELECT ' . self::COLUMNS . ' FROM media WHERE id = :id', ['id' => $id]);

        return $row === null ? null : self::map($row);
    }

    public function findByHash(string $sha256): ?Media
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM media WHERE sha256 = :hash',
            ['hash' => $sha256],
        );

        return $row === null ? null : self::map($row);
    }

    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        // Platzhalter statt Einbau der Werte: Auch bei Zahlen aus der eigenen
        // Datenbank bleibt es dabei, dass kein Wert je in den Abfragetext
        // wandert.
        $placeholders = [];
        $parameters = [];

        foreach (array_values(array_unique($ids)) as $index => $id) {
            $name = 'id' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $id;
        }

        $media = [];
        foreach ($this->database->select(
            'SELECT ' . self::COLUMNS . ' FROM media WHERE id IN (' . implode(', ', $placeholders) . ')',
            $parameters,
        ) as $row) {
            $item = self::map($row);
            $media[$item->id ?? 0] = $item;
        }

        return $media;
    }

    public function save(Media $media): int
    {
        if ($media->id !== null) {
            $this->database->execute(
                'UPDATE media SET alt_text = :alt, caption = :caption WHERE id = :id',
                ['alt' => $media->altText, 'caption' => $media->caption, 'id' => $media->id],
            );

            return $media->id;
        }

        $this->database->execute(
            'INSERT INTO media (sha256, path, original_filename, mime, width, height, byte_size,
                                alt_text, caption, uploaded_by, created_at)
             VALUES (:hash, :path, :filename, :mime, :width, :height, :size, :alt, :caption, :by, :now)',
            [
                'hash' => $media->sha256,
                'path' => $media->path,
                'filename' => $media->originalFilename,
                'mime' => $media->mime,
                'width' => $media->width,
                'height' => $media->height,
                'size' => $media->byteSize,
                'alt' => $media->altText,
                'caption' => $media->caption,
                'by' => $media->uploadedBy,
                'now' => Timestamp::utcOrNull($media->createdAt) ?? Timestamp::now(),
            ],
        );

        return $this->database->lastInsertId();
    }

    public function updateText(int $id, string $altText, string $caption): void
    {
        $this->database->execute(
            'UPDATE media SET alt_text = :alt, caption = :caption WHERE id = :id',
            ['alt' => $altText, 'caption' => $caption, 'id' => $id],
        );
    }

    public function delete(int $id): void
    {
        $this->database->execute('DELETE FROM media WHERE id = :id', ['id' => $id]);
    }

    public function latest(int $limit = 60, int $offset = 0, ?string $query = null): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM media';
        $parameters = ['limit' => $limit, 'offset' => $offset];

        if ($query !== null && trim($query) !== '') {
            $sql .= ' WHERE original_filename LIKE :q OR alt_text LIKE :q OR caption LIKE :q';
            $parameters['q'] = '%' . trim($query) . '%';
        }

        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset';

        return array_map(static fn(array $row): Media => self::map($row), $this->database->select($sql, $parameters));
    }

    public function count(?string $query = null): int
    {
        if ($query === null || trim($query) === '') {
            return (int) $this->database->scalar('SELECT COUNT(*) FROM media');
        }

        return (int) $this->database->scalar(
            'SELECT COUNT(*) FROM media WHERE original_filename LIKE :q OR alt_text LIKE :q OR caption LIKE :q',
            ['q' => '%' . trim($query) . '%'],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function map(array $row): Media
    {
        return new Media(
            id: (int) $row['id'],
            sha256: (string) $row['sha256'],
            path: (string) $row['path'],
            width: (int) $row['width'],
            height: (int) $row['height'],
            byteSize: (int) $row['byte_size'],
            originalFilename: (string) $row['original_filename'],
            mime: (string) $row['mime'],
            altText: (string) $row['alt_text'],
            caption: (string) $row['caption'],
            uploadedBy: $row['uploaded_by'] === null ? null : (int) $row['uploaded_by'],
            createdAt: Timestamp::parse((string) $row['created_at']),
        );
    }
}
