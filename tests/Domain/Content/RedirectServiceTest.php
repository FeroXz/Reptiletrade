<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Content;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Content\ContentException;
use Reptilienmarkt\Domain\Content\ContentService;
use Reptilienmarkt\Domain\Content\ContentText;
use Reptilienmarkt\Domain\Content\ContentType;
use Reptilienmarkt\Domain\Content\MarkdownRenderer;
use Reptilienmarkt\Domain\Content\RedirectService;
use Reptilienmarkt\Domain\Privacy\RetentionPolicy;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoContentBlockRepository;
use Reptilienmarkt\Infra\Persistence\PdoContentEntryRepository;
use Reptilienmarkt\Infra\Persistence\PdoContentRevisionRepository;
use Reptilienmarkt\Infra\Persistence\PdoRedirectRepository;
use Reptilienmarkt\Infra\Search\Fts5ContentSearchIndex;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;

#[CoversClass(RedirectService::class)]
#[CoversClass(PdoRedirectRepository::class)]
final class RedirectServiceTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    private PdoRedirectRepository $repository;

    private int $autor = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-08T10:00:00+00:00'));
        $this->repository = new PdoRedirectRepository($this->database);
        $this->autor = $this->createUser('redaktion@example.tld');
    }

    public function testEineWeiterleitungWirdAngelegtUndAufgeloest(): void
    {
        $dienst = $this->service();
        $dienst->create('/alt/', '/neu/', $this->autor);

        $treffer = $dienst->resolve('/alt/');

        self::assertNotNull($treffer);
        self::assertSame('/neu/', $treffer->toPath);
        self::assertSame(301, $treffer->code);
    }

    public function testDerAusgangspfadWirdNormalisiert(): void
    {
        $dienst = $this->service();
        $dienst->create('alt', '/neu/', $this->autor);

        // "alt", "/alt" und "/alt/" sind derselbe Ausgangspunkt.
        self::assertNotNull($dienst->resolve('/alt/'));
        self::assertNotNull($dienst->resolve('alt'));
        self::assertNotNull($dienst->resolve('/alt'));
    }

    public function testEinZielOhneSchraegstrichWirdAbgewiesen(): void
    {
        // Beim Ziel wird nicht geraten: "example.tld" koennte ein fremder Host
        // sein oder ein interner Pfad — und die falsche Annahme fuehrt
        // Besucher entweder ins Leere oder auf eine fremde Seite.
        $this->expectException(ContentException::class);

        $this->service()->create('/alt/', 'neu', $this->autor);
    }

    public function testTrefferWerdenGezaehlt(): void
    {
        $dienst = $this->service();
        $dienst->create('/alt/', '/neu/', $this->autor);

        $dienst->resolve('/alt/');
        $dienst->resolve('/alt/');

        $gespeichert = $this->repository->findByPath('/alt/');

        self::assertNotNull($gespeichert);
        self::assertSame(2, $gespeichert->hits);
        self::assertNotNull($gespeichert->lastUsedAt);
    }

    public function testKettenWerdenBeimAnlegenAufgeloest(): void
    {
        $dienst = $this->service();

        $dienst->create('/a/', '/b/', $this->autor);
        $dienst->create('/b/', '/c/', $this->autor);

        // /a/ zeigt jetzt direkt auf /c/ — sonst schickt jede Umbenennung den
        // Besucher einen Sprung weiter durch die Geschichte der Seite.
        $a = $this->repository->findByPath('/a/');

        self::assertNotNull($a);
        self::assertSame('/c/', $a->toPath);
    }

    public function testEinZielDasSelbstWeiterleitetWirdUebersprungen(): void
    {
        $dienst = $this->service();

        $dienst->create('/b/', '/c/', $this->autor);
        $neu = $dienst->create('/a/', '/b/', $this->autor);

        self::assertSame('/c/', $neu->toPath);
    }

    public function testEineSchleifeWirdAbgewiesen(): void
    {
        $dienst = $this->service();
        $dienst->create('/a/', '/b/', $this->autor);

        $this->expectException(ContentException::class);
        $this->expectExceptionMessageMatches('/Kreis/');

        $dienst->create('/b/', '/a/', $this->autor);
    }

    public function testEineWeiterleitungAufSichSelbstWirdAbgewiesen(): void
    {
        $this->expectException(ContentException::class);

        $this->service()->create('/a/', '/a/', $this->autor);
    }

    public function testEinJavascriptZielWirdAbgewiesen(): void
    {
        // Eine offene Weiterleitung mit Code-Ausfuehrung waere der Angriff,
        // den der Markdown-Renderer an anderer Stelle schon abfaengt.
        $this->expectException(ContentException::class);

        $this->service()->create('/a/', 'javascript:alert(1)', $this->autor);
    }

    public function testEineFremdeAdresseIstErlaubt(): void
    {
        $neu = $this->service()->create('/partner/', 'https://www.example.tld/seite', $this->autor);

        self::assertSame('https://www.example.tld/seite', $neu->toPath);
    }

    public function testEinUnbekannterCodeWirdAbgewiesen(): void
    {
        $this->expectException(ContentException::class);

        $this->service()->create('/a/', '/b/', $this->autor, code: 307);
    }

    public function testDasAnlegenLandetImAuditTrail(): void
    {
        $neu = $this->service()->create('/alt/', '/neu/', $this->autor);

        $aktionen = array_column(
            (new PdoAuditLog($this->database))->forEntity('content_redirect', $neu->id ?? 0),
            'action',
        );

        self::assertContains('redirect.created', $aktionen);
    }

    public function testSchleifenWerdenGemeldet(): void
    {
        // Nur von Hand in die Datenbank geschrieben moeglich — genau dafuer
        // sucht bin/doctor.php danach.
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->database->execute(
            "INSERT INTO content_redirects (from_path, to_path, created_at) VALUES ('/a/', '/b/', :now)",
            ['now' => $now],
        );
        $this->database->execute(
            "INSERT INTO content_redirects (from_path, to_path, created_at) VALUES ('/b/', '/a/', :now)",
            ['now' => $now],
        );

        self::assertCount(2, $this->service()->loops());
    }

    // ------------------------------------------------------------- Auto-301

    public function testEineSlugAenderungLegtDieWeiterleitungSelbstAn(): void
    {
        $inhalte = $this->contentService();
        $seite = $inhalte->create(ContentType::Seite, 'Haltung', null, null, $this->autor);
        $id = $seite->id ?? 0;

        $inhalte->publish($id, $this->autor);
        $inhalte->updateHeader($id, ['slug' => 'haltung-im-terrarium'], $this->autor);

        $weiterleitung = $this->repository->findByPath('/haltung/');

        self::assertNotNull($weiterleitung);
        self::assertSame('/haltung-im-terrarium/', $weiterleitung->toPath);
        self::assertSame(301, $weiterleitung->code);
        self::assertTrue($weiterleitung->isAuto);
    }

    public function testEinEntwurfBekommtKeineWeiterleitung(): void
    {
        // Ein Entwurf hatte nie eine Adresse, die jemand kennt — eine
        // Weiterleitung darauf waere ein Eintrag ohne Anlass.
        $inhalte = $this->contentService();
        $seite = $inhalte->create(ContentType::Seite, 'Haltung', null, null, $this->autor);

        $inhalte->updateHeader($seite->id ?? 0, ['slug' => 'anders'], $this->autor);

        self::assertNull($this->repository->findByPath('/haltung/'));
    }

    public function testUnterseitenBekommenEigeneWeiterleitungen(): void
    {
        $inhalte = $this->contentService();
        $eltern = $inhalte->create(ContentType::Seite, 'Haltung', null, null, $this->autor);
        $kind = $inhalte->create(ContentType::Seite, 'Terrarium', null, $eltern->id, $this->autor);

        $inhalte->publish($eltern->id ?? 0, $this->autor);
        $inhalte->publish($kind->id ?? 0, $this->autor);

        $inhalte->updateHeader($eltern->id ?? 0, ['slug' => 'pflege'], $this->autor);

        $fuerKind = $this->repository->findByPath('/haltung/terrarium/');

        self::assertNotNull($fuerKind);
        self::assertSame('/pflege/terrarium/', $fuerKind->toPath);
    }

    public function testZweimalUmbenennenErgibtKeineKette(): void
    {
        $inhalte = $this->contentService();
        $seite = $inhalte->create(ContentType::Seite, 'Eins', null, null, $this->autor);
        $id = $seite->id ?? 0;

        $inhalte->publish($id, $this->autor);
        $inhalte->updateHeader($id, ['slug' => 'zwei'], $this->autor);
        $inhalte->updateHeader($id, ['slug' => 'drei'], $this->autor);

        $erste = $this->repository->findByPath('/eins/');

        self::assertNotNull($erste);
        self::assertSame('/drei/', $erste->toPath, 'Der erste Pfad muss direkt aufs Ziel zeigen.');
    }

    public function testZurueckbenennenLoestDieAlteWeiterleitungAb(): void
    {
        $inhalte = $this->contentService();
        $seite = $inhalte->create(ContentType::Seite, 'Eins', null, null, $this->autor);
        $id = $seite->id ?? 0;

        $inhalte->publish($id, $this->autor);
        $inhalte->updateHeader($id, ['slug' => 'zwei'], $this->autor);
        $inhalte->updateHeader($id, ['slug' => 'eins'], $this->autor);

        // /eins/ ist wieder eine echte Seite — die Weiterleitung von dort weg
        // ist ueberholt und verschwindet, statt einen Kreis zu bilden.
        self::assertNull($this->repository->findByPath('/eins/'));

        $zurueck = $this->repository->findByPath('/zwei/');
        self::assertNotNull($zurueck);
        self::assertSame('/eins/', $zurueck->toPath);

        self::assertSame([], $this->service()->loops());
    }

    private function service(): RedirectService
    {
        return new RedirectService($this->repository, new PdoAuditLog($this->database), $this->clock);
    }

    private function contentService(): ContentService
    {
        return new ContentService(
            new PdoContentEntryRepository($this->database),
            new PdoContentBlockRepository($this->database),
            new PdoContentRevisionRepository($this->database),
            new Fts5ContentSearchIndex($this->database),
            new ContentText(new MarkdownRenderer()),
            $this->service(),
            new RetentionPolicy(require \dirname(__DIR__, 3) . '/config/aufbewahrung.php', $this->clock),
            new PdoAuditLog($this->database),
            $this->clock,
        );
    }
}
