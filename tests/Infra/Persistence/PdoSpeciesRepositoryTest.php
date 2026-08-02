<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Infra\Persistence;

use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Species\BnatschgStatus;
use Reptilienmarkt\Domain\Species\CareLevel;
use Reptilienmarkt\Domain\Species\CitesAppendix;
use Reptilienmarkt\Domain\Species\EuAnnex;
use Reptilienmarkt\Domain\Species\Inheritance;
use Reptilienmarkt\Domain\Species\Morph;
use Reptilienmarkt\Domain\Species\Species;
use Reptilienmarkt\Infra\Persistence\PdoMorphRepository;
use Reptilienmarkt\Infra\Persistence\PdoSpeciesRepository;
use Reptilienmarkt\Tests\DatabaseTestCase;

#[CoversClass(PdoSpeciesRepository::class)]
#[CoversClass(PdoMorphRepository::class)]
final class PdoSpeciesRepositoryTest extends DatabaseTestCase
{
    private PdoSpeciesRepository $species;

    private PdoMorphRepository $morphs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->species = new PdoSpeciesRepository($this->database);
        $this->morphs = new PdoMorphRepository($this->database);
    }

    private function bartagame(): Species
    {
        return new Species(
            null,
            'Pogona vitticeps',
            'Bartagame',
            'pogona-vitticeps',
            'Agamidae',
            'Squamata',
            null,
            null,
            BnatschgStatus::NichtGeschuetzt,
            false,
            false,
            false,
            CareLevel::Einsteiger,
            50,
            12,
            8,
            25,
        );
    }

    private function griechischeLandschildkroete(): Species
    {
        return new Species(
            null,
            'Testudo hermanni',
            'Griechische Landschildkröte',
            'testudo-hermanni',
            'Testudinidae',
            'Testudines',
            CitesAppendix::II,
            EuAnnex::A,
            BnatschgStatus::Streng,
            true,
            true,
            false,
            CareLevel::Fortgeschritten,
            25,
            80,
            12,
            20,
        );
    }

    public function testSpeichertUndLiestVollstaendigZurueck(): void
    {
        $id = $this->species->save($this->griechischeLandschildkroete());

        $loaded = $this->species->findById($id);

        self::assertNotNull($loaded);
        self::assertSame('Testudo hermanni', $loaded->scientificName);
        self::assertSame(CitesAppendix::II, $loaded->citesAppendix);
        self::assertSame(EuAnnex::A, $loaded->euAnnex);
        self::assertSame(BnatschgStatus::Streng, $loaded->bnatschgStatus);
        self::assertTrue($loaded->meldepflicht);
        self::assertTrue($loaded->dokuPflicht);
        self::assertFalse($loaded->gefahrtier);
        self::assertSame(12, $loaded->minAbgabeAlterWochen);
        self::assertSame(20, $loaded->minAbgabeGewichtG);
    }

    public function testNullwerteBleibenNull(): void
    {
        $id = $this->species->save($this->bartagame());
        $loaded = $this->species->findById($id);

        self::assertNotNull($loaded);
        self::assertNull($loaded->citesAppendix);
        self::assertNull($loaded->euAnnex);
        self::assertSame(BnatschgStatus::NichtGeschuetzt, $loaded->bnatschgStatus);
    }

    public function testErneutesSpeichernAktualisiertStattZuDuplizieren(): void
    {
        $first = $this->species->save($this->bartagame());

        $changed = new Species(
            null,
            'Pogona vitticeps',
            'Streifenköpfige Bartagame',
            'pogona-vitticeps',
            'Agamidae',
            'Squamata',
            null,
            null,
            BnatschgStatus::NichtGeschuetzt,
            false,
            false,
            false,
            CareLevel::Fortgeschritten,
            55,
            12,
            10,
            30,
        );

        $second = $this->species->save($changed);

        self::assertSame($first, $second);
        self::assertCount(1, $this->species->all());

        $loaded = $this->species->findById($first);
        self::assertNotNull($loaded);
        self::assertSame('Streifenköpfige Bartagame', $loaded->commonNameDe);
        self::assertSame(10, $loaded->minAbgabeAlterWochen);
    }

    public function testFindetUeberSlugUndWissenschaftlichenNamen(): void
    {
        $this->species->save($this->bartagame());

        self::assertNotNull($this->species->findBySlug('pogona-vitticeps'));
        self::assertNotNull($this->species->findByScientificName('Pogona vitticeps'));
        self::assertNull($this->species->findBySlug('gibt-es-nicht'));
    }

    public function testAutocompleteFindetDeutschenUndWissenschaftlichenNamen(): void
    {
        $this->species->save($this->bartagame());
        $this->species->save($this->griechischeLandschildkroete());

        self::assertCount(1, $this->species->search('Bartag'));
        self::assertCount(1, $this->species->search('Testudo'));
        self::assertCount(0, $this->species->search('Chamaeleo'));
    }

    public function testAutocompleteBehandeltPlatzhalterAlsText(): void
    {
        $this->species->save($this->bartagame());

        self::assertCount(0, $this->species->search('%'));
    }

    public function testGattungWirdAusDemNamenAbgeleitet(): void
    {
        self::assertSame('Testudo', $this->griechischeLandschildkroete()->genus());
    }

    public function testMerkmaleHaengenAnDerArt(): void
    {
        $speciesId = $this->species->save($this->bartagame());

        $this->morphs->save(new Morph(
            null,
            $speciesId,
            'Hypomelanistic',
            Inheritance::Recessive,
            ['Hypo'],
            null,
            false,
            'Reduziertes Melanin.',
        ));
        $this->morphs->save(new Morph(null, $speciesId, 'Leatherback', Inheritance::IncompleteDominant, [], 'leatherback'));

        $loaded = $this->morphs->forSpecies($speciesId);

        self::assertCount(2, $loaded);
        self::assertSame('Hypomelanistic', $loaded[0]->name);
        self::assertSame(['Hypo'], $loaded[0]->aliases);
        self::assertSame(Inheritance::Recessive, $loaded[0]->inheritance);
        self::assertTrue($loaded[0]->inheritance->allowsHeterozygous());
        self::assertFalse($loaded[1]->inheritance->allowsHeterozygous());
    }

    public function testErneutesSpeichernEinesMerkmalsAktualisiert(): void
    {
        $speciesId = $this->species->save($this->bartagame());

        $first = $this->morphs->save(new Morph(null, $speciesId, 'Zero', Inheritance::Recessive));
        $second = $this->morphs->save(new Morph(null, $speciesId, 'Zero', Inheritance::Recessive, [], 'zero_witblits'));

        self::assertSame($first, $second);
        self::assertCount(1, $this->morphs->forSpecies($speciesId));

        $loaded = $this->morphs->findByName($speciesId, 'Zero');
        self::assertNotNull($loaded);
        self::assertSame('zero_witblits', $loaded->alleleGroup);
    }

    public function testMerkmaleDerselbenAllelgruppeTeilenDenGenort(): void
    {
        $speciesId = $this->species->save($this->bartagame());
        $this->morphs->save(new Morph(null, $speciesId, 'Witblits', Inheritance::Recessive, [], 'zero_witblits'));
        $this->morphs->save(new Morph(null, $speciesId, 'Zero', Inheritance::Recessive, [], 'zero_witblits'));
        $this->morphs->save(new Morph(null, $speciesId, 'Dunner', Inheritance::Dominant));

        $witblits = $this->morphs->findByName($speciesId, 'Witblits');
        $zero = $this->morphs->findByName($speciesId, 'Zero');
        $dunner = $this->morphs->findByName($speciesId, 'Dunner');

        self::assertNotNull($witblits);
        self::assertNotNull($zero);
        self::assertNotNull($dunner);
        self::assertTrue($witblits->sharesLocusWith($zero));
        self::assertFalse($witblits->sharesLocusWith($dunner));
        self::assertFalse($dunner->sharesLocusWith($dunner), 'Ohne Allelgruppe gibt es keinen gemeinsamen Genort.');
    }

    public function testLoeschenDerArtEntferntDieMerkmale(): void
    {
        $speciesId = $this->species->save($this->bartagame());
        $this->morphs->save(new Morph(null, $speciesId, 'Hypo', Inheritance::Recessive));

        $this->species->deleteById($speciesId);

        self::assertSame([], $this->morphs->forSpecies($speciesId));
    }
}
