<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Infra\Persistence;

use DateTimeImmutable;
use PDOException;
use Reptilienmarkt\Domain\Content\BlockType;
use Reptilienmarkt\Domain\Content\ContentBlock;
use Reptilienmarkt\Domain\Content\ContentEntry;
use Reptilienmarkt\Domain\Content\ContentPath;
use Reptilienmarkt\Domain\Content\ContentStatus;
use Reptilienmarkt\Domain\Content\ContentTemplate;
use Reptilienmarkt\Domain\Content\ContentType;
use Reptilienmarkt\Infra\Persistence\PdoContentBlockRepository;
use Reptilienmarkt\Infra\Persistence\PdoContentEntryRepository;
use Reptilienmarkt\Tests\DatabaseTestCase;

final class PdoContentEntryRepositoryTest extends DatabaseTestCase
{
    private PdoContentEntryRepository $entries;

    private PdoContentBlockRepository $blocks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entries = new PdoContentEntryRepository($this->database);
        $this->blocks = new PdoContentBlockRepository($this->database);
    }

    public function testSpeichertUndLiestEineSeite(): void
    {
        $autor = $this->createUser('redaktion@example.tld');
        $id = $this->entries->save($this->page('haltung', 'Haltung', authorId: $autor));

        $gelesen = $this->entries->findById($id);

        self::assertNotNull($gelesen);
        self::assertSame('/haltung/', $gelesen->path);
        self::assertSame(ContentType::Seite, $gelesen->type);
        self::assertSame(ContentStatus::Entwurf, $gelesen->status);
        self::assertSame(ContentTemplate::Standard, $gelesen->template);
        self::assertSame(ContentEntry::DEFAULT_LOCALE, $gelesen->locale);
        self::assertSame($autor, $gelesen->authorId);
        self::assertFalse($gelesen->noindex);
    }

    public function testFindetUeberDenPfad(): void
    {
        $this->entries->save($this->page('haltung', 'Haltung'));

        self::assertNotNull($this->entries->findByPath('/haltung/'));
        self::assertNull($this->entries->findByPath('/gibt-es-nicht/'));
    }

    public function testFindetUeberSlugAuchOhneElternteil(): void
    {
        // NULL = NULL ist in SQL niemals wahr; genau die Wurzelseiten haetten
        // sonst nie einen Treffer.
        $this->entries->save($this->page('haltung', 'Haltung'));

        self::assertNotNull($this->entries->findBySlug(ContentType::Seite, null, 'haltung'));
        self::assertNull($this->entries->findBySlug(ContentType::Seite, null, 'anderes'));
    }

    public function testPfadIstEindeutig(): void
    {
        $this->entries->save($this->page('haltung', 'Haltung'));

        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/UNIQUE/i');

        $this->entries->save($this->page('haltung', 'Zweite Haltung'));
    }

    public function testKindSeiteErbtDenPfadDesElternteils(): void
    {
        $elternId = $this->entries->save($this->page('haltung', 'Haltung'));
        $eltern = $this->entries->findById($elternId);
        self::assertNotNull($eltern);

        $kindId = $this->entries->save($this->page(
            'terrarium',
            'Terrarium',
            parentId: $elternId,
            path: ContentPath::forPage('terrarium', $eltern->path),
        ));

        $kind = $this->entries->findById($kindId);
        self::assertNotNull($kind);
        self::assertSame('/haltung/terrarium/', $kind->path);

        $kinder = $this->entries->children($elternId);
        self::assertCount(1, $kinder);
        self::assertSame($kindId, $kinder[0]->id);
    }

    public function testNachfahrenLiefertDieGanzeTiefe(): void
    {
        $a = $this->entries->save($this->page('a', 'A'));
        $b = $this->entries->save($this->page('b', 'B', parentId: $a, path: '/a/b/'));
        $c = $this->entries->save($this->page('c', 'C', parentId: $b, path: '/a/b/c/'));

        $pfade = array_map(static fn(ContentEntry $e): string => $e->path, $this->entries->descendants($a));

        self::assertSame(['/a/b/', '/a/b/c/'], $pfade);
        self::assertNotSame(0, $c);
    }

    public function testBeitragBrauchtKeinElternteil(): void
    {
        $id = $this->entries->save(new ContentEntry(
            id: null,
            type: ContentType::Beitrag,
            slug: 'erste-nachricht',
            path: '/news/2026/erste-nachricht/',
            title: 'Erste Nachricht',
            template: ContentTemplate::Beitrag,
            createdAt: new DateTimeImmutable('2026-03-01T10:00:00Z'),
            updatedAt: new DateTimeImmutable('2026-03-01T10:00:00Z'),
        ));

        $beitrag = $this->entries->findById($id);
        self::assertNotNull($beitrag);
        self::assertSame(ContentType::Beitrag, $beitrag->type);
    }

    public function testBeitragMitElternteilWirdAbgewiesen(): void
    {
        $seite = $this->entries->save($this->page('haltung', 'Haltung'));

        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/CHECK/i');

        $this->entries->save(new ContentEntry(
            id: null,
            type: ContentType::Beitrag,
            slug: 'falsch',
            path: '/news/2026/falsch/',
            title: 'Falsch',
            parentId: $seite,
            createdAt: new DateTimeImmutable('2026-03-01T10:00:00Z'),
            updatedAt: new DateTimeImmutable('2026-03-01T10:00:00Z'),
        ));
    }

    public function testGeplantOhneTerminWirdAbgewiesen(): void
    {
        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/CHECK/i');

        $this->entries->save($this->page('geplant', 'Geplant', status: ContentStatus::Geplant));
    }

    public function testFaelligLiefertNurUeberfaelligeEintraege(): void
    {
        $jetzt = new DateTimeImmutable('2026-05-01T12:00:00Z');

        $faellig = $this->entries->save($this->page(
            'frueher',
            'Früher',
            status: ContentStatus::Geplant,
            publishedAt: new DateTimeImmutable('2026-05-01T11:00:00Z'),
        ));
        $this->entries->save($this->page(
            'spaeter',
            'Später',
            status: ContentStatus::Geplant,
            publishedAt: new DateTimeImmutable('2026-05-01T13:00:00Z'),
        ));

        $due = $this->entries->due($jetzt);

        self::assertCount(1, $due);
        self::assertSame($faellig, $due[0]->id);
    }

    public function testVeroeffentlichteWerdenNachDatumSortiert(): void
    {
        $alt = $this->entries->save($this->page(
            'alt',
            'Alt',
            status: ContentStatus::Veroeffentlicht,
            publishedAt: new DateTimeImmutable('2026-01-01T09:00:00Z'),
        ));
        $neu = $this->entries->save($this->page(
            'neu',
            'Neu',
            status: ContentStatus::Veroeffentlicht,
            publishedAt: new DateTimeImmutable('2026-02-01T09:00:00Z'),
        ));

        $liste = $this->entries->published(ContentType::Seite, 10);

        self::assertSame([$neu, $alt], array_map(static fn(ContentEntry $e): ?int => $e->id, $liste));
        self::assertSame(2, $this->entries->countPublished(ContentType::Seite));
    }

    public function testSucheFiltertNachStatusUndText(): void
    {
        $this->entries->save($this->page('haltung', 'Haltung im Terrarium'));
        $this->entries->save($this->page(
            'futter',
            'Futtertiere',
            status: ContentStatus::Veroeffentlicht,
            publishedAt: new DateTimeImmutable('2026-02-01T09:00:00Z'),
        ));

        self::assertCount(1, $this->entries->search(null, ContentStatus::Entwurf, null));
        self::assertCount(1, $this->entries->search(null, null, 'Futter'));
        self::assertCount(2, $this->entries->search(ContentType::Seite, null, null));
    }

    public function testBloeckeWerdenLueckenlosNummeriert(): void
    {
        $id = $this->entries->save($this->page('haltung', 'Haltung'));

        $this->blocks->replaceAll($id, [
            new ContentBlock(null, BlockType::Text, 40, ['text' => 'Erster Absatz']),
            new ContentBlock(null, BlockType::Trenner, 90, []),
            new ContentBlock(null, BlockType::Zitat, 7, ['zitat' => 'Ein Zitat', 'quelle' => 'Jemand']),
        ]);

        $gelesen = $this->blocks->forEntry($id);

        self::assertSame([0, 1, 2], array_map(static fn(ContentBlock $b): int => $b->position, $gelesen));
        self::assertSame(BlockType::Text, $gelesen[0]->type);
        self::assertSame('Erster Absatz', $gelesen[0]->string('text'));
        self::assertSame('Jemand', $gelesen[2]->string('quelle'));
    }

    public function testBloeckeWerdenBeimErsetzenVollstaendigAusgetauscht(): void
    {
        $id = $this->entries->save($this->page('haltung', 'Haltung'));

        $this->blocks->replaceAll($id, [new ContentBlock(null, BlockType::Text, 0, ['text' => 'alt'])]);
        $this->blocks->replaceAll($id, [new ContentBlock(null, BlockType::Hinweis, 0, ['text' => 'neu'])]);

        $gelesen = $this->blocks->forEntry($id);

        self::assertCount(1, $gelesen);
        self::assertSame(BlockType::Hinweis, $gelesen[0]->type);
    }

    public function testBloeckeVerschwindenMitDemEintrag(): void
    {
        $id = $this->entries->save($this->page('haltung', 'Haltung'));
        $this->blocks->replaceAll($id, [new ContentBlock(null, BlockType::Text, 0, ['text' => 'x'])]);

        $this->entries->delete($id);

        self::assertSame(0, (int) $this->database->scalar('SELECT COUNT(*) FROM content_blocks'));
    }

    public function testUnbekannterBlocktypWirdAbgewiesen(): void
    {
        $id = $this->entries->save($this->page('haltung', 'Haltung'));

        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/CHECK/i');

        $this->database->execute(
            "INSERT INTO content_blocks (entry_id, position, type, data_json) VALUES (:id, 0, 'roh-html', '{}')",
            ['id' => $id],
        );
    }

    public function testUngueltigesJsonWirdAbgewiesen(): void
    {
        $id = $this->entries->save($this->page('haltung', 'Haltung'));

        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/CHECK/i');

        $this->database->execute(
            "INSERT INTO content_blocks (entry_id, position, type, data_json) VALUES (:id, 0, 'text', 'kein json')",
            ['id' => $id],
        );
    }

    public function testEineSeiteMitKindernLaesstSichNichtLoeschen(): void
    {
        $eltern = $this->entries->save($this->page('haltung', 'Haltung'));
        $this->entries->save($this->page('terrarium', 'Terrarium', parentId: $eltern, path: '/haltung/terrarium/'));

        // ON DELETE RESTRICT: Sonst haengt das Kind an einer Elternseite, die es
        // nicht mehr gibt, und sein Pfad zeigt ins Leere.
        $this->expectException(PDOException::class);

        $this->entries->delete($eltern);
    }

    private function page(
        string $slug,
        string $title,
        ContentStatus $status = ContentStatus::Entwurf,
        ?int $parentId = null,
        ?string $path = null,
        ?DateTimeImmutable $publishedAt = null,
        ?int $authorId = null,
    ): ContentEntry {
        $moment = new DateTimeImmutable('2026-01-15T08:00:00Z');

        return new ContentEntry(
            id: null,
            type: ContentType::Seite,
            slug: $slug,
            path: $path ?? ContentPath::forPage($slug),
            title: $title,
            status: $status,
            parentId: $parentId,
            publishedAt: $publishedAt,
            createdAt: $moment,
            updatedAt: $moment,
            authorId: $authorId,
        );
    }
}
