<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Admin;

use Reptilienmarkt\Domain\Audit\AuditActorType;
use Reptilienmarkt\Domain\Audit\AuditEntry;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Domain\Species\BnatschgStatus;
use Reptilienmarkt\Domain\Species\CareLevel;
use Reptilienmarkt\Domain\Species\CitesAppendix;
use Reptilienmarkt\Domain\Species\EuAnnex;
use Reptilienmarkt\Domain\Species\Inheritance;
use Reptilienmarkt\Domain\Species\Morph;
use Reptilienmarkt\Domain\Species\MorphRepository;
use Reptilienmarkt\Domain\Species\Species;
use Reptilienmarkt\Domain\Species\SpeciesRepository;
use Reptilienmarkt\Support\Slugger;

/**
 * Import und Export von Artenstamm und Merkmalskatalog.
 *
 * Der Import ist bewusst zweistufig: Erst wird die ganze Datei geprueft, dann
 * geschrieben. Ein Import, der in der Mitte abbricht, hinterlaesst sonst einen
 * halben Artenstamm — und beim Schutzstatus ist ein halber Datensatz
 * gefaehrlicher als gar keiner.
 *
 * Geschrieben wird per Upsert ueber den wissenschaftlichen Namen: Er ist der
 * fachliche Schluessel, die Kennung ist nur eine Zeilennummer.
 */
