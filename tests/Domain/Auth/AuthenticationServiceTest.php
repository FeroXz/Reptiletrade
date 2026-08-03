<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Auth;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Auth\AuthenticationException;
use Reptilienmarkt\Domain\Auth\AuthenticationService;
use Reptilienmarkt\Domain\Auth\PasswordHasher;
use Reptilienmarkt\Domain\Auth\RegistrationException;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\UserStatus;
use Reptilienmarkt\Infra\Persistence\PdoUserRepository;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;

#[CoversClass(AuthenticationService::class)]
#[CoversClass(PasswordHasher::class)]
#[CoversClass(PdoUserRepository::class)]
final class AuthenticationServiceTest extends DatabaseTestCase
{
    private AuthenticationService $auth;

    private PdoUserRepository $users;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-02T12:00:00+00:00'));
        $this->users = new PdoUserRepository($this->database);
        // Niedrige Kosten: Der Test prueft die Ablaeufe, nicht die Rechenzeit.
        $this->auth = new AuthenticationService($this->users, new PasswordHasher(8192, 1, 1), $this->clock);
    }

    public function testRegistrierenUndAnmelden(): void
    {
        $registriert = $this->auth->register('Zuechter@Example.TLD', 'Testzüchter', 'einsicheres123');

        self::assertNotNull($registriert->id);
        self::assertSame(Role::Seller, $registriert->role);
        self::assertSame(UserStatus::Aktiv, $registriert->status);

        $angemeldet = $this->auth->authenticate('zuechter@example.tld', 'einsicheres123');

        self::assertSame($registriert->id, $angemeldet->id);
    }

    public function testEmailIstUnabhaengigVonGrossschreibung(): void
    {
        $this->auth->register('Zuechter@Example.TLD', 'Testzüchter', 'einsicheres123');

        self::assertSame('zuechter@example.tld', $this->auth->authenticate('ZUECHTER@EXAMPLE.TLD', 'einsicheres123')->emailCanonical());
    }

    public function testDoppelteRegistrierungScheitert(): void
    {
        $this->auth->register('zuechter@example.tld', 'Erster', 'einsicheres123');

        $this->expectException(RegistrationException::class);

        $this->auth->register('zuechter@example.tld', 'Zweiter', 'einanderes456');
    }

    public function testZuKurzesPasswortScheitert(): void
    {
        $this->expectException(RegistrationException::class);
        $this->expectExceptionMessageMatches('/mindestens/');

        $this->auth->register('zuechter@example.tld', 'Testzüchter', 'kurz');
    }

    public function testUngueltigeEmailScheitert(): void
    {
        $this->expectException(RegistrationException::class);

        $this->auth->register('keine-email', 'Testzüchter', 'einsicheres123');
    }

    public function testFalschesPasswortScheitert(): void
    {
        $this->auth->register('zuechter@example.tld', 'Testzüchter', 'einsicheres123');

        $this->expectException(AuthenticationException::class);

        $this->auth->authenticate('zuechter@example.tld', 'falschespasswort');
    }

    /**
     * Die Meldung darf nicht verraten, ob es das Konto gibt.
     */
    public function testMeldungUnterscheidetNichtZwischenUnbekanntUndFalsch(): void
    {
        $this->auth->register('zuechter@example.tld', 'Testzüchter', 'einsicheres123');

        $unbekannt = null;
        $falsch = null;

        try {
            $this->auth->authenticate('gibtesnicht@example.tld', 'einsicheres123');
        } catch (AuthenticationException $exception) {
            $unbekannt = $exception->getMessage();
        }

        try {
            $this->auth->authenticate('zuechter@example.tld', 'falschespasswort');
        } catch (AuthenticationException $exception) {
            $falsch = $exception->getMessage();
        }

        self::assertNotNull($unbekannt);
        self::assertSame($unbekannt, $falsch);
    }

    public function testKontoWirdNachZuVielenFehlversuchenGesperrt(): void
    {
        $user = $this->auth->register('zuechter@example.tld', 'Testzüchter', 'einsicheres123');

        for ($i = 0; $i < AuthenticationService::MAX_FAILED_ATTEMPTS; ++$i) {
            try {
                $this->auth->authenticate('zuechter@example.tld', 'falsch' . $i);
            } catch (AuthenticationException) {
                // erwartet
            }
        }

        self::assertNotNull($this->users->lockedUntil($user->id ?? 0));

        // Auch mit richtigem Passwort bleibt die Tuer vorerst zu.
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessageMatches('/Fehlversuche/');

        $this->auth->authenticate('zuechter@example.tld', 'einsicheres123');
    }

    public function testSperreLaeuftAb(): void
    {
        $this->auth->register('zuechter@example.tld', 'Testzüchter', 'einsicheres123');

        for ($i = 0; $i < AuthenticationService::MAX_FAILED_ATTEMPTS; ++$i) {
            try {
                $this->auth->authenticate('zuechter@example.tld', 'falsch' . $i);
            } catch (AuthenticationException) {
                // erwartet
            }
        }

        $this->clock->travelTo(new DateTimeImmutable('2026-08-02T12:20:00+00:00'));

        self::assertSame('Testzüchter', $this->auth->authenticate('zuechter@example.tld', 'einsicheres123')->displayName);
    }

    public function testErfolgreicheAnmeldungSetztDenZaehlerZurueck(): void
    {
        $user = $this->auth->register('zuechter@example.tld', 'Testzüchter', 'einsicheres123');

        try {
            $this->auth->authenticate('zuechter@example.tld', 'falsch');
        } catch (AuthenticationException) {
            // erwartet
        }

        $this->auth->authenticate('zuechter@example.tld', 'einsicheres123');

        $zaehler = $this->database->scalar('SELECT failed_login_attempts FROM users WHERE id = :id', ['id' => $user->id]);
        self::assertSame(0, (int) $zaehler);
    }

    public function testGesperrtesKontoKommtNichtRein(): void
    {
        $user = $this->auth->register('zuechter@example.tld', 'Testzüchter', 'einsicheres123');
        $this->database->execute("UPDATE users SET status = 'gesperrt' WHERE id = :id", ['id' => $user->id]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessageMatches('/gesperrt/');

        $this->auth->authenticate('zuechter@example.tld', 'einsicheres123');
    }

    public function testPasswortWirdMitArgon2idGehasht(): void
    {
        $user = $this->auth->register('zuechter@example.tld', 'Testzüchter', 'einsicheres123');
        $hash = (string) $this->users->passwordHashFor($user->id ?? 0);

        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertStringNotContainsString('einsicheres123', $hash);
    }

    public function testVeralteteKostenWerdenNachgezogen(): void
    {
        $user = $this->auth->register('zuechter@example.tld', 'Testzüchter', 'einsicheres123');
        $alt = (string) $this->users->passwordHashFor($user->id ?? 0);

        // Dienst mit hoeheren Kosten: Der naechste Login muss neu hashen.
        $strenger = new AuthenticationService($this->users, new PasswordHasher(16384, 2, 1), $this->clock);
        $strenger->authenticate('zuechter@example.tld', 'einsicheres123');

        self::assertNotSame($alt, $this->users->passwordHashFor($user->id ?? 0));
    }
}
