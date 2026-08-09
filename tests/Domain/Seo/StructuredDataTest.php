<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Seo;

use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Listing\Listing;
use Reptilienmarkt\Domain\Listing\ListingStatus;
use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Domain\Review\ReviewSummary;
use Reptilienmarkt\Domain\Seo\SitemapUrl;
use Reptilienmarkt\Domain\Seo\StructuredData;
use Reptilienmarkt\Domain\Species\Species;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Infra\Persistence\PdoSitemapRepository;
use Reptilienmarkt\Tests\DatabaseTestCase;

#[CoversClass(StructuredData::class)]
#[CoversClass(PdoSitemapRepository::class)]
#[CoversClass(SitemapUrl::class)]
final class StructuredDataTest extends DatabaseTestCase
{
    // --------------------------------------------------------- Anzeige

    public function testDasJsonLdEinerAnzeigeIstGueltigesJson(): void
    {
        $json = StructuredData::encode(StructuredData::product(
            $this->listing(),
            $this->species(),
            ['https://test.example/uploads/anzeigen/1/a.webp'],
            'https://test.example/anzeige/1/',
            'Züchter',
        ));

        self::assertIsString($json);

        /** @var array<string, mixed> $daten */
        $daten = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('https://schema.org', $daten['@context']);
        self::assertSame('Product', $daten['@type']);
        self::assertSame('Bartagame Nachzucht', $daten['name']);
        self::assertSame(['https://test.example/uploads/anzeigen/1/a.webp'], $daten['image']);
    }

    public function testDerStatusStehtInDerVerfuegbarkeit(): void
    {
        // Ein Enum taugt nicht als Schluessel — deshalb Paare.
        foreach ([
            [ListingStatus::Aktiv, 'https://schema.org/InStock'],
            [ListingStatus::Reserviert, 'https://schema.org/LimitedAvailability'],
            [ListingStatus::Verkauft, 'https://schema.org/SoldOut'],
            [ListingStatus::Pausiert, 'https://schema.org/OutOfStock'],
            [ListingStatus::Abgelaufen, 'https://schema.org/OutOfStock'],
        ] as [$status, $erwartet]) {
            $daten = StructuredData::product(
                $this->listing($status),
                null,
                [],
                'https://test.example/anzeige/1/',
            );

            self::assertSame($erwartet, $daten['offers']['availability'], $status->value);
        }
    }

    public function testDerPreisStehtAlsDezimalzahlMitWaehrung(): void
    {
        $daten = StructuredData::product($this->listing(), null, [], 'https://test.example/anzeige/1/');

        self::assertSame('129.90', $daten['offers']['price']);
        self::assertSame('EUR', $daten['offers']['priceCurrency']);
    }

    public function testOhnePreisGibtEsKeinAngebot(): void
    {
        $daten = StructuredData::product(
            $this->listing(ListingStatus::Aktiv, null),
            null,
            [],
            'https://test.example/anzeige/1/',
        );

        // Ein Offer ohne Preis ist eine leere Huelle — bei Tausch bleibt es weg.
        self::assertArrayNotHasKey('offers', $daten);
    }

    public function testDieAnzeigeTraegtKeineKontobewertung(): void
    {
        $daten = StructuredData::product($this->listing(), null, [], 'https://test.example/anzeige/1/');

        // Die Bewertungen gelten dem Konto, nicht diesem Tier.
        self::assertArrayNotHasKey('aggregateRating', $daten);
    }

    // ------------------------------------------------------ Artenprofil

    public function testDasArtenprofilIstEineSammelseiteMitAhnenreihe(): void
    {
        $json = StructuredData::encode(StructuredData::speciesPage($this->species(), 'https://test.example', 42));

        self::assertIsString($json);

        /** @var array{'@graph': list<array<string, mixed>>} $daten */
        $daten = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('CollectionPage', $daten['@graph'][0]['@type']);
        self::assertSame(42, $daten['@graph'][0]['mainEntity']['numberOfItems']);
        self::assertSame('BreadcrumbList', $daten['@graph'][1]['@type']);
        self::assertCount(3, $daten['@graph'][1]['itemListElement']);
        self::assertSame(
            'https://test.example/art/pogona-vitticeps/',
            $daten['@graph'][1]['itemListElement'][2]['item'],
        );
    }

