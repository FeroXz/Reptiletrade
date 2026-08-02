<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Infra\Persistence;

use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Reptilienmarkt\Tests\DatabaseTestCase;

/**
 * Die Datenbank ist die letzte Verteidigungslinie: Diese Tests halten fest,
 * welche Zusicherungen das Schema selbst durchsetzt.
 */
final class SchemaConstraintsTest extends DatabaseTestCase
{
    public function testAuditLogVerweigertAenderungen(): void
    {
        $this->database->execute(
            "INSERT INTO audit_log (occurred_at, action, entity_type, entity_id)
             VALUES (:now, 'listing.created', 'listing', 1)",
            ['now' => gmdate('Y-m-d\TH:i:s\Z')],
        );

        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/append-only/');

        $this->database->execute("UPDATE audit_log SET action = 'manipuliert'");
    }

    public function testAuditLogVerweigertLoeschungen(): void
    {
        $this->database->execute(
            "INSERT INTO audit_log (occurred_at, action, entity_type, entity_id)
             VALUES (:now, 'listing.created', 'listing', 1)",
            ['now' => gmdate('Y-m-d\TH:i:s\Z')],
        );

        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/append-only/');

        $this->database->execute('DELETE FROM audit_log');
    }

    public function testMaximalZwoelfBilderJeAnzeige(): void
    {
        $listingId = $this->createListing($this->createUser(), $this->createSpecies());

        for ($i = 1; $i <= 12; ++$i) {
            $this->insertMedia($listingId, 'bild', \sprintf('bild-%d.webp', $i));
        }

        self::assertSame(12, $this->mediaCount($listingId, 'bild'));

        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/Maximal 12 Bilder/');

        $this->insertMedia($listingId, 'bild', 'bild-13.webp');
    }

    public function testMaximalEineVideoUrlJeAnzeige(): void
    {
        $listingId = $this->createListing($this->createUser(), $this->createSpecies());
        $this->insertMedia($listingId, 'video', 'https://example.tld/video');

        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/Maximal eine Video-URL/');

        $this->insertMedia($listingId, 'video', 'https://example.tld/zweites-video');
    }

    public function testNurEinPrimaerbildJeAnzeige(): void
    {
        $listingId = $this->createListing($this->createUser(), $this->createSpecies());
        $this->insertMedia($listingId, 'bild', 'erstes.webp', true);

        $this->expectException(PDOException::class);

        $this->insertMedia($listingId, 'bild', 'zweites.webp', true);
    }

