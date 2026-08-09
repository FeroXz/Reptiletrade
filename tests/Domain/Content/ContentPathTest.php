<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Content;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Domain\Content\ContentException;
use Reptilienmarkt\Domain\Content\ContentPath;
use Reptilienmarkt\Domain\Content\ContentType;
use Reptilienmarkt\Domain\Content\ReservedPaths;

final class ContentPathTest extends TestCase
{
    #[DataProvider('pfade')]
    public function testNormalisiert(string $eingabe, string $erwartet): void
    {
        self::assertSame($erwartet, ContentPath::normalize($eingabe));
    }

    /**
     * @return list<array{string, string}>
     */
    public static function pfade(): array
    {
        return [
            ['haltung', '/haltung/'],
            ['/haltung', '/haltung/'],
            ['/haltung/', '/haltung/'],
            ['//haltung//terrarium//', '/haltung/terrarium/'],
            ['', '/'],
            ['/', '/'],
        ];
    }

    public function testSeitenpfadHaengtAmElternteil(): void
    {
        self::assertSame('/haltung/', ContentPath::forPage('haltung'));
        self::assertSame('/haltung/terrarium/', ContentPath::forPage('terrarium', '/haltung/'));
    }

    public function testBeitragspfadTraegtDasJahr(): void
    {
        $angelegt = new DateTimeImmutable('2025-11-02T08:00:00Z');
        $veroeffentlicht = new DateTimeImmutable('2026-01-04T08:00:00Z');

        self::assertSame('/news/2025/erste/', ContentPath::forPost('erste', null, $angelegt));
        self::assertSame('/news/2026/erste/', ContentPath::forPost('erste', $veroeffentlicht, $angelegt));
    }

    public function testErstesSegment(): void
    {
        self::assertSame('haltung', ContentPath::firstSegment('/haltung/terrarium/'));
        self::assertSame('', ContentPath::firstSegment('/'));
        self::assertSame(['haltung', 'terrarium'], ContentPath::segments('/haltung/terrarium/'));
    }

    #[DataProvider('belegtePfade')]
    public function testBelegtePfadeWerdenAbgewiesen(string $pfad): void
    {
        self::assertTrue(ReservedPaths::isReserved($pfad));
    }

    /**
     * @return list<array{string}>
     */
    public static function belegtePfade(): array
    {
        return [['/markt/'], ['/impressum/'], ['/admin/irgendwas/'], ['/api/v1/'], ['/'], ['/Impressum/']];
    }

    public function testFreiePfadeBleibenFrei(): void
    {
        self::assertFalse(ReservedPaths::isReserved('/haltung/'));
        self::assertFalse(ReservedPaths::isReserved('/ueber-uns/team/'));
    }

    public function testDieMeldungNenntDenKonflikt(): void
    {
        try {
            ReservedPaths::guard('/impressum/', ContentType::Seite);
            self::fail('Der belegte Pfad haette abgewiesen werden muessen.');
        } catch (ContentException $exception) {
            self::assertStringContainsString('/impressum/', $exception->getMessage());
        }
    }

    public function testBeitraegeDuerfenUnterNewsLiegen(): void
    {
        // /news/ steht auf der Liste, weil die Uebersicht dort liegt — die
        // Beitraege selbst muessen aber genau dorthin. Eine Seite mit
        // demselben Pfad bleibt abgewiesen.
        ReservedPaths::guard('/news/2026/erste/', ContentType::Beitrag);

        $this->expectException(ContentException::class);

        ReservedPaths::guard('/news/2026/erste/', ContentType::Seite);
    }
}
