<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Infra\Persistence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Infra\Persistence\PdoLegalTextRepository;
use Reptilienmarkt\Infra\Persistence\PdoSettings;
use Reptilienmarkt\Legal\LegalText;
use Reptilienmarkt\Tests\DatabaseTestCase;

#[CoversClass(PdoLegalTextRepository::class)]
#[CoversClass(PdoSettings::class)]
final class PdoLegalTextRepositoryTest extends DatabaseTestCase
{
    private PdoLegalTextRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new PdoLegalTextRepository($this->database);
    }

    private function text(string $body = 'Erste Fassung', string $jurisdiction = 'DE'): LegalText
    {
        return new LegalText(null, 'legal.anhang_a', 'Anhang A', $body, $jurisdiction, 'Art. 8 EG-VO 338/97');
    }

    public function testSpeichertUndFindet(): void
    {
        $this->repository->save($this->text());

        $loaded = $this->repository->find('legal.anhang_a');

        self::assertNotNull($loaded);
        self::assertSame('Anhang A', $loaded->title);
        self::assertSame('Art. 8 EG-VO 338/97', $loaded->sourceReference);
        self::assertNull($loaded->lastReviewedAt);
    }

    public function testRechtsordnungenWerdenGetrenntGefuehrt(): void
    {
        $this->repository->save($this->text('Fassung DE', 'DE'));
        $this->repository->save($this->text('Fassung AT', 'AT'));

        self::assertSame('Fassung DE', $this->repository->find('legal.anhang_a', 'DE')?->body);
        self::assertSame('Fassung AT', $this->repository->find('legal.anhang_a', 'AT')?->body);
        self::assertNull($this->repository->find('legal.anhang_a', 'CH'));
        self::assertCount(2, $this->repository->all());
    }

    public function testSaveUeberschreibt(): void
    {
        $this->repository->save($this->text('Erste Fassung'));
        $this->repository->save($this->text('Zweite Fassung'));

        self::assertCount(1, $this->repository->all());
        self::assertSame('Zweite Fassung', $this->repository->find('legal.anhang_a')?->body);
    }

    /**
     * Der Seed darf redaktionelle Aenderungen niemals ueberschreiben.
     */
    public function testInsertIfMissingLaesstBestehendeTexteInRuhe(): void
    {
        self::assertTrue($this->repository->insertIfMissing($this->text('Redaktionelle Fassung')));
        self::assertFalse($this->repository->insertIfMissing($this->text('Seed-Fassung')));

        self::assertSame('Redaktionelle Fassung', $this->repository->find('legal.anhang_a')?->body);
        self::assertCount(1, $this->repository->all());
    }

    public function testFreigabeVermerktZeitpunktUndPruefer(): void
    {
        $userId = $this->createUser('moderation@example.tld');
        $this->repository->save($this->text());

        $this->repository->markReviewed('legal.anhang_a', 'DE', $userId);

        $loaded = $this->repository->find('legal.anhang_a');
        self::assertNotNull($loaded);
        self::assertInstanceOf(DateTimeImmutable::class, $loaded->lastReviewedAt);

        $reviewer = $this->database->scalar('SELECT last_reviewed_by FROM legal_texts WHERE text_key = :key', [
            'key' => 'legal.anhang_a',
        ]);
        self::assertSame($userId, (int) $reviewer);
    }

    public function testFreigabeEinesUnbekanntenSchluesselsIstFolgenlos(): void
    {
        $this->repository->markReviewed('legal.gibt.es.nicht', 'DE', 1);

        self::assertSame([], $this->repository->all());
    }

    public function testEinstellungenLesenUndSchreiben(): void
    {
        $settings = new PdoSettings($this->database);

        self::assertFalse($settings->has('legal.gefahrtier_enforcement'));
        self::assertTrue($settings->bool('legal.gefahrtier_enforcement', true), 'Standardwert greift.');

        $settings->set('legal.gefahrtier_enforcement', false, 'Gefahrtierregel global');
        $settings->set('legal.review_max_age_months', 6);
        $settings->set('legal.regionen', ['DE' => 'Bayern']);

        self::assertTrue($settings->has('legal.gefahrtier_enforcement'));
        self::assertFalse($settings->bool('legal.gefahrtier_enforcement', true));
        self::assertSame(6, $settings->int('legal.review_max_age_months', 12));
        self::assertSame(['DE' => 'Bayern'], $settings->json('legal.regionen'));
    }

    public function testEinstellungWirdUeberschriebenNichtDupliziert(): void
    {
        $settings = new PdoSettings($this->database);
        $settings->set('legal.review_max_age_months', 12);
        $settings->set('legal.review_max_age_months', 24);

        self::assertSame(24, $settings->int('legal.review_max_age_months'));
        self::assertSame(1, (int) $this->database->scalar('SELECT COUNT(*) FROM settings'));
    }
}
