<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Admin\CatalogException;
use Reptilienmarkt\Domain\Admin\SpeciesCatalogService;
use Reptilienmarkt\Domain\Species\BnatschgStatus;
use Reptilienmarkt\Domain\Species\Inheritance;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoMorphRepository;
use Reptilienmarkt\Infra\Persistence\PdoSpeciesRepository;
use Reptilienmarkt\Tests\DatabaseTestCase;

#[CoversClass(SpeciesCatalogService::class)]
final class SpeciesCatalogServiceTest extends DatabaseTestCase
{
    private SpeciesCatalogService $catalog;

    private PdoSpeciesRepository $species;

    private PdoMorphRepository $morphs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->species = new PdoSpeciesRepository($this->database);
        $this->morphs = new PdoMorphRepository($this->database);
        $this->catalog = new SpeciesCatalogService($this->species, $this->morphs, new PdoAuditLog($this->database));
    }

    public function testLegtNeueArtenAusJsonAn(): void
    {
        $json = json_encode([[
            'scientific_name' => 'Python regius',
            'common_name_de' => 'Königspython',
            'bnatschg_status' => 'besonders',
            'cites_appendix' => 'II',
            'meldepflicht' => true,
            'adult_size_cm' => 150,
        ]], \JSON_THROW_ON_ERROR);

        $ergebnis = $this->catalog->importSpecies($json, 'json');

        self::assertTrue($ergebnis->isSuccessful());
        self::assertSame(1, $ergebnis->created);

        $art = $this->species->findByScientificName('Python regius');
        self::assertNotNull($art);
        self::assertSame('Königspython', $art->commonNameDe);
        self::assertSame(BnatschgStatus::Besonders, $art->bnatschgStatus);
        self::assertTrue($art->meldepflicht);
        // Ohne Angabe wird der Slug aus dem wissenschaftlichen Namen gebildet.
        self::assertSame('python-regius', $art->slug);
    }

    public function testAktualisiertUeberDenWissenschaftlichenNamen(): void
    {
        $this->createSpecies('Pogona vitticeps', 'pogona-vitticeps');

        $csv = "scientific_name,common_name_de,bnatschg_status\n"
            . "Pogona vitticeps,Bartagame,nicht_geschuetzt\n";

        $ergebnis = $this->catalog->importSpecies($csv, 'csv');

        self::assertSame(0, $ergebnis->created);
        self::assertSame(1, $ergebnis->updated);
        // Die Kennung ist nur eine Zeilennummer, der Name ist der Schluessel:
        // Es darf keine zweite Zeile derselben Art entstehen.
        self::assertCount(1, $this->species->all());
        self::assertSame('Bartagame', $this->species->findByScientificName('Pogona vitticeps')?->commonNameDe);
    }

    public function testEineFehlerhafteZeileVerhindertDenGanzenImport(): void
    {
        $csv = "scientific_name,bnatschg_status\n"
            . "Python regius,besonders\n"
            . "Boa constrictor,voellig_ausgedacht\n";

        $ergebnis = $this->catalog->importSpecies($csv, 'csv');

        self::assertFalse($ergebnis->isSuccessful());
        self::assertCount(1, $ergebnis->errors);
        self::assertStringContainsString('Zeile 2', $ergebnis->errors[0]);
        // Erst pruefen, dann schreiben: Auch die gute Zeile bleibt draussen.
        self::assertSame([], $this->species->all());
    }

    public function testDerProbelaufSchreibtNichts(): void
    {
        $csv = "scientific_name,bnatschg_status\nPython regius,besonders\n";

        $ergebnis = $this->catalog->importSpecies($csv, 'csv', null, true);

        self::assertTrue($ergebnis->isSuccessful());
        self::assertStringContainsString('Probelauf', $ergebnis->message());
        self::assertSame([], $this->species->all());
    }

    public function testUnbekannteSpaltenWerdenUebergangen(): void
    {
        $csv = "scientific_name,bnatschg_status,irgendwas_neues\n"
            . "Python regius,besonders,egal\n";

        // Ein Export aus einer neueren Fassung soll noch einlesbar bleiben.
        self::assertTrue($this->catalog->importSpecies($csv, 'csv')->isSuccessful());
    }

    public function testEineKopfzeileOhneBekannteSpalteWirdAbgelehnt(): void
    {
        $this->expectException(CatalogException::class);

        $this->catalog->importSpecies("a,b,c\n1,2,3\n", 'csv');
    }

    public function testMorphsBrauchenEineVorhandeneArt(): void
    {
        $json = json_encode([[
            'species_scientific_name' => 'Gibt Es Nicht',
            'name' => 'Hypo',
            'inheritance' => 'recessive',
        ]], \JSON_THROW_ON_ERROR);

        $ergebnis = $this->catalog->importMorphs($json, 'json');

        self::assertFalse($ergebnis->isSuccessful());
        self::assertStringContainsString('nicht im Bestand', $ergebnis->errors[0]);
    }

    public function testLegtMorphsMitAliasenUndErbgangAn(): void
    {
        $artId = $this->createSpecies('Python regius', 'python-regius');

        $json = json_encode([[
            'species_scientific_name' => 'Python regius',
            'name' => 'Pastel',
            'inheritance' => 'incomplete_dominant',
            'aliases' => ['Pastell', 'Pastel Jungle'],
            'allele_group' => 'pastel',
        ]], \JSON_THROW_ON_ERROR);

        $ergebnis = $this->catalog->importMorphs($json, 'json');

        self::assertTrue($ergebnis->isSuccessful());

        $morph = $this->morphs->findByName($artId, 'Pastel');
        self::assertNotNull($morph);
        self::assertSame(Inheritance::IncompleteDominant, $morph->inheritance);
        self::assertSame(['Pastell', 'Pastel Jungle'], $morph->aliases);
    }

    public function testExportUndImportSindEinKreis(): void
    {
        $artId = $this->createSpecies('Python regius', 'python-regius');
        $this->database->execute(
            "INSERT INTO morphs (species_id, name, inheritance, aliases, created_at, updated_at)
             VALUES (:art, 'Pastel', 'incomplete_dominant', '[\"Pastell\"]', :now, :now)",
            ['art' => $artId, 'now' => gmdate('Y-m-d\TH:i:s\Z')],
        );

        $artenCsv = $this->catalog->exportSpeciesCsv();
        $morphsJson = $this->catalog->exportMorphsJson();

        // Frische Datenbank, gleiche Datei: Was herauskommt, muss auch wieder
        // hineingehen — sonst ist der Export als Sicherung wertlos.
        $this->database = \Reptilienmarkt\Infra\Persistence\Database::sqlite(':memory:');
        $this->migrator()->up();
        $this->setUpServices();

        self::assertTrue($this->catalog->importSpecies($artenCsv, 'csv')->isSuccessful());
        self::assertTrue($this->catalog->importMorphs($morphsJson, 'json')->isSuccessful());

        $art = $this->species->findByScientificName('Python regius');
        self::assertNotNull($art);
        self::assertNotNull($art->id);
        self::assertNotNull($this->morphs->findByName($art->id, 'Pastel'));
    }

    public function testDerImportStehtImAuditTrail(): void
    {
        $adminId = $this->createUser('admin@example.tld');

        $this->catalog->importSpecies(
            "scientific_name,bnatschg_status\nPython regius,besonders\n",
            'csv',
            $adminId,
        );

        $eintrag = $this->database->selectOne(
            "SELECT * FROM audit_log WHERE action = 'catalog.species_imported'",
        );

        self::assertNotNull($eintrag);
        self::assertSame($adminId, (int) $eintrag['actor_user_id']);
        self::assertSame('admin', $eintrag['actor_type']);
    }

    private function setUpServices(): void
    {
        $this->species = new PdoSpeciesRepository($this->database);
        $this->morphs = new PdoMorphRepository($this->database);
        $this->catalog = new SpeciesCatalogService($this->species, $this->morphs, new PdoAuditLog($this->database));
    }
}
