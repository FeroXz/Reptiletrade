<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\User;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Auth\AuthenticationService;
use Reptilienmarkt\Domain\Auth\PasswordHasher;
use Reptilienmarkt\Domain\Auth\TokenException;
use Reptilienmarkt\Domain\Auth\TokenService;
use Reptilienmarkt\Domain\Auth\TokenType;
use Reptilienmarkt\Domain\Auth\TotpAuthenticator;
use Reptilienmarkt\Domain\Mail\MailMessage;
use Reptilienmarkt\Domain\User\AccountException;
use Reptilienmarkt\Domain\User\AccountService;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Domain\User\VerificationLevel;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoSessionRepository;
use Reptilienmarkt\Infra\Persistence\PdoTokenRepository;
use Reptilienmarkt\Infra\Persistence\PdoUserRepository;
use Reptilienmarkt\Infra\Persistence\PdoVerificationRepository;
use Reptilienmarkt\Support\Translator;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\CollectingMailer;
use Reptilienmarkt\Tests\Support\FrozenClock;

#[CoversClass(AccountService::class)]
#[CoversClass(TokenService::class)]
#[CoversClass(PdoTokenRepository::class)]
#[CoversClass(PdoVerificationRepository::class)]
final class AccountServiceTest extends DatabaseTestCase
{
    private AccountService $accounts;

    private AuthenticationService $auth;

    private PdoUserRepository $users;

    private TokenService $tokens;

    private CollectingMailer $mailer;

    private FrozenClock $clock;

    private TotpAuthenticator $totp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-03T12:00:00+00:00'));
        $this->users = new PdoUserRepository($this->database);
        $this->mailer = new CollectingMailer();
        $this->tokens = new TokenService(new PdoTokenRepository($this->database), $this->clock);
        $this->totp = new TotpAuthenticator($this->clock);

        $hasher = new PasswordHasher(8192, 1, 1);
        $this->auth = new AuthenticationService($this->users, $hasher, $this->clock);

