<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use Reptilienmarkt\Domain\Species\BnatschgStatus;
use Reptilienmarkt\Domain\Species\CareLevel;
use Reptilienmarkt\Domain\Species\CitesAppendix;
use Reptilienmarkt\Domain\Species\EuAnnex;
use Reptilienmarkt\Domain\Species\Species;
use Reptilienmarkt\Domain\Species\SpeciesRepository;

final readonly class PdoSpeciesRepository implements SpeciesRepository
{
    private const string COLUMNS = 'id, scientific_name, common_name_de, family, order_taxon, cites_appendix, eu_annex, '
        . 'bnatschg_status, meldepflicht, doku_pflicht, gefahrtier, care_level, adult_size_cm, lifespan_years, '
        . 'min_abgabe_alter_wochen, min_abgabe_gewicht_g, slug';

    public function __construct(private Database $database) {}

    public function findById(int $id): ?Species
    {
        $row = $this->database->selectOne('SELECT ' . self::COLUMNS . ' FROM species WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->map($row);
    }

    public function findBySlug(string $slug): ?Species
    {
        $row = $this->database->selectOne('SELECT ' . self::COLUMNS . ' FROM species WHERE slug = :slug', ['slug' => $slug]);

        return $row === null ? null : $this->map($row);
    }

    public function findByScientificName(string $scientificName): ?Species
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM species WHERE scientific_name = :name',
            ['name' => $scientificName],
        );

        return $row === null ? null : $this->map($row);
    }

    public function all(): array
    {
        $rows = $this->database->select('SELECT ' . self::COLUMNS . ' FROM species ORDER BY common_name_de');

        return array_map(fn(array $row): Species => $this->map($row), $rows);
    }

    public function search(string $term, int $limit = 20): array
    {
        $pattern = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($term)) . '%';

        $rows = $this->database->select(
            'SELECT ' . self::COLUMNS . ' FROM species
             WHERE scientific_name LIKE :pattern ESCAPE \'\\\' OR common_name_de LIKE :pattern ESCAPE \'\\\'
             ORDER BY CASE WHEN common_name_de LIKE :prefix ESCAPE \'\\\' THEN 0 ELSE 1 END, common_name_de
             LIMIT :limit',
            ['pattern' => $pattern, 'prefix' => ltrim($pattern, '%'), 'limit' => $limit],
        );

        return array_map(fn(array $row): Species => $this->map($row), $rows);
    }

    public function save(Species $species): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $parameters = [
            'scientific_name' => $species->scientificName,
            'common_name_de' => $species->commonNameDe,
            'family' => $species->family,
            'order_taxon' => $species->orderTaxon,
            'cites_appendix' => $species->citesAppendix?->value,
            'eu_annex' => $species->euAnnex?->value,
            'bnatschg_status' => $species->bnatschgStatus->value,
            'meldepflicht' => $species->meldepflicht ? 1 : 0,
            'doku_pflicht' => $species->dokuPflicht ? 1 : 0,
            'gefahrtier' => $species->gefahrtier ? 1 : 0,
            'care_level' => $species->careLevel?->value,
            'adult_size_cm' => $species->adultSizeCm,
            'lifespan_years' => $species->lifespanYears,
            'min_abgabe_alter_wochen' => $species->minAbgabeAlterWochen,
            'min_abgabe_gewicht_g' => $species->minAbgabeGewichtG,
            'slug' => $species->slug,
            'updated_at' => $now,
        ];

        // Upsert ueber den wissenschaftlichen Namen: der Artenstamm wird per
        // Import gepflegt und darf beim erneuten Einspielen keine Dubletten erzeugen.
        $this->database->execute(
            'INSERT INTO species (scientific_name, common_name_de, family, order_taxon, cites_appendix, eu_annex,
                bnatschg_status, meldepflicht, doku_pflicht, gefahrtier, care_level, adult_size_cm, lifespan_years,
                min_abgabe_alter_wochen, min_abgabe_gewicht_g, slug, created_at, updated_at)
             VALUES (:scientific_name, :common_name_de, :family, :order_taxon, :cites_appendix, :eu_annex,
                :bnatschg_status, :meldepflicht, :doku_pflicht, :gefahrtier, :care_level, :adult_size_cm, :lifespan_years,
                :min_abgabe_alter_wochen, :min_abgabe_gewicht_g, :slug, :updated_at, :updated_at)
             ON CONFLICT(scientific_name) DO UPDATE SET
                common_name_de = excluded.common_name_de,
                family = excluded.family,
                order_taxon = excluded.order_taxon,
                cites_appendix = excluded.cites_appendix,
                eu_annex = excluded.eu_annex,
                bnatschg_status = excluded.bnatschg_status,
                meldepflicht = excluded.meldepflicht,
                doku_pflicht = excluded.doku_pflicht,
                gefahrtier = excluded.gefahrtier,
                care_level = excluded.care_level,
                adult_size_cm = excluded.adult_size_cm,
                lifespan_years = excluded.lifespan_years,
                min_abgabe_alter_wochen = excluded.min_abgabe_alter_wochen,
                min_abgabe_gewicht_g = excluded.min_abgabe_gewicht_g,
                slug = excluded.slug,
                updated_at = excluded.updated_at',
            $parameters,
        );

        $id = $this->database->scalar(
            'SELECT id FROM species WHERE scientific_name = :name',
            ['name' => $species->scientificName],
        );

        return (int) (is_numeric($id) ? $id : 0);
    }

    public function deleteById(int $id): void
    {
        $this->database->execute('DELETE FROM species WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Species
    {
        $cites = $row['cites_appendix'];
        $annex = $row['eu_annex'];
        $care = $row['care_level'];

        return new Species(
            (int) $row['id'],
            (string) $row['scientific_name'],
            (string) $row['common_name_de'],
            (string) $row['slug'],
            $row['family'] === null ? null : (string) $row['family'],
            $row['order_taxon'] === null ? null : (string) $row['order_taxon'],
            \is_string($cites) ? CitesAppendix::from($cites) : null,
            \is_string($annex) ? EuAnnex::from($annex) : null,
            BnatschgStatus::from((string) $row['bnatschg_status']),
            (bool) $row['meldepflicht'],
            (bool) $row['doku_pflicht'],
            (bool) $row['gefahrtier'],
            \is_string($care) ? CareLevel::from($care) : null,
            $row['adult_size_cm'] === null ? null : (int) $row['adult_size_cm'],
            $row['lifespan_years'] === null ? null : (int) $row['lifespan_years'],
            $row['min_abgabe_alter_wochen'] === null ? null : (int) $row['min_abgabe_alter_wochen'],
            $row['min_abgabe_gewicht_g'] === null ? null : (int) $row['min_abgabe_gewicht_g'],
        );
    }
}
