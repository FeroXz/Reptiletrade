<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Search;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Job\Job;
use Reptilienmarkt\Domain\Listing\CbStatus;
use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Notification\NotificationChannel;
use Reptilienmarkt\Domain\Search\AlertFrequency;
use Reptilienmarkt\Domain\Search\MorphFilter;
use Reptilienmarkt\Domain\Search\SavedSearchException;
use Reptilienmarkt\Domain\Search\SavedSearchService;
use Reptilienmarkt\Domain\Search\SearchCriteria;
use Reptilienmarkt\Domain\Search\SearchCriteriaCodec;
use Reptilienmarkt\Domain\Search\SortOrder;
use Reptilienmarkt\Infra\Job\Handler\SavedSearchAlertHandler;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoSavedSearchRepository;
use Reptilienmarkt\Infra\Persistence\PdoUserRepository;
use Reptilienmarkt\Infra\Search\ListingQuery;
use Reptilienmarkt\Infra\Search\PdoListingSearchRepository;
use Reptilienmarkt\Support\Timestamp;
use Reptilienmarkt\Support\Translator;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\CollectingMailer;
use Reptilienmarkt\Tests\Support\FrozenClock;

#[CoversClass(SavedSearchService::class)]
#[CoversClass(SearchCriteriaCodec::class)]
#[CoversClass(PdoSavedSearchRepository::class)]
#[CoversClass(SavedSearchAlertHandler::class)]
final class SavedSearchTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    private CollectingMailer $mailer;

    private PdoSavedSearchRepository $repository;

    private int $userId;

    private int $speciesId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-09T07:00:00Z'));
        $this->mailer = new CollectingMailer();
        $this->repository = new PdoSavedSearchRepository($this->database);

        $this->userId = $this->createUser('sucher@example.tld');
        $this->speciesId = $this->createSpecies();
    }

    // ------------------------------------------------------------- Codec

    public function testDieKriterienUeberlebenDenRundweg(): void
    {
        $kriterien = new SearchCriteria(
            query: 'bartagame',
            speciesId: 7,
            morphs: [new MorphFilter(3), new MorphFilter(5)],
            sexes: [Sex::Weiblich],
            types: [ListingType::Verkauf],
            cbStatuses: [CbStatus::Nachzucht],
            priceMinCents: 5000,
            ageMaxMonths: 24,
            withImageOnly: true,
            admin1: 'Bayern',
            sort: SortOrder::PreisAufsteigend,
            page: 5,
        );

        $zurueck = SearchCriteriaCodec::decode(SearchCriteriaCodec::encode($kriterien));

        self::assertSame('bartagame', $zurueck->query);
        self::assertSame(7, $zurueck->speciesId);
        self::assertSame([3, 5], array_map(static fn(MorphFilter $m): int => $m->morphId, $zurueck->morphs));
        self::assertSame([Sex::Weiblich], $zurueck->sexes);
        self::assertSame([ListingType::Verkauf], $zurueck->types);
        self::assertSame([CbStatus::Nachzucht], $zurueck->cbStatuses);
        self::assertSame(5000, $zurueck->priceMinCents);
        self::assertSame(24, $zurueck->ageMaxMonths);
        self::assertTrue($zurueck->withImageOnly);
        self::assertSame('Bayern', $zurueck->admin1);
        self::assertSame(SortOrder::PreisAufsteigend, $zurueck->sort);
        // Die Seitenzahl gehoert nicht zur Frage.
        self::assertSame(1, $zurueck->page);
    }

    public function testEinKaputterFilterMachtDieSucheNichtUnbrauchbar(): void
    {
        $kriterien = SearchCriteriaCodec::decode([
            'art_id' => 'keine Zahl',
            'geschlecht' => ['gibt-es-nicht', 'w'],
            'morphs' => ['unsinn', ['id' => 4]],
            'umkreis' => ['lat' => 48.1, 'lng' => 11.6, 'km' => 999],
            'sortierung' => 'erfunden',
        ]);

        self::assertNull($kriterien->speciesId);
        self::assertSame([Sex::Weiblich], $kriterien->sexes);
        self::assertSame([4], array_map(static fn(MorphFilter $m): int => $m->morphId, $kriterien->morphs));
        // 999 km gibt es nicht — lieber ohne Umkreis suchen als gar nicht.
        self::assertNull($kriterien->radius);
        self::assertSame(SortOrder::Neueste, $kriterien->sort);
    }

    // ------------------------------------------------------------ Dienst

    public function testEineSucheOhneFilterLaesstSichNichtMerken(): void
    {
        $this->expectException(SavedSearchException::class);

        $this->service()->save($this->userId, 'Alles', new SearchCriteria());
    }

    public function testDieObergrenzeGreift(): void
    {
        $dienst = $this->service(2);

        $dienst->save($this->userId, 'Erste', new SearchCriteria(speciesId: $this->speciesId));
        $dienst->save($this->userId, 'Zweite', new SearchCriteria(query: 'python'));

        $this->expectException(SavedSearchException::class);
        $dienst->save($this->userId, 'Dritte', new SearchCriteria(query: 'gecko'));
    }

    public function testEineFremdeSucheLaesstSichNichtLoeschen(): void
    {
        $fremder = $this->createUser('fremder@example.tld');
        $suche = $this->service()->save($this->userId, 'Meine', new SearchCriteria(speciesId: $this->speciesId));

        try {
            $this->service()->delete($fremder, $suche->id ?? 0);
            self::fail('Eine fremde Suche darf nicht loeschbar sein.');
        } catch (SavedSearchException) {
            // erwartet
        }

        self::assertCount(1, $this->repository->forUser($this->userId));
    }

    // -------------------------------------------------------- Der ganze Weg

    public function testSucheMerkenPassendeAnzeigeVeroeffentlichenGenauEineMail(): void
    {
        // Vor dem Merken vorhandene Anzeigen zaehlen nicht — sonst kaeme beim
        // ersten Lauf der halbe Bestand als "neu" heraus.
        $this->createListing($this->createUser('anbieter@example.tld'), $this->speciesId);

        $this->service()->save($this->userId, 'Bartagamen', new SearchCriteria(speciesId: $this->speciesId));

        $neu = $this->createListing($this->createUser('anbieter2@example.tld'), $this->speciesId);
        $this->veroeffentlichen($neu);

        $bericht = $this->handler()->handle(new Job(1, 'saved_search.alert', ['frequenz' => 'taeglich']));

        self::assertSame('1 Benachrichtigungen (taeglich)', $bericht);
        self::assertCount(1, $this->mailer->messages());

        $mail = $this->mailer->messages()[0];

        self::assertSame('sucher@example.tld', $mail->to);
        self::assertSame(NotificationChannel::SucheTreffer->value, $mail->purpose);
        self::assertSame($this->userId, $mail->userId);
        self::assertStringContainsString('Bartagamen', $mail->body);
        self::assertStringContainsString('/anzeige/' . $neu . '/', $mail->body);

        // Zweiter Lauf ohne neue Anzeige: nichts.
        $this->mailer->clear();
        $zweiter = $this->handler()->handle(new Job(2, 'saved_search.alert', ['frequenz' => 'taeglich']));

        self::assertSame('0 Benachrichtigungen (taeglich)', $zweiter);
        self::assertSame([], $this->mailer->messages());
    }

    public function testEineNichtPassendeAnzeigeMeldetSichNicht(): void
    {
        $andereArt = $this->createSpecies('Python regius', 'python-regius');

        $this->service()->save($this->userId, 'Bartagamen', new SearchCriteria(speciesId: $this->speciesId));

        $this->veroeffentlichen($this->createListing($this->createUser('anbieter@example.tld'), $andereArt));

        $this->handler()->handle(new Job(1, 'saved_search.alert', ['frequenz' => 'taeglich']));

        // Vorher meldete der Auftrag jede neue Anzeige, egal welche — was
        // niemandem auffiel, weil die Tabelle leer war.
        self::assertSame([], $this->mailer->messages());
    }

    public function testEineAbgeschalteteSucheWirdNichtGemeldet(): void
    {
        $suche = $this->service()->save($this->userId, 'Bartagamen', new SearchCriteria(speciesId: $this->speciesId));
        $this->service()->setAlertFrequency($this->userId, $suche->id ?? 0, AlertFrequency::Aus);

        $this->veroeffentlichen($this->createListing($this->createUser('anbieter@example.tld'), $this->speciesId));

        $this->handler()->handle(new Job(1, 'saved_search.alert', ['frequenz' => 'taeglich']));

        self::assertSame([], $this->mailer->messages());
    }

    private function service(?int $grenze = null): SavedSearchService
    {
        return new SavedSearchService(
            $this->repository,
            new PdoAuditLog($this->database),
            $this->clock,
            $grenze ?? 20,
        );
    }

    private function handler(): SavedSearchAlertHandler
    {
        return new SavedSearchAlertHandler(
            $this->repository,
            new PdoListingSearchRepository($this->database, new ListingQuery($this->clock)),
            new PdoUserRepository($this->database),
            $this->mailer,
            new Translator(\dirname(__DIR__, 3) . '/lang'),
            $this->clock,
            'https://test.example',
        );
    }

    /**
     * Sichtbar wird eine Anzeige ueber ihren Status — dieselbe Bedingung, die
     * die Suche stellt.
     */
    private function veroeffentlichen(int $listingId): void
    {
        $this->database->execute(
            "UPDATE listings SET status = 'aktiv', updated_at = :now WHERE id = :id",
            ['now' => Timestamp::utc($this->clock->now()), 'id' => $listingId],
        );
    }
}