        $this->accounts = new AccountService(
            $this->users,
            new PdoVerificationRepository($this->database),
            $this->tokens,
            $hasher,
            $this->totp,
            new PdoSessionRepository($this->database),
            $this->mailer,
            new PdoAuditLog($this->database),
            $this->clock,
            new Translator(\dirname(__DIR__, 3) . '/lang'),
            'https://markt.example',
        );
    }

    private function register(string $email = 'zuechter@example.tld'): User
    {
        return $this->auth->register($email, 'Testzüchter', 'einsicheres123');
    }

    private function letzteMail(): MailMessage
    {
        $mails = $this->mailer->messages();

        self::assertNotEmpty($mails, 'Es wurde keine Mail verschickt.');

        return $mails[\count($mails) - 1];
    }

    private function tokenAusMail(string $parameter = 'token'): string
    {
        return rawurldecode($this->ausMail('/[?&]' . $parameter . '=([^\s&]+)/', 'In der Mail steht kein Token.'));
    }

    private function codeAusMail(): string
    {
        return $this->ausMail('/Code lautet: (\d{6})/', 'In der Mail steht kein Code.');
    }

    /**
     * Der erste Klammerausdruck aus der zuletzt verschickten Mail.
     *
     * Der Rueckgabewert von preg_match wird geprueft und nicht nur der Treffer:
     * Ohne Treffer liefe der Test mit einer leeren Zeichenkette weiter und
     * scheiterte spaeter an einer Stelle, die mit der Ursache nichts zu tun hat.
     */
    private function ausMail(string $muster, string $meldung): string
    {
        if (preg_match($muster, $this->letzteMail()->body, $treffer) !== 1) {
            self::fail($meldung);
        }

        return $treffer[1];
    }

    // ------------------------------------------------------------- E-Mail

    public function testEmailBestaetigungHebtDieStufe(): void
    {
        $user = $this->register();
        self::assertSame(VerificationLevel::Keine, $user->verificationLevel());

        $this->accounts->sendEmailVerification($user);
        $bestaetigt = $this->accounts->confirmEmail($this->tokenAusMail());

        self::assertSame(VerificationLevel::Email, $bestaetigt->verificationLevel());
    }

    public function testDerBestaetigungslinkGiltNurEinmal(): void
    {
        $user = $this->register();
        $this->accounts->sendEmailVerification($user);
        $token = $this->tokenAusMail();

        $this->accounts->confirmEmail($token);

        $this->expectException(TokenException::class);

        $this->accounts->confirmEmail($token);
    }

    public function testEinNeuerLinkEntwertetDenAlten(): void
    {
        $user = $this->register();

        $this->accounts->sendEmailVerification($user);
        $alt = $this->tokenAusMail();

        $this->accounts->sendEmailVerification($user);

        $this->expectException(TokenException::class);

        $this->accounts->confirmEmail($alt);
    }

    public function testAbgelaufenerLinkWirdAbgelehnt(): void
    {
        $user = $this->register();
        $this->accounts->sendEmailVerification($user);
        $token = $this->tokenAusMail();

        $this->clock->travelTo(new DateTimeImmutable('2026-08-10T12:00:00+00:00'));

        $this->expectException(TokenException::class);

        $this->accounts->confirmEmail($token);
    }

    /**
     * In der Datenbank darf der Klartext nicht stehen — sonst waere ein
     * Datenbank-Leck eine Kontouebernahme.
     */
    public function testInDerDatenbankStehtNurDerHash(): void
    {
        $user = $this->register();
        $this->accounts->sendEmailVerification($user);
        $token = $this->tokenAusMail();

        $gespeichert = (string) $this->database->scalar('SELECT token_hash FROM user_tokens LIMIT 1');

        self::assertNotSame($token, $gespeichert);
        self::assertSame(hash('sha256', $token), $gespeichert);
    }

    // ------------------------------------------------------------ Telefon

    public function testTelefonstufeBrauchtBestaetigteEmail(): void
    {
        $user = $this->register();

        $this->expectException(AccountException::class);
        $this->expectExceptionMessageMatches('/E-Mail/');

        $this->accounts->startPhoneVerification($user, '0176 12345678');
    }

    public function testTelefonBestaetigenHebtDieStufe(): void
    {
        $user = $this->register();
        $this->accounts->sendEmailVerification($user);
        $user = $this->accounts->confirmEmail($this->tokenAusMail());

        $this->accounts->startPhoneVerification($user, '0176 12345678');

        $bestaetigt = $this->accounts->confirmPhone($user, $this->codeAusMail());

        self::assertSame(VerificationLevel::Telefon, $bestaetigt->verificationLevel());
    }

    public function testUnbrauchbareTelefonnummerWirdAbgelehnt(): void
    {
        $user = $this->register();
        $this->accounts->sendEmailVerification($user);
        $user = $this->accounts->confirmEmail($this->tokenAusMail());

        $this->expectException(AccountException::class);

        $this->accounts->startPhoneVerification($user, '123');
    }

    public function testTelefonnummernWerdenVereinheitlicht(): void
    {
        self::assertSame('+49176123456', AccountService::normalizePhone('0049 176 123456'));
        self::assertSame('0176123456', AccountService::normalizePhone('0176 / 123-456'));
        self::assertNull(AccountService::normalizePhone('12345'));
        self::assertNull(AccountService::normalizePhone('keine Nummer'));
    }

    // --------------------------------------------------- Passwort-Reset

    public function testPasswortResetSetztDasPasswortNeu(): void
    {
        $this->register();

        $this->accounts->requestPasswordReset('zuechter@example.tld');
        $this->accounts->resetPassword($this->tokenAusMail(), 'einneues456789');

        self::assertSame('Testzüchter', $this->auth->authenticate('zuechter@example.tld', 'einneues456789')->displayName);
    }

    /**
     * Ob es die Adresse gibt, darf das Formular nicht verraten — auch nicht
     * dadurch, dass es bei einer unbekannten Adresse anders reagiert.
     */
    public function testUnbekannteAdresseFuehrtZuKeinerMailUndKeinemFehler(): void
    {
        $this->accounts->requestPasswordReset('gibtesnicht@example.tld');

        self::assertSame([], $this->mailer->messages());
    }

    public function testDerResetLinkGiltNurEinmal(): void
    {
        $this->register();
        $this->accounts->requestPasswordReset('zuechter@example.tld');
        $token = $this->tokenAusMail();

        $this->accounts->resetPassword($token, 'einneues456789');

        $this->expectException(TokenException::class);

        $this->accounts->resetPassword($token, 'nochmalanders12');
    }

    /**
     * Ein zu kurzes Passwort soll den Token nicht verbrennen — sonst muesste
     * der Nutzer wegen eines Tippfehlers einen neuen Link anfordern.
     */
    public function testEinZuKurzesPasswortVerbrenntDenTokenNicht(): void
    {
        $this->register();
        $this->accounts->requestPasswordReset('zuechter@example.tld');
        $token = $this->tokenAusMail();

        try {
            $this->accounts->resetPassword($token, 'kurz');
            self::fail('Zu kurzes Passwort haette abgelehnt werden muessen.');
        } catch (AccountException) {
            // erwartet
        }

        $this->accounts->resetPassword($token, 'einneues456789');

        // authenticate() wirft bei falschem Passwort — kommt es hier durch,
        // gilt das neue.
        self::assertSame('Testzüchter', $this->auth->authenticate('zuechter@example.tld', 'einneues456789')->displayName);
    }

    public function testResetLoestDieKontosperre(): void
    {
        $user = $this->register();

        for ($i = 0; $i < AuthenticationService::MAX_FAILED_ATTEMPTS; ++$i) {
            try {
                $this->auth->authenticate('zuechter@example.tld', 'falsch' . $i);
            } catch (\Reptilienmarkt\Domain\Auth\AuthenticationException) {
                // erwartet
            }
        }

        self::assertNotNull($this->users->lockedUntil($user->id ?? 0));

        $this->accounts->requestPasswordReset('zuechter@example.tld');
        $this->accounts->resetPassword($this->tokenAusMail(), 'einneues456789');

        self::assertNull($this->users->lockedUntil($user->id ?? 0));
    }

    public function testPasswortAendernBrauchtDasAlte(): void
    {
        $user = $this->register();

        $this->expectException(AccountException::class);

        $this->accounts->changePassword($user, 'falschespasswort', 'einneues456789');
    }

    public function testPasswortAendern(): void
    {
        $user = $this->register();

        $this->accounts->changePassword($user, 'einsicheres123', 'einneues456789');

        self::assertSame($user->id, $this->auth->authenticate('zuechter@example.tld', 'einneues456789')->id);
    }

    // ------------------------------------------------------- Zwei-Faktor

    public function testZweiFaktorGiltErstNachBestaetigung(): void
    {
        $user = $this->register();
        $geheimnis = $this->accounts->beginTwoFactorSetup($user);

        // Angelegt, aber noch nicht scharf.
        self::assertFalse($this->accounts->twoFactorEnabled($user->id ?? 0));

        $this->accounts->confirmTwoFactor($user, $this->totp->currentCode($geheimnis));

        self::assertTrue($this->accounts->twoFactorEnabled($user->id ?? 0));
        self::assertTrue($this->accounts->verifyTwoFactorCode($user->id ?? 0, $this->totp->currentCode($geheimnis)));
    }

    public function testFalscherCodeSchaltetNichtScharf(): void
    {
        $user = $this->register();
        $this->accounts->beginTwoFactorSetup($user);

        try {
            $this->accounts->confirmTwoFactor($user, '000000');
            self::fail('Ein falscher Code haette abgelehnt werden muessen.');
        } catch (AccountException) {
            // erwartet
        }

        self::assertFalse($this->accounts->twoFactorEnabled($user->id ?? 0));
    }

    public function testAbschaltenBrauchtDasPasswort(): void
    {
        $user = $this->register();
        $geheimnis = $this->accounts->beginTwoFactorSetup($user);
        $this->accounts->confirmTwoFactor($user, $this->totp->currentCode($geheimnis));

        try {
            $this->accounts->disableTwoFactor($user, 'falschespasswort');
            self::fail('Ohne richtiges Passwort darf nichts abgeschaltet werden.');
        } catch (AccountException) {
            // erwartet
        }

        self::assertTrue($this->accounts->twoFactorEnabled($user->id ?? 0));

        $this->accounts->disableTwoFactor($user, 'einsicheres123');

        self::assertFalse($this->accounts->twoFactorEnabled($user->id ?? 0));
    }

    public function testTokenEinesAnderenKontosGreiftNicht(): void
    {
        $ersterNutzer = $this->register('erster@example.tld');
        $zweiterNutzer = $this->register('zweiter@example.tld');

        $this->accounts->sendEmailVerification($ersterNutzer);
        $this->accounts->confirmEmail($this->tokenAusMail());

        $this->accounts->startPhoneVerification(
            $this->users->findById($ersterNutzer->id ?? 0) ?? $ersterNutzer,
            '0176 12345678',
        );

        $code = $this->codeAusMail();

        $this->expectException(AccountException::class);

        $this->accounts->confirmPhone($zweiterNutzer, $code);
    }

    public function testTokenlaufzeitenUnterscheidenSichNachZweck(): void
    {
        // Ein Reset-Link bedeutet vollen Kontozugriff und lebt deshalb kurz.
        self::assertLessThan(
            TokenType::EmailVerify->lifetimeMinutes(),
            TokenType::PasswordReset->lifetimeMinutes(),
        );
        self::assertLessThan(
            TokenType::PasswordReset->lifetimeMinutes(),
            TokenType::PhoneVerify->lifetimeMinutes(),
        );
    }
}