    /**
     * Rechtsdokumente duerfen niemals im Webroot landen.
     */
    #[DataProvider('unzulaessigePfade')]
    public function testLegalDocsVerweigertPfadeImWebroot(string $path): void
    {
        $listingId = $this->createListing($this->createUser(), $this->createSpecies());

        $this->expectException(PDOException::class);

        $this->database->execute(
            "INSERT INTO legal_docs (listing_id, doc_type, private_path, created_at, updated_at)
             VALUES (:listing_id, 'herkunftsnachweis', :path, :now, :now)",
            ['listing_id' => $listingId, 'path' => $path, 'now' => gmdate('Y-m-d\TH:i:s\Z')],
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unzulaessigePfade(): iterable
    {
        yield 'Webroot' => ['public/uploads/nachweis.pdf'];

        yield 'absoluter Pfad' => ['/etc/passwd'];

        yield 'Pfadwechsel' => ['../public/nachweis.pdf'];
    }

    public function testLegalDocsAkzeptiertRelativenPrivatenPfad(): void
    {
        $listingId = $this->createListing($this->createUser(), $this->createSpecies());

        $this->database->execute(
            "INSERT INTO legal_docs (listing_id, doc_type, private_path, created_at, updated_at)
             VALUES (:listing_id, 'eu_bescheinigung', 'legal/2026/abc123.pdf', :now, :now)",
            ['listing_id' => $listingId, 'now' => gmdate('Y-m-d\TH:i:s\Z')],
        );

        self::assertSame(1, (int) $this->database->scalar('SELECT COUNT(*) FROM legal_docs'));
    }

    public function testBewertungNurMitGueltigerNote(): void
    {
        $seller = $this->createUser('seller@example.tld');
        $buyer = $this->createUser('buyer@example.tld');
        $listingId = $this->createListing($seller, $this->createSpecies());

        $this->expectException(PDOException::class);

        $this->database->execute(
            'INSERT INTO reviews (listing_id, from_user_id, to_user_id, rating, deal_confirmed_at, created_at)
             VALUES (:listing_id, :from, :to, 6, :now, :now)',
            ['listing_id' => $listingId, 'from' => $buyer, 'to' => $seller, 'now' => gmdate('Y-m-d\TH:i:s\Z')],
        );
    }

    public function testBewertungNichtAnSichSelbst(): void
    {
        $userId = $this->createUser();
        $listingId = $this->createListing($userId, $this->createSpecies());

        $this->expectException(PDOException::class);

        $this->database->execute(
            'INSERT INTO reviews (listing_id, from_user_id, to_user_id, rating, deal_confirmed_at, created_at)
             VALUES (:listing_id, :user, :user, 5, :now, :now)',
            ['listing_id' => $listingId, 'user' => $userId, 'now' => gmdate('Y-m-d\TH:i:s\Z')],
        );
    }

    public function testKonversationBrauchtZweiVerschiedeneParteien(): void
    {
        $userId = $this->createUser();
        $listingId = $this->createListing($userId, $this->createSpecies());

        $this->expectException(PDOException::class);

        $this->database->execute(
            'INSERT INTO conversations (listing_id, buyer_id, seller_id, created_at)
             VALUES (:listing_id, :user, :user, :now)',
            ['listing_id' => $listingId, 'user' => $userId, 'now' => gmdate('Y-m-d\TH:i:s\Z')],
        );
    }

    public function testUnbekannterAnzeigenstatusWirdAbgelehnt(): void
    {
        $userId = $this->createUser();
        $speciesId = $this->createSpecies();

        $this->expectException(PDOException::class);

        $this->createListing($userId, $speciesId, 'faellig');
    }

    public function testLoeschenEinerAnzeigeRaeumtAbhaengigeDatenAb(): void
    {
        $userId = $this->createUser();
        $listingId = $this->createListing($userId, $this->createSpecies());
        $this->insertMedia($listingId, 'bild', 'bild.webp');

        $this->database->execute('DELETE FROM listings WHERE id = :id', ['id' => $listingId]);

        self::assertSame(0, (int) $this->database->scalar('SELECT COUNT(*) FROM listing_media'));
    }

    public function testArtMitAnzeigeLaesstSichNichtLoeschen(): void
    {
        $speciesId = $this->createSpecies();
        $this->createListing($this->createUser(), $speciesId);

        $this->expectException(PDOException::class);

        $this->database->execute('DELETE FROM species WHERE id = :id', ['id' => $speciesId]);
    }

    public function testUngueltigesJsonInGespeicherterSucheWirdAbgelehnt(): void
    {
        $userId = $this->createUser();

        $this->expectException(PDOException::class);

        $this->database->execute(
            "INSERT INTO saved_searches (user_id, name, filter_json, created_at, updated_at)
             VALUES (:user_id, 'Suche', 'kein json', :now, :now)",
            ['user_id' => $userId, 'now' => gmdate('Y-m-d\TH:i:s\Z')],
        );
    }

    private function insertMedia(int $listingId, string $type, string $path, bool $primary = false): void
    {
        $this->database->execute(
            'INSERT INTO listing_media (listing_id, media_type, path, is_primary, created_at)
             VALUES (:listing_id, :type, :path, :primary, :now)',
            [
                'listing_id' => $listingId,
                'type' => $type,
                'path' => $path,
                'primary' => $primary ? 1 : 0,
                'now' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
        );
    }

    private function mediaCount(int $listingId, string $type): int
    {
        return (int) $this->database->scalar(
            'SELECT COUNT(*) FROM listing_media WHERE listing_id = :listing_id AND media_type = :type',
            ['listing_id' => $listingId, 'type' => $type],
        );
    }
}