final readonly class SpeciesCatalogService
{
    /** @var list<string> */
    public const array SPECIES_COLUMNS = [
        'scientific_name', 'common_name_de', 'family', 'order_taxon', 'cites_appendix', 'eu_annex',
        'bnatschg_status', 'meldepflicht', 'doku_pflicht', 'gefahrtier', 'care_level', 'adult_size_cm',
        'lifespan_years', 'min_abgabe_alter_wochen', 'min_abgabe_gewicht_g', 'slug',
    ];

    /** @var list<string> */
    public const array MORPH_COLUMNS = [
        'species_scientific_name', 'name', 'aliases', 'inheritance', 'allele_group', 'is_lethal_combo', 'description',
    ];

    public function __construct(
        private SpeciesRepository $species,
        private MorphRepository $morphs,
        private AuditLog $audit,
    ) {}

    // ------------------------------------------------------------- Export

    public function exportSpeciesJson(): string
    {
        $daten = array_map($this->speciesToArray(...), $this->species->all());

        return json_encode($daten, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    public function exportSpeciesCsv(): string
    {
        return $this->toCsv(
            self::SPECIES_COLUMNS,
            array_map($this->speciesToArray(...), $this->species->all()),
        );
    }

    public function exportMorphsJson(): string
    {
        return json_encode($this->allMorphRows(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    public function exportMorphsCsv(): string
    {
        return $this->toCsv(self::MORPH_COLUMNS, $this->allMorphRows());
    }

    // ------------------------------------------------------------- Import

    /**
     * @throws CatalogException
     */
    public function importSpecies(string $content, string $format, ?int $actorId = null, bool $dryRun = false): ImportResult
    {
        $zeilen = $this->parse($content, $format, self::SPECIES_COLUMNS);
        $geprueft = [];
        $fehler = [];

        foreach ($zeilen as $nummer => $zeile) {
            try {
                $geprueft[] = $this->speciesFromRow($zeile);
            } catch (CatalogException $exception) {
                $fehler[] = \sprintf('Zeile %d: %s', $nummer + 1, $exception->getMessage());
            }
        }

        // Erst pruefen, dann schreiben — ein halber Artenstamm waere
        // gefaehrlicher als gar keiner.
        if ($fehler !== []) {
            return new ImportResult(0, 0, $fehler);
        }

        if ($dryRun) {
            return new ImportResult(\count($geprueft), 0, [], true);
        }

        $neu = 0;
        $aktualisiert = 0;

        foreach ($geprueft as $art) {
            $vorhanden = $this->species->findByScientificName($art->scientificName);

            if ($vorhanden === null) {
                $this->species->save($art);
                ++$neu;

                continue;
            }

            $this->species->save($art->withId($vorhanden->id ?? 0));
            ++$aktualisiert;
        }

        $this->audit->record(new AuditEntry(
            'catalog.species_imported',
            'species',
            null,
            ['neu' => $neu, 'aktualisiert' => $aktualisiert, 'format' => $format],
            $actorId,
            AuditActorType::Admin,
        ));

        return new ImportResult($neu, $aktualisiert, []);
    }

    /**
     * @throws CatalogException
     */
    public function importMorphs(string $content, string $format, ?int $actorId = null, bool $dryRun = false): ImportResult
    {
        $zeilen = $this->parse($content, $format, self::MORPH_COLUMNS);
        $geprueft = [];
        $fehler = [];

        foreach ($zeilen as $nummer => $zeile) {
            $wissenschaftlich = $this->string($zeile, 'species_scientific_name');
            $art = $this->species->findByScientificName($wissenschaftlich);

            if ($art === null || $art->id === null) {
                $fehler[] = \sprintf('Zeile %d: Art "%s" ist nicht im Bestand.', $nummer + 1, $wissenschaftlich);

                continue;
            }

            try {
                $geprueft[] = $this->morphFromRow($zeile, $art->id);
            } catch (CatalogException $exception) {
                $fehler[] = \sprintf('Zeile %d: %s', $nummer + 1, $exception->getMessage());
            }
        }

        if ($fehler !== []) {
            return new ImportResult(0, 0, $fehler);
        }

        if ($dryRun) {
            return new ImportResult(\count($geprueft), 0, [], true);
        }

        $neu = 0;
        $aktualisiert = 0;

        foreach ($geprueft as $morph) {
            $vorhanden = $this->morphs->findByName($morph->speciesId, $morph->name);

            if ($vorhanden === null) {
                $this->morphs->save($morph);
                ++$neu;

                continue;
            }

            $this->morphs->save($morph->withId($vorhanden->id ?? 0));
            ++$aktualisiert;
        }

        $this->audit->record(new AuditEntry(
            'catalog.morphs_imported',
            'morph',
            null,
            ['neu' => $neu, 'aktualisiert' => $aktualisiert, 'format' => $format],
            $actorId,
            AuditActorType::Admin,
        ));

        return new ImportResult($neu, $aktualisiert, []);
    }

    // -------------------------------------------------------- Hilfsmittel

    /**
     * @param list<string> $columns
     *
     * @return list<array<string, string>>
     *
     * @throws CatalogException
     */
    private function parse(string $content, string $format, array $columns): array
    {
        return match ($format) {
            'json' => $this->parseJson($content),
            'csv' => $this->parseCsv($content, $columns),
            default => throw new CatalogException('Unterstützt werden JSON und CSV.'),
        };
    }

    /**
     * @return list<array<string, string>>
     *
     * @throws CatalogException
     */
    private function parseJson(string $content): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($content, true);

        if (!\is_array($decoded)) {
            throw new CatalogException('Die Datei enthält kein gültiges JSON-Array.');
        }

        $zeilen = [];

        foreach ($decoded as $eintrag) {
            if (!\is_array($eintrag)) {
                throw new CatalogException('Jeder Eintrag muss ein Objekt sein.');
            }

            $zeile = [];
            foreach ($eintrag as $key => $value) {
                if (\is_string($key)) {
                    // Alles als Zeichenkette: Die Typumwandlung passiert an
                    // einer Stelle, nicht zweimal je Format.
                    $zeile[$key] = \is_array($value)
                        ? json_encode($value, \JSON_UNESCAPED_UNICODE) ?: '[]'
                        : (\is_bool($value) ? ($value ? '1' : '0') : (string) ($value ?? ''));
                }
            }

            $zeilen[] = $zeile;
        }

        return $zeilen;
    }

    /**
     * @param list<string> $columns
     *
     * @return list<array<string, string>>
     *
     * @throws CatalogException
     */
    private function parseCsv(string $content, array $columns): array
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new CatalogException('Die Datei ließ sich nicht lesen.');
        }

        fwrite($handle, $content);
        rewind($handle);

        $kopf = fgetcsv($handle, 0, ',', '"', '\\');

        if (!\is_array($kopf)) {
            fclose($handle);

            throw new CatalogException('Die CSV-Datei hat keine Kopfzeile.');
        }

        $kopf = array_map(static fn(mixed $spalte): string => trim((string) $spalte), $kopf);

        // Die Kopfzeile bestimmt die Zuordnung. Unbekannte Spalten sind kein
        // Fehler — sie werden ignoriert, damit ein Export aus einer neueren
        // Fassung noch einlesbar bleibt.
        if (array_intersect($columns, $kopf) === []) {
            fclose($handle);

            throw new CatalogException('Keine bekannte Spalte in der Kopfzeile gefunden.');
        }

        $zeilen = [];

        while (($werte = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($werte === [null] || $werte === []) {
                continue;
            }

            $zeile = [];
            foreach ($kopf as $index => $spalte) {
                $zeile[$spalte] = trim((string) ($werte[$index] ?? ''));
            }

            $zeilen[] = $zeile;
        }

        fclose($handle);

        return $zeilen;
    }

    /**
     * @param array<string, string> $row
     *
     * @throws CatalogException
     */
    private function speciesFromRow(array $row): Species
    {
        $wissenschaftlich = $this->string($row, 'scientific_name');

        if ($wissenschaftlich === '') {
            throw new CatalogException('"scientific_name" fehlt.');
        }

        $status = BnatschgStatus::tryFrom($this->string($row, 'bnatschg_status', 'nicht_geschuetzt'));

        if ($status === null) {
            throw new CatalogException('Unbekannter Schutzstatus in "bnatschg_status".');
        }

        $cites = $this->string($row, 'cites_appendix');
        $annex = $this->string($row, 'eu_annex');

        if ($cites !== '' && CitesAppendix::tryFrom($cites) === null) {
            throw new CatalogException(\sprintf('Unbekannter CITES-Anhang "%s".', $cites));
        }

        if ($annex !== '' && EuAnnex::tryFrom($annex) === null) {
            throw new CatalogException(\sprintf('Unbekannter EU-Anhang "%s".', $annex));
        }

        $slug = $this->string($row, 'slug');
        $pflege = $this->string($row, 'care_level');

        if ($pflege !== '' && CareLevel::tryFrom($pflege) === null) {
            throw new CatalogException(\sprintf('Unbekannte Haltungsstufe "%s".', $pflege));
        }

        return new Species(
            null,
            $wissenschaftlich,
            $this->string($row, 'common_name_de', $wissenschaftlich),
            $slug === '' ? Slugger::slug($wissenschaftlich) : $slug,
            $this->nullable($row, 'family'),
            $this->nullable($row, 'order_taxon'),
            $cites === '' ? null : CitesAppendix::from($cites),
            $annex === '' ? null : EuAnnex::from($annex),
            $status,
            $this->bool($row, 'meldepflicht'),
            $this->bool($row, 'doku_pflicht'),
            $this->bool($row, 'gefahrtier'),
            $pflege === '' ? null : CareLevel::from($pflege),
            $this->int($row, 'adult_size_cm'),
            $this->int($row, 'lifespan_years'),
            $this->int($row, 'min_abgabe_alter_wochen'),
            $this->int($row, 'min_abgabe_gewicht_g'),
        );
    }

    /**
     * @param array<string, string> $row
     *
     * @throws CatalogException
     */
    private function morphFromRow(array $row, int $speciesId): Morph
    {
        $name = $this->string($row, 'name');

        if ($name === '') {
            throw new CatalogException('"name" fehlt.');
        }

        $vererbung = Inheritance::tryFrom($this->string($row, 'inheritance', 'recessive'));

        if ($vererbung === null) {
            throw new CatalogException('Unbekannter Erbgang in "inheritance".');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($this->string($row, 'aliases', '[]'), true);
        $aliases = [];

        if (\is_array($decoded)) {
            foreach ($decoded as $alias) {
                if (\is_string($alias) && trim($alias) !== '') {
                    $aliases[] = trim($alias);
                }
            }
        }

        return new Morph(
            null,
            $speciesId,
            $name,
            $vererbung,
            $aliases,
            $this->nullable($row, 'allele_group'),
            $this->bool($row, 'is_lethal_combo'),
            $this->nullable($row, 'description'),
        );
    }

    /**
     * @return array<string, string|int|null>
     */
    private function speciesToArray(Species $species): array
    {
        return [
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
        ];
    }

    /**
     * @return list<array<string, string|int|null>>
     */
    private function allMorphRows(): array
    {
        $zeilen = [];

        foreach ($this->species->all() as $art) {
            if ($art->id === null) {
                continue;
            }

            foreach ($this->morphs->forSpecies($art->id) as $morph) {
                $zeilen[] = [
                    'species_scientific_name' => $art->scientificName,
                    'name' => $morph->name,
                    'aliases' => json_encode($morph->aliases, \JSON_UNESCAPED_UNICODE) ?: '[]',
                    'inheritance' => $morph->inheritance->value,
                    'allele_group' => $morph->alleleGroup,
                    'is_lethal_combo' => $morph->isLethalCombo ? 1 : 0,
                    'description' => $morph->description,
                ];
            }
        }

        return $zeilen;
    }

    /**
     * @param list<string>                             $columns
     * @param list<array<string, string|int|null>>     $rows
     */
    private function toCsv(array $columns, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, $columns, ',', '"', '\\');

        foreach ($rows as $row) {
            $werte = [];
            foreach ($columns as $spalte) {
                $werte[] = $row[$spalte] ?? '';
            }

            fputcsv($handle, $werte, ',', '"', '\\');
        }

        rewind($handle);
        $inhalt = stream_get_contents($handle);
        fclose($handle);

        return $inhalt === false ? '' : $inhalt;
    }

    /**
     * @param array<string, string> $row
     */
    private function string(array $row, string $key, string $default = ''): string
    {
        $value = trim($row[$key] ?? '');

        return $value === '' ? $default : $value;
    }

    /**
     * @param array<string, string> $row
     */
    private function nullable(array $row, string $key): ?string
    {
        $value = $this->string($row, $key);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, string> $row
     */
    private function int(array $row, string $key): ?int
    {
        $value = $this->string($row, $key);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<string, string> $row
     */
    private function bool(array $row, string $key): bool
    {
        return \in_array(strtolower($this->string($row, $key)), ['1', 'true', 'ja', 'yes'], true);
    }
}
