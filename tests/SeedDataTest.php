<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Domain\Species\BnatschgStatus;
use Reptilienmarkt\Domain\Species\CareLevel;
use Reptilienmarkt\Domain\Species\CitesAppendix;
use Reptilienmarkt\Domain\Species\EuAnnex;
use Reptilienmarkt\Domain\Species\Inheritance;

/**
 * Der Artenstamm ist die Eingangsgroesse der Rechts-Engine. Diese Tests halten
 * die strukturelle Konsistenz fest — die fachliche Richtigkeit des Schutzstatus
 * bleibt Aufgabe der redaktionellen Pflege (siehe data/README.md).
 */
final class SeedDataTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private static function species(): array
    {
        /** @var array{arten: list<array<string, mixed>>} $data */
        $data = json_decode(
            (string) file_get_contents(\dirname(__DIR__) . '/data/species.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        return $data['arten'];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private static function morphs(): array
    {
        /** @var array{morphs: array<string, list<array<string, mixed>>>} $data */
        $data = json_decode(
            (string) file_get_contents(\dirname(__DIR__) . '/data/morphs.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        return $data['morphs'];
    }

    public function testMindestensVierzigArten(): void
    {
        self::assertGreaterThanOrEqual(40, \count(self::species()));
    }

    public function testWissenschaftlicheNamenUndSlugsSindEindeutig(): void
    {
        $names = array_column(self::species(), 'scientific_name');
        $slugs = array_column(self::species(), 'slug');

        self::assertSame($names, array_values(array_unique($names)));
        self::assertSame($slugs, array_values(array_unique($slugs)));
    }

    public function testSlugPasstZumWissenschaftlichenNamen(): void
    {
        foreach (self::species() as $row) {
            $expected = strtolower(str_replace(' ', '-', (string) $row['scientific_name']));

            self::assertSame($expected, $row['slug'], \sprintf('Slug passt nicht zu %s.', (string) $row['scientific_name']));
        }
    }

    public function testAlleAufzaehlungswerteSindGueltig(): void
    {
        foreach (self::species() as $row) {
            $name = (string) $row['scientific_name'];

            self::assertNotNull(
                BnatschgStatus::tryFrom((string) $row['bnatschg_status']),
                \sprintf('Unbekannter BNatSchG-Status bei %s.', $name),
            );

            if (\is_string($row['cites_appendix'])) {
                self::assertNotNull(CitesAppendix::tryFrom($row['cites_appendix']), \sprintf('Unbekannter CITES-Anhang bei %s.', $name));
            }

            if (\is_string($row['eu_annex'])) {
                self::assertNotNull(EuAnnex::tryFrom($row['eu_annex']), \sprintf('Unbekannter EU-Anhang bei %s.', $name));
            }

            if (\is_string($row['care_level'])) {
                self::assertNotNull(CareLevel::tryFrom($row['care_level']), \sprintf('Unbekannte Haltungsstufe bei %s.', $name));
            }
        }
    }

    /**
     * Anhang A zieht die Vermarktungsbescheinigung nach sich (Regel 1) — solche
     * Arten muessen zugleich streng geschuetzt, melde- und dokumentationspflichtig sein.
     */
    public function testAnhangAIstStrengGeschuetztUndNachweispflichtig(): void
    {
        $found = 0;

        foreach (self::species() as $row) {
            if ($row['eu_annex'] !== 'A') {
                continue;
            }

            ++$found;
            $name = (string) $row['scientific_name'];

            self::assertSame(BnatschgStatus::Streng->value, $row['bnatschg_status'], \sprintf('%s ist Anhang A, aber nicht streng geschuetzt.', $name));
            self::assertSame(1, $row['meldepflicht'], \sprintf('%s ist Anhang A, aber nicht meldepflichtig.', $name));
            self::assertSame(1, $row['doku_pflicht'], \sprintf('%s ist Anhang A ohne Dokumentationspflicht.', $name));
        }

        self::assertGreaterThan(0, $found, 'Der Seed braucht mindestens eine Anhang-A-Art als Testfall fuer Regel 1.');
    }

    /**
     * Anhang B loest die Herkunftsnachweispflicht aus (Regel 2).
     */
    public function testAnhangBIstDokumentationspflichtig(): void
    {
        $found = 0;

        foreach (self::species() as $row) {
            if ($row['eu_annex'] !== 'B') {
                continue;
            }

            ++$found;
            self::assertSame(
                1,
                $row['doku_pflicht'],
                \sprintf('%s ist Anhang B ohne Dokumentationspflicht.', (string) $row['scientific_name']),
            );
        }

        self::assertGreaterThan(0, $found);
    }

    public function testCitesUndEuAnhangSindKonsistent(): void
    {
        foreach (self::species() as $row) {
            $name = (string) $row['scientific_name'];

            if ($row['eu_annex'] !== null) {
                self::assertNotNull(
                    $row['cites_appendix'],
                    \sprintf('%s hat einen EU-Anhang, aber keinen CITES-Anhang.', $name),
                );
            }

            if ($row['cites_appendix'] === null) {
                self::assertSame(
                    BnatschgStatus::NichtGeschuetzt->value,
                    $row['bnatschg_status'],
                    \sprintf('%s ist nicht CITES-gelistet, aber als geschuetzt gefuehrt.', $name),
                );
            }
        }
    }

    public function testTierschutzGrenzwerteSindGesetzt(): void
    {
        foreach (self::species() as $row) {
            $name = (string) $row['scientific_name'];

            self::assertIsInt($row['min_abgabe_alter_wochen'], \sprintf('%s ohne Mindestabgabealter.', $name));
            self::assertIsInt($row['min_abgabe_gewicht_g'], \sprintf('%s ohne Mindestabgabegewicht.', $name));
            self::assertGreaterThan(0, $row['min_abgabe_alter_wochen']);
            self::assertGreaterThan(0, $row['min_abgabe_gewicht_g']);
        }
    }

    public function testMindestensEineGefahrtierArtFuerRegelFuenf(): void
    {
        $gefahrtiere = array_filter(self::species(), static fn(array $row): bool => $row['gefahrtier'] === 1);

        self::assertGreaterThanOrEqual(3, \count($gefahrtiere));
    }

    #[DataProvider('artenMitMerkmalskatalog')]
    public function testMerkmalskatalogVorhanden(string $scientificName, int $minimum): void
    {
        $morphs = self::morphs();

        self::assertArrayHasKey($scientificName, $morphs);
        self::assertGreaterThanOrEqual($minimum, \count($morphs[$scientificName]));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function artenMitMerkmalskatalog(): iterable
    {
        yield 'Bartagame' => ['Pogona vitticeps', 10];

        yield 'Königspython' => ['Python regius', 15];

        yield 'Leopardgecko' => ['Eublepharis macularius', 12];

        yield 'Kronengecko' => ['Correlophus ciliatus', 10];
    }

    public function testJedeMerkmalsartStehtImArtenstamm(): void
    {
        $known = array_column(self::species(), 'scientific_name');

        foreach (array_keys(self::morphs()) as $scientificName) {
            self::assertContains($scientificName, $known);
        }
    }

    public function testMerkmaleSindStrukturellGueltig(): void
    {
        foreach (self::morphs() as $scientificName => $entries) {
            $names = array_column($entries, 'name');
            self::assertSame($names, array_values(array_unique($names)), \sprintf('Doppelter Merkmalsname bei %s.', $scientificName));

            foreach ($entries as $entry) {
                $label = $scientificName . ' / ' . (string) $entry['name'];

                self::assertNotNull(Inheritance::tryFrom((string) $entry['inheritance']), \sprintf('Unbekannter Vererbungsmodus bei %s.', $label));
                self::assertIsArray($entry['aliases'], \sprintf('aliases muss ein Array sein bei %s.', $label));
                self::assertContains($entry['is_lethal_combo'], [0, 1], \sprintf('is_lethal_combo muss 0 oder 1 sein bei %s.', $label));
            }
        }
    }

    /**
     * Letalkombinationen sind der Ausloeser fuer die Zuchtwarnung — mindestens
     * die bekannten Faelle muessen markiert sein.
     */
    public function testBekannteLetalkombinationenSindMarkiert(): void
    {
        $lethal = [];
        foreach (self::morphs() as $entries) {
            foreach ($entries as $entry) {
                if ($entry['is_lethal_combo'] === 1) {
                    $lethal[] = (string) $entry['name'];
                }
            }
        }

        foreach (['Spider', 'Champagne', 'Hidden Gene Woma', 'Lilly White'] as $name) {
            self::assertContains($name, $lethal, \sprintf('%s muss als Letalkombination markiert sein.', $name));
        }
    }

    /**
     * Merkmale derselben Allelgruppe besetzen denselben Genort — die drei
     * Leopardgecko-Albinolinien sind gerade NICHT allel zueinander.
     */
    public function testAlbinoLinienDesLeopardgeckosSindNichtAllel(): void
    {
        $groups = [];
        foreach (self::morphs()['Eublepharis macularius'] as $entry) {
            if (str_starts_with((string) $entry['name'], 'Albino ')) {
                $groups[] = $entry['allele_group'];
            }
        }

        self::assertCount(3, $groups);
        self::assertSame($groups, array_values(array_unique($groups)));
    }
}
