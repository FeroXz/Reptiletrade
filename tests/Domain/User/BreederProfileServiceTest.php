<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\User;

use DateTime;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\User\BreederProfile;
use Reptilienmarkt\Domain\User\BreederProfileService;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoBreederProfileRepository;
use Reptilienmarkt\Tests\DatabaseTestCase;

#[CoversClass(BreederProfileService::class)]
#[CoversClass(PdoBreederProfileRepository::class)]
#[CoversClass(BreederProfile::class)]
final class BreederProfileServiceTest extends DatabaseTestCase
{
    private BreederProfileService $service;

    private PdoBreederProfileRepository $profiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->profiles = new PdoBreederProfileRepository($this->database);
        $this->service = new BreederProfileService($this->profiles, new PdoAuditLog($this->database));
    }

    private function user(string $email, string $name): User
    {
        return new User($this->createUser($email), $email, $name, Role::Breeder);
    }

    private function save(User $user, ?string $slug = null, ?int $since = null, bool $public = true): BreederProfile
    {
        return $this->service->save($user, $slug, null, 'Beschreibung', [], $since, null, $public);
    }

    public function testDerSlugEntstehtAusDemAnzeigenamen(): void
    {
        $profil = $this->save($this->user('a@example.tld', 'Drachen Zucht München'));

        self::assertSame('drachen-zucht-muenchen', $profil->slug);
    }

    public function testKollisionBekommtEineZahl(): void
    {
        $this->save($this->user('a@example.tld', 'Drachenzucht'));
        $zweites = $this->save($this->user('b@example.tld', 'Drachenzucht'));

        self::assertSame('drachenzucht-2', $zweites->slug);
    }

    /**
     * Wer sein Profil verlinkt hat, soll den Link nicht durch ein zweites
     * Speichern verlieren.
     */
    public function testDerSlugBleibtBeimZweitenSpeichernStehen(): void
    {
        $user = $this->user('a@example.tld', 'Drachenzucht');
        $erst = $this->save($user);
        $dann = $this->save($user);

        self::assertSame($erst->slug, $dann->slug);
    }

    public function testEinNeuerSlugLaesstSichSetzen(): void
    {
        $user = $this->user('a@example.tld', 'Drachenzucht');
        $this->save($user);
        $geaendert = $this->save($user, 'agamen-muenchen');

        self::assertSame('agamen-muenchen', $geaendert->slug);
        self::assertNull($this->profiles->findBySlug('drachenzucht'));
    }

    public function testProfilLaesstSichUeberDenSlugFinden(): void
    {
        $user = $this->user('a@example.tld', 'Drachenzucht');
        $this->save($user);

        self::assertSame($user->id, $this->profiles->findBySlug('drachenzucht')?->userId);
    }

    public function testZuchtjahreZaehlenAbDemAngegebenenJahr(): void
    {
        $profil = $this->save($this->user('a@example.tld', 'Drachenzucht'), since: 2020);

        self::assertSame(6, $profil->breedingYears(new DateTimeImmutable('2026-08-03')));
    }

    /**
     * Regressionstest: Twigs date() liefert ein DateTime, kein
     * DateTimeImmutable. Mit der engeren Signatur brach die Profilseite mit
     * einem Serverfehler.
     */
    public function testZuchtjahreAkzeptierenAuchEinDateTime(): void
    {
        $profil = $this->save($this->user('a@example.tld', 'Drachenzucht'), since: 2020);

        self::assertSame(6, $profil->breedingYears(new DateTime('2026-08-03')));
    }

    public function testOhneJahresangabeGibtEsKeineZuchtjahre(): void
    {
        $profil = $this->save($this->user('a@example.tld', 'Drachenzucht'));

        self::assertNull($profil->breedingYears(new DateTimeImmutable('2026-08-03')));
    }

    public function testUnsinnigesJahrWirdAbgelehnt(): void
    {
        $this->expectException(\Reptilienmarkt\Domain\User\AccountException::class);

        $this->save($this->user('a@example.tld', 'Drachenzucht'), since: 1800);
    }

    public function testWebsiteBekommtEinSchema(): void
    {
        $user = $this->user('a@example.tld', 'Drachenzucht');
        $profil = $this->service->save($user, null, null, null, [], null, 'beispiel.de', true);

        self::assertSame('https://beispiel.de', $profil->website);
    }

    public function testUnsinnigeWebsiteWirdVerworfen(): void
    {
        $user = $this->user('a@example.tld', 'Drachenzucht');
        $profil = $this->service->save($user, null, null, null, [], null, 'kein url', true);

        self::assertNull($profil->website);
    }

    public function testSchwerpunktIstBegrenztUndOhneDubletten(): void
    {
        $user = $this->user('a@example.tld', 'Drachenzucht');
        $profil = $this->service->save($user, null, null, null, [1, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10], null, null, true);

        self::assertCount(BreederProfile::MAX_FOCUS_SPECIES, $profil->focusSpeciesIds);
        self::assertSame($profil->focusSpeciesIds, array_values(array_unique($profil->focusSpeciesIds)));
    }

    public function testSchwerpunktUeberlebtDenSpeichervorgang(): void
    {
        $user = $this->user('a@example.tld', 'Drachenzucht');
        $this->service->save($user, null, null, null, [4, 7], null, null, true);

        self::assertSame([4, 7], $this->profiles->findByUser($user->id ?? 0)?->focusSpeciesIds);
    }

    public function testStatistikZaehltAnzeigenUndBewertungen(): void
    {
        $user = $this->user('a@example.tld', 'Drachenzucht');
        $speciesId = $this->createSpecies();
        $this->createListing($user->id ?? 0, $speciesId, 'aktiv');
        $this->createListing($user->id ?? 0, $speciesId, 'entwurf');

        $statistik = $this->service->statistics($user->id ?? 0);

        self::assertSame(1, $statistik['aktive_anzeigen']);
        self::assertSame(0, $statistik['bewertungen']);
        self::assertNull($statistik['schnitt']);
    }

    public function testNichtOeffentlichesProfilBleibtAuffindbarAberMarkiert(): void
    {
        $user = $this->user('a@example.tld', 'Drachenzucht');
        $this->save($user, public: false);

        self::assertFalse($this->profiles->findByUser($user->id ?? 0)?->isPublic);
    }
}
