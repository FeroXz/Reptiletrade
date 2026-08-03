<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Listing\LegalDocType;
use Reptilienmarkt\Domain\Listing\LegalDocumentRecord;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Http\Controller\LegalDocumentController;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Session\NotAuthenticatedException;
use Reptilienmarkt\Http\Session\Viewer;
use Reptilienmarkt\Infra\Persistence\PdoLegalDocumentRepository;
use Reptilienmarkt\Infra\Persistence\PdoListingRepository;
use Reptilienmarkt\Infra\Storage\PrivateStorage;
use Reptilienmarkt\Infra\Storage\StorageException;
use Reptilienmarkt\Tests\DatabaseTestCase;

/**
 * Akzeptanzkriterium: Rechtsdokumente sind ohne Anmeldung per Direkt-URL nicht
 * abrufbar.
 */
#[CoversClass(LegalDocumentController::class)]
#[CoversClass(PrivateStorage::class)]
final class LegalDocumentAccessTest extends DatabaseTestCase
{
    private string $storageDir;

    private PrivateStorage $storage;

    private PdoLegalDocumentRepository $documents;

    private PdoListingRepository $listings;

    private int $ownerId;

    private int $listingId;

    private int $documentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageDir = sys_get_temp_dir() . '/rm-privat-' . bin2hex(random_bytes(6));
        mkdir($this->storageDir, 0o770, true);

        $this->storage = new PrivateStorage($this->storageDir);
        $this->documents = new PdoLegalDocumentRepository($this->database);
        $this->listings = new PdoListingRepository($this->database);

        $this->ownerId = $this->createUser('eigentuemer@example.tld');
        $speciesId = $this->createSpecies();
        $this->listingId = $this->createListing($this->ownerId, $speciesId, 'entwurf');

        $quelle = $this->storageDir . '/quelle.pdf';
        file_put_contents($quelle, "%PDF-1.4\n% Bescheinigung\n");
        $stored = $this->storage->store($quelle, 'bescheinigung.pdf', 'listing-' . $this->listingId);

        $this->documentId = $this->documents->save(new LegalDocumentRecord(
            null,
            $this->listingId,
            LegalDocType::EuBescheinigung,
            'DE-BW-2026-000123',
            'Regierungspräsidium',
            null,
            $stored->relativePath,
            $stored->originalFilename,
            $stored->mimeType,
            $stored->byteSize,
        ));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storageDir);

        parent::tearDown();
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }

        rmdir($path);
    }

    private function controller(?User $viewer): LegalDocumentController
    {
        return new LegalDocumentController(
            $this->documents,
            $this->listings,
            // Der Zugriffsschutz laesst sich so pruefen, ohne eine Sitzung aufzubauen.
            new class ($viewer) implements Viewer {
                public function __construct(private readonly ?User $viewer) {}

                public function get(): ?User
                {
                    return $this->viewer;
                }

                public function require(): User
                {
                    return $this->viewer ?? throw new NotAuthenticatedException();
                }

                public function isAuthenticated(): bool
                {
                    return $this->viewer !== null;
                }
            },
            $this->storage,
        );
    }

    private function request(int $documentId): Request
    {
        return (new Request('GET', '/nachweis/' . $documentId))->withAttributes(['id' => (string) $documentId]);
    }

    private function user(int $id, Role $role = Role::Seller): User
    {
        return new User($id, 'nutzer' . $id . '@example.tld', 'Nutzer ' . $id, $role);
    }

    public function testOhneAnmeldungKeinZugriff(): void
    {
        $this->expectException(NotAuthenticatedException::class);

        $this->controller(null)->download($this->request($this->documentId));
    }

    public function testFremderNutzerBekommtKeinenZugriff(): void
    {
        $fremd = $this->createUser('fremd@example.tld');

        $this->expectException(HttpException::class);
        // 404 statt 403: Ein 403 wuerde die Existenz des Dokuments bestaetigen.
        $this->expectExceptionMessage('Nachweis nicht gefunden.');

        $this->controller($this->user($fremd))->download($this->request($this->documentId));
    }

    public function testEigentuemerDarfHerunterladen(): void
    {
        $response = $this->controller($this->user($this->ownerId))->download($this->request($this->documentId));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('%PDF', $response->body);
        self::assertSame('application/pdf', $response->headers['content-type']);
    }

    public function testModerationDarfHerunterladen(): void
    {
        $moderator = $this->createUser('moderation@example.tld');

        $response = $this->controller($this->user($moderator, Role::Moderator))->download($this->request($this->documentId));

        self::assertSame(200, $response->status);
    }

    /**
     * Ein im Browser gerendertes Dokument koennte aktive Inhalte mitbringen.
     */
    public function testDokumentWirdImmerAlsAnhangAusgeliefert(): void
    {
        $response = $this->controller($this->user($this->ownerId))->download($this->request($this->documentId));

        self::assertStringStartsWith('attachment;', $response->headers['content-disposition']);
        self::assertSame('private, no-store', $response->headers['cache-control']);
        self::assertStringContainsString('noindex', $response->headers['x-robots-tag']);
    }

    public function testUnbekanntesDokumentErgibtVierNullVier(): void
    {
        $this->expectException(HttpException::class);

        $this->controller($this->user($this->ownerId))->download($this->request(999999));
    }

    /**
     * Die Ablage liegt ausserhalb des Webroots — es gibt keine Datei unter
     * public/, ueber die ein Nachweis erreichbar waere.
     */
    public function testAblageLiegtAusserhalbDesWebroots(): void
    {
        $document = $this->documents->find($this->documentId);
        self::assertNotNull($document);

        $absolut = $this->storage->absolutePath((string) $document->privatePath);

        self::assertFileExists($absolut);
        self::assertStringNotContainsString('/public/', $absolut);

        $webroot = \dirname(__DIR__, 2) . '/public';
        self::assertStringStartsNotWith($webroot, $absolut);
    }

    /**
     * Der Pfad aus der Datenbank wird wie eine Nutzereingabe behandelt.
     */
    public function testPfadwechselWirdAbgewiesen(): void
    {
        foreach (['../../etc/passwd', '/etc/passwd', 'nachweise/../../../etc/passwd', "nachweise/\0boese"] as $pfad) {
            $abgewiesen = false;

            try {
                $this->storage->absolutePath($pfad);
            } catch (StorageException) {
                $abgewiesen = true;
            }

            self::assertTrue($abgewiesen, \sprintf('Der Pfad "%s" haette abgewiesen werden muessen.', $pfad));
        }
    }

    public function testGespeicherterPfadIstRelativUndUnauffaellig(): void
    {
        $document = $this->documents->find($this->documentId);

        self::assertNotNull($document);
        self::assertMatchesRegularExpression('#^listing-\d+/\d{4}/\d{2}/[0-9a-f]{32}\.pdf$#', (string) $document->privatePath);
        // Der Originalname taucht im Pfad nicht auf.
        self::assertStringNotContainsString('bescheinigung', (string) $document->privatePath);
    }
}