    // ------------------------------------------------------ Zuechterseite

    public function testDieZuechterseiteZeigtDieNoteErstAbDreiBewertungen(): void
    {
        $ohne = StructuredData::breeder($this->user(), 'https://test.example/zuechter/a/', null, null, new ReviewSummary(2, 5.0, []));
        $mit = StructuredData::breeder($this->user(), 'https://test.example/zuechter/a/', null, null, new ReviewSummary(3, 4.5, []));

        self::assertArrayNotHasKey('aggregateRating', $ohne);
        self::assertSame(4.5, $mit['aggregateRating']['ratingValue']);
        self::assertSame(3, $mit['aggregateRating']['reviewCount']);
    }

    public function testGewerblicheAnbieterSindEineOrganisation(): void
    {
        $privat = StructuredData::breeder($this->user(), 'https://test.example/zuechter/a/', null, null, new ReviewSummary(0, null, []));
        $gewerblich = StructuredData::breeder($this->user(true), 'https://test.example/zuechter/a/', null, null, new ReviewSummary(0, null, []));

        self::assertSame('Person', $privat['@type']);
        self::assertSame('Organization', $gewerblich['@type']);
    }

    // ---------------------------------------------------------- Sitemap

    public function testEinePausierteAnzeigeStehtNichtInDerSitemap(): void
    {
        $userId = $this->createUser('anbieter@example.tld');
        $art = $this->createSpecies();

        $aktiv = $this->createListing($userId, $art);
        $pausiert = $this->createListing($userId, $art, 'pausiert');
        $pruefung = $this->createListing($userId, $art, 'pruefung');
        $entwurf = $this->createListing($userId, $art, 'entwurf');

        $adressen = array_map(
            static fn(SitemapUrl $url): string => $url->loc,
            (new PdoSitemapRepository($this->database))->listings(),
        );

        self::assertContains('/anzeige/' . $aktiv . '/', $adressen);
        // Die Sitemap ist eine Einladung — auf eine Seite, die 404 antwortet,
        // einzuladen ist ein gemeldeter Fehler.
        self::assertNotContains('/anzeige/' . $pausiert . '/', $adressen);
        self::assertNotContains('/anzeige/' . $pruefung . '/', $adressen);
        self::assertNotContains('/anzeige/' . $entwurf . '/', $adressen);
    }

    public function testNurOeffentlicheZuechterseitenAktiverKontenStehenDrin(): void
    {
        $offen = $this->createUser('offen@example.tld');
        $versteckt = $this->createUser('versteckt@example.tld');

        $this->profil($offen, 'offen', true);
        $this->profil($versteckt, 'versteckt', false);

        $adressen = array_map(
            static fn(SitemapUrl $url): string => $url->loc,
            (new PdoSitemapRepository($this->database))->breeders(),
        );

        self::assertSame(['/zuechter/offen/'], $adressen);
    }

    private function profil(int $userId, string $slug, bool $public): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $this->database->execute(
            'INSERT INTO breeder_profiles (user_id, slug, is_public, created_at, updated_at)
             VALUES (:user, :slug, :public, :now, :now)',
            ['user' => $userId, 'slug' => $slug, 'public' => $public ? 1 : 0, 'now' => $now],
        );
    }

    private function listing(ListingStatus $status = ListingStatus::Aktiv, ?int $priceCents = 12990): Listing
    {
        return new Listing(
            1,
            7,
            ListingType::Verkauf,
            3,
            'Bartagame Nachzucht',
            description: 'Kräftige Nachzucht aus eigener Haltung.',
            priceCents: $priceCents,
            status: $status,
        );
    }

    private function species(): Species
    {
        return new Species(3, 'Pogona vitticeps', 'Bartagame', 'pogona-vitticeps');
    }

    private function user(bool $commercial = false): User
    {
        return new User(7, 'zuechter@example.tld', 'Züchter', Role::Seller, isCommercial: $commercial);
    }
}
