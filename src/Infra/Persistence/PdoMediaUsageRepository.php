<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use Reptilienmarkt\Domain\Content\MediaUsageContext;
use Reptilienmarkt\Domain\Content\MediaUsageRepository;

final readonly class PdoMediaUsageRepository implements MediaUsageRepository
{
    public function __construct(private Database $database) {}

    public function replaceFor(MediaUsageContext $context, int $contextId, array $mediaIds): void
    {
        $this->database->transaction(static function (Database $database) use ($context, $contextId, $mediaIds): void {
            $database->execute(
                'DELETE FROM media_usages WHERE context = :context AND context_id = :id',
                ['context' => $context->value, 'id' => $contextId],
            );

            foreach (array_unique($mediaIds) as $mediaId) {
                if ($mediaId <= 0) {
                    continue;
                }

                // Ein Medium, das es nicht mehr gibt, wird uebergangen statt
                // das Speichern scheitern zu lassen: Der Fremdschluessel wiese
                // es ab, und ein Redakteur bekaeme fuer eine alte Nummer im
                // Formular eine Datenbankfehlermeldung.
                $database->execute(
                    'INSERT OR IGNORE INTO media_usages (media_id, context, context_id)
                     SELECT id, :context, :cid FROM media WHERE id = :media',
                    ['context' => $context->value, 'cid' => $contextId, 'media' => $mediaId],
                );
            }
        });
    }

    public function forMedia(int $mediaId): array
    {
        $usages = [];

        // Der Titel kommt aus content_entries, weil sowohl Bloecke als auch das
        // Vorschaubild an einem Eintrag haengen. Menueeintraege haben keinen
        // Pfad — dort bleibt er leer.
        foreach ($this->database->select(
            'SELECT u.context, u.context_id,
                    COALESCE(e.title, \'\') AS titel,
                    COALESCE(e.path, \'\')  AS pfad
               FROM media_usages u
               LEFT JOIN content_entries e ON e.id = u.context_id AND u.context <> :menu
              WHERE u.media_id = :media
              ORDER BY u.context, u.context_id',
            ['media' => $mediaId, 'menu' => MediaUsageContext::Menu->value],
        ) as $row) {
            $usages[] = [
                'context' => MediaUsageContext::from((string) $row['context']),
                'context_id' => (int) $row['context_id'],
                'titel' => (string) $row['titel'],
                'pfad' => (string) $row['pfad'],
            ];
        }

        return $usages;
    }

    public function countsFor(array $mediaIds): array
    {
        if ($mediaIds === []) {
            return [];
        }

        $placeholders = [];
        $parameters = [];

        foreach (array_values(array_unique($mediaIds)) as $index => $id) {
            $name = 'id' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $id;
        }

        $counts = [];
        foreach ($this->database->select(
            'SELECT media_id, COUNT(*) AS anzahl FROM media_usages
              WHERE media_id IN (' . implode(', ', $placeholders) . ')
              GROUP BY media_id',
            $parameters,
        ) as $row) {
            $counts[(int) $row['media_id']] = (int) $row['anzahl'];
        }

        return $counts;
    }

    public function isUsed(int $mediaId): bool
    {
        return $this->database->scalar(
            'SELECT 1 FROM media_usages WHERE media_id = :media LIMIT 1',
            ['media' => $mediaId],
        ) !== null;
    }
}
