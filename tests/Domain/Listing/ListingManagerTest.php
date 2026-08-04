<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Listing;

use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Listing\ListingManagementException;
use Reptilienmarkt\Domain\Listing\ListingManager;
use Reptilienmarkt\Domain\Listing\ListingStatus;
use Reptilienmarkt\Domain\Listing\PauseActor;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Infra\Persistence\PdoListingRepository;
use Reptilienmarkt\Support\Timestamp;
use Reptilienmarkt\Tests\DatabaseTestCase;

/**
 * Die Regeln rund um eine veroeffentlichte Anzeige.
 */
#[CoversClass(ListingManager::class)]
#[CoversClass(PdoListingRepository::class)]
final class ListingManagerTest extends DatabaseTestCase
{
    private PdoListingRepository $listings;

    private int $sellerId;

    private int $speciesId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->listings = new PdoListingRepository($this->database);
        $this->sellerId = $this->createUser('anbieter@example.tld');
        $this->speciesId = $this->createSpecies();
    }

    public function testPausierenNimmtDieAnzeigeAusDerOeffentlichkeit(): void
    {
        $id = $this->createListing($this->sellerId, $this->speciesId, 'aktiv');

        $this->listings->pause($id, PauseActor::Anbieter, null, ListingStatus::Aktiv);

        $listing = $this->listings->findById($id);
        self::assertNotNull($listing);
        self::assertSame(ListingStatus::Pausiert, $listing->status);
        self::assertFalse($listing->status->isPubliclyVisible());
    }

    public function testFortsetzenStelltDenVorherigenZustandWiederHer(): void
    {
        $id = $this->createListing($this->sellerId, $this->speciesId, 'reserviert');

        $this->listings->pause($id, PauseActor::Anbieter, null, ListingStatus::Reserviert);
        $zustand = $this->listings->pauseState($id);

        self::assertNotNull($zustand);
        // Eine reservierte Anzeige darf nach der Pause nicht ploetzlich wieder
        // frei sein.
        self::assertSame(ListingStatus::Reserviert, $zustand->previousStatus);

        $this->listings->resume($id, $zustand->previousStatus);

        self::assertSame(ListingStatus::Reserviert, $this->listings->findById($id)?->status);
        self::assertNull($this->listings->pauseState($id));
    }

    public function testEineVerwaltungspauseHebtDerAnbieterNichtAuf(): void
    {
        $id = $this->createListing($this->sellerId, $this->speciesId, 'aktiv');
        $this->listings->pause($id, PauseActor::Verwaltung, 'Verstoß gegen die Richtlinien', ListingStatus::Aktiv);

        $zustand = $this->listings->pauseState($id);

        self::assertNotNull($zustand);
        self::assertTrue($zustand->byAdmin());
        self::assertFalse($zustand->actor->mayBeResumedByOwner());
        // Der Grund gehoert in die Erklaerung: Wer nicht erfaehrt, warum seine
        // Anzeige steht, kann es nicht abstellen.
        self::assertStringContainsString('Verstoß gegen die Richtlinien', $zustand->explanation());
    }

    public function testEineEigenePauseNenntKeinenGrund(): void
    {
        $id = $this->createListing($this->sellerId, $this->speciesId, 'aktiv');
        $this->listings->pause($id, PauseActor::Anbieter, null, ListingStatus::Aktiv);

        $zustand = $this->listings->pauseState($id);

        self::assertNotNull($zustand);
        self::assertNull($zustand->reason);
        self::assertTrue($zustand->actor->mayBeResumedByOwner());
    }

    public function testNurOeffentlicheAnzeigenLassenSichPausieren(): void
    {
        foreach (['entwurf', 'pruefung', 'verkauft', 'gesperrt', 'abgelaufen'] as $status) {
            self::assertFalse(
                ListingStatus::from($status)->isPausable(),
                \sprintf('"%s" sollte nicht pausierbar sein.', $status),
            );
        }

        self::assertTrue(ListingStatus::Aktiv->isPausable());
        self::assertTrue(ListingStatus::Reserviert->isPausable());
    }

    public function testAbgeschlosseneAnzeigenLassenSichNichtMehrBearbeiten(): void
    {
        // Eine verkaufte Anzeige nachtraeglich umzuschreiben wuerde die
        // Bewertung des Handels auf einen anderen Text zeigen lassen.
        self::assertFalse(ListingStatus::Verkauft->isEditable());
        self::assertFalse(ListingStatus::Abgelaufen->isEditable());
        self::assertFalse(ListingStatus::Gesperrt->isEditable());

        self::assertTrue(ListingStatus::Aktiv->isEditable());
        self::assertTrue(ListingStatus::Pausiert->isEditable());
    }

    public function testBearbeitungenWerdenProtokolliertUndGezaehlt(): void
    {
        $id = $this->createListing($this->sellerId, $this->speciesId, 'aktiv');

        $this->listings->recordEdit($id, $this->sellerId, ['titel' => 'Alter Titel']);
        $this->listings->recordEdit($id, $this->sellerId, ['preis_cent' => 12000]);

        self::assertSame(2, $this->listings->editCount($id));

        // Ein Protokoll je Bearbeitung — genau die Abfolge ist der Zweck.
        $eintraege = $this->database->select(
            'SELECT changed_json FROM listing_edits WHERE listing_id = :id ORDER BY id',
            ['id' => $id],
        );

        self::assertCount(2, $eintraege);
        self::assertStringContainsString('Alter Titel', (string) $eintraege[0]['changed_json']);
    }

    public function testEineAnzeigeOhneAnhangWirdWirklichGeloescht(): void
    {
        $id = $this->createListing($this->sellerId, $this->speciesId, 'aktiv');

        self::assertSame(0, $this->listings->conversationCount($id));
        self::assertSame(0, $this->listings->reviewCount($id));

        $this->listings->delete($id);

        self::assertNull($this->listings->findById($id));
    }

    public function testGespraecheUndBewertungenWerdenGezaehlt(): void
    {
        $id = $this->createListing($this->sellerId, $this->speciesId, 'aktiv');
        $kaeufer = $this->createUser('kaeufer@example.tld');
        $now = Timestamp::now();

        $this->database->execute(
            'INSERT INTO conversations (listing_id, buyer_id, seller_id, message_count, created_at)
             VALUES (:listing, :buyer, :seller, 1, :now)',
            ['listing' => $id, 'buyer' => $kaeufer, 'seller' => $this->sellerId, 'now' => $now],
        );
        $this->database->execute(
            'INSERT INTO reviews (listing_id, from_user_id, to_user_id, rating, deal_confirmed_at, created_at)
             VALUES (:listing, :from, :to, 5, :now, :now)',
            ['listing' => $id, 'from' => $kaeufer, 'to' => $this->sellerId, 'now' => $now],
        );

        self::assertSame(1, $this->listings->conversationCount($id));
        self::assertSame(1, $this->listings->reviewCount($id));
    }

    public function testDieVerwaltungslisteZeigtAnbieterArtUndPause(): void
    {
        $id = $this->createListing($this->sellerId, $this->speciesId, 'aktiv');
        $this->listings->pause($id, PauseActor::Verwaltung, 'Bitte Nachweis nachreichen', ListingStatus::Aktiv);

        $zeilen = $this->listings->forAdmin();

        self::assertCount(1, $zeilen);
        self::assertSame('Testnutzer', $zeilen[0]->sellerName);
        self::assertSame('Testart', $zeilen[0]->speciesName);
        self::assertTrue($zeilen[0]->isPaused());
        self::assertTrue($zeilen[0]->pausedByAdmin());
        self::assertSame('Bitte Nachweis nachreichen', $zeilen[0]->pausedReason);
    }

    public function testDieVerwaltungslisteLaesstSichFiltern(): void
    {
        $aktiv = $this->createListing($this->sellerId, $this->speciesId, 'aktiv');
        $this->createListing($this->sellerId, $this->speciesId, 'entwurf');
        $this->listings->pause($aktiv, PauseActor::Anbieter, null, ListingStatus::Aktiv);

        self::assertCount(2, $this->listings->forAdmin());
        self::assertCount(1, $this->listings->forAdmin(['nur_pausiert' => true]));
        self::assertCount(1, $this->listings->forAdmin(['status' => 'entwurf']));
        self::assertCount(2, $this->listings->forAdmin(['suche' => 'Testnutzer']));
        self::assertCount(0, $this->listings->forAdmin(['suche' => 'gibtsnicht']));
    }

    public function testDieVerwaltungslisteZaehltOffeneMeldungen(): void
    {
        $id = $this->createListing($this->sellerId, $this->speciesId, 'aktiv');
        $melder = $this->createUser('melder@example.tld');

        $this->database->execute(
            "INSERT INTO reports (reporter_id, target_type, target_id, reason, status, created_at)
             VALUES (:melder, 'listing', :id, 'betrug', 'offen', :now)",
            ['melder' => $melder, 'id' => $id, 'now' => Timestamp::now()],
        );

        self::assertSame(1, $this->listings->forAdmin()[0]->reportCount);
    }

    public function testEineStatusaenderungRaeumtDiePausenangabenAb(): void
    {
        $id = $this->createListing($this->sellerId, $this->speciesId, 'aktiv');
        $this->listings->pause($id, PauseActor::Verwaltung, 'Vorlaeufig angehalten', ListingStatus::Aktiv);

        // Die Moderation gibt frei. Bliebe "pausiert von der Verwaltung"
        // stehen, zeigte die Verwaltungsliste eine laufende Anzeige als
        // angehalten — und der Anbieter saehe eine Pause, die es nicht gibt.
        $this->listings->updateStatus($id, ListingStatus::Aktiv);

        self::assertNull($this->listings->pauseState($id));
        self::assertCount(0, $this->listings->forAdmin(['nur_pausiert' => true]));
    }

    public function testEineAbgelaufenePauseTauchtNichtMehrAlsPauseAuf(): void
    {
        $id = $this->createListing($this->sellerId, $this->speciesId, 'aktiv');
        $this->listings->pause($id, PauseActor::Anbieter, null, ListingStatus::Aktiv);

        $this->listings->updateStatus($id, ListingStatus::Abgelaufen);

        self::assertNull($this->listings->pauseState($id));
        self::assertSame(ListingStatus::Abgelaufen, $this->listings->findById($id)?->status);
    }

    public function testDerGrundWirdServerseitigGekuerzt(): void
    {
        $id = $this->createListing($this->sellerId, $this->speciesId, 'aktiv');
        $listing = $this->listings->findById($id);
        self::assertNotNull($listing);

        $admin = new User($this->createUser('admin@example.tld'), 'admin@example.tld', 'Admin', Role::Admin);

        // Das maxlength im Formular ist eine Bequemlichkeit, keine Schranke.
        $this->manager()->pauseByAdmin($listing, $admin, str_repeat('A', 5000));

        $zustand = $this->listings->pauseState($id);
        self::assertNotNull($zustand);
        self::assertNotNull($zustand->reason);
        self::assertSame(200, mb_strlen($zustand->reason));
    }

    public function testEinFremdesKontoDarfNichtPausieren(): void
    {
        $id = $this->createListing($this->sellerId, $this->speciesId, 'aktiv');
        $listing = $this->listings->findById($id);
        self::assertNotNull($listing);

        $fremder = new User(999, 'fremd@example.tld', 'Fremd');

        $this->expectException(ListingManagementException::class);
        $this->expectExceptionMessageMatches('/gehört dir nicht/');

        $this->manager()->pause($listing, $fremder);
    }

    public function testOhneAdminrolleKeineVerwaltungspause(): void
    {
        $id = $this->createListing($this->sellerId, $this->speciesId, 'aktiv');
        $listing = $this->listings->findById($id);
        self::assertNotNull($listing);

        $moderator = new User($this->createUser('mod@example.tld'), 'mod@example.tld', 'Mod', Role::Moderator);

        $this->expectException(ListingManagementException::class);
        $this->expectExceptionMessageMatches('/Berechtigung/');

        $this->manager()->pauseByAdmin($listing, $moderator, 'Grund');
    }

    /**
     * Der Dienst braucht Ablage, Index und Assistent — die haengen hier nicht
     * am Test. Geprueft werden die Regeln, die ohne sie greifen.
     */
    private function manager(): ListingManager
    {
        $verzeichnis = sys_get_temp_dir() . '/reptilienmarkt-manager-' . bin2hex(random_bytes(6));
        mkdir($verzeichnis, 0o775, true);

        return new ListingManager(
            $this->listings,
            new \Reptilienmarkt\Infra\Persistence\PdoListingMediaRepository($this->database),
            new \Reptilienmarkt\Infra\Persistence\PdoLegalDocumentRepository($this->database),
            $this->wizard(),
            new \Reptilienmarkt\Infra\Search\ListingIndexer(
                $this->database,
                new \Reptilienmarkt\Infra\Search\Fts5SearchIndex($this->database),
            ),
            new \Reptilienmarkt\Infra\Storage\PublicImageStorage($verzeichnis),
            new \Reptilienmarkt\Infra\Storage\PrivateStorage($verzeichnis),
            new \Reptilienmarkt\Infra\Persistence\PdoAuditLog($this->database),
        );
    }

    private function wizard(): \Reptilienmarkt\Domain\Listing\ListingWizard
    {
        $container = require \dirname(__DIR__, 3) . '/config/container.php';

        return $container->get(\Reptilienmarkt\Domain\Listing\ListingWizard::class);
    }
}
