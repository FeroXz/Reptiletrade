<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\User;

use Reptilienmarkt\Domain\Audit\AuditEntry;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Domain\Auth\PasswordHasher;
use Reptilienmarkt\Domain\Auth\TokenException;
use Reptilienmarkt\Domain\Auth\TokenService;
use Reptilienmarkt\Domain\Auth\TokenType;
use Reptilienmarkt\Domain\Auth\TotpAuthenticator;
use Reptilienmarkt\Domain\Mail\Mailer;
use Reptilienmarkt\Domain\Mail\MailMessage;
use Reptilienmarkt\Support\Clock;
use Reptilienmarkt\Support\Translator;

/**
 * Kontoverwaltung: Verifizierungsstufen, Passwort-Reset, Zwei-Faktor.
 *
 * Die Verifizierung ist eine Leiter — E-Mail, dann Telefon, dann Ausweis. Die
 * ersten beiden Stufen erledigt der Nutzer selbst ueber einen Einmal-Token,
 * die dritte prueft ein Mensch.
 */
final readonly class AccountService
{
    public function __construct(
        private UserRepository $users,
        private VerificationRepository $verification,
        private TokenService $tokens,
        private PasswordHasher $hasher,
        private TotpAuthenticator $totp,
        private Mailer $mailer,
        private AuditLog $audit,
        private Clock $clock,
        private Translator $translator,
        private string $appUrl = 'https://example.tld',
    ) {}

    // ----------------------------------------------------------- E-Mail

    public function sendEmailVerification(User $user): void
    {
        $token = $this->tokens->issue($user->id ?? 0, TokenType::EmailVerify);

        $this->mailer->send(new MailMessage(
            $user->email,
            $this->translator->translate('mail.email_verify.betreff'),
            $this->translator->translate('mail.email_verify.text', [
                'name' => $user->displayName,
                'link' => $this->appUrl . '/konto/email-bestaetigen?token=' . rawurlencode($token),
                'stunden' => (int) (TokenType::EmailVerify->lifetimeMinutes() / 60),
            ]),
            $user->displayName,
        ));
    }

    /**
     * @throws TokenException
     */
    public function confirmEmail(string $token): User
    {
        $record = $this->tokens->redeem($token, TokenType::EmailVerify);
        $now = $this->clock->now();

        $this->verification->markEmailVerified($record->userId, $now);
        $this->audit->record(new AuditEntry('user.email_verified', 'user', $record->userId, [], $record->userId));

        return $this->users->findById($record->userId) ?? throw new TokenException('Das Konto gibt es nicht mehr.');
    }

    // ---------------------------------------------------------- Telefon

    /**
     * @throws AccountException
     */
    public function startPhoneVerification(User $user, string $phone): string
    {
        if (!$user->hasVerifiedEmail()) {
            throw new AccountException('Bitte bestätige zuerst deine E-Mail-Adresse.');
        }

        $normalized = self::normalizePhone($phone);
        if ($normalized === null) {
            throw new AccountException('Bitte gib eine gültige Telefonnummer an.');
        }

        $this->verification->setPhone($user->id ?? 0, $normalized);

        // Der Code geht per SMS raus, sobald ein Anbieter angebunden ist. Bis
        // dahin laeuft er ueber dieselbe Mailstrecke — sichtbar, nachvollziehbar
        // und ohne stillschweigend nicht funktionierende Stufe.
        $code = $this->tokens->issue($user->id ?? 0, TokenType::PhoneVerify);

        $this->mailer->send(new MailMessage(
            $user->email,
            $this->translator->translate('mail.telefon_code.betreff'),
            $this->translator->translate('mail.telefon_code.text', [
                'code' => $code,
                'nummer' => $normalized,
                'minuten' => TokenType::PhoneVerify->lifetimeMinutes(),
            ]),
            $user->displayName,
        ));

        return $normalized;
    }

    /**
     * @throws TokenException
     * @throws AccountException
     */
    public function confirmPhone(User $user, string $code): User
    {
        $record = $this->tokens->redeem($code, TokenType::PhoneVerify);

        if ($record->userId !== ($user->id ?? 0)) {
            throw new AccountException('Dieser Code gehört zu einem anderen Konto.');
        }

        $this->verification->markPhoneVerified($record->userId, $this->clock->now());
        $this->audit->record(new AuditEntry('user.phone_verified', 'user', $record->userId, [], $record->userId));

        return $this->users->findById($record->userId) ?? $user;
    }

    // --------------------------------------------------- Passwort-Reset

    /**
     * Stoesst den Reset an. Ob es die Adresse gibt, wird nach aussen nicht
     * verraten — der Aufrufer bekommt immer dieselbe Antwort.
     */
    public function requestPasswordReset(string $email): void
    {
        $user = $this->users->findByEmail($email);

        if ($user === null || !$user->isActive()) {
            return;
        }

        $token = $this->tokens->issue($user->id ?? 0, TokenType::PasswordReset);

        $this->mailer->send(new MailMessage(
            $user->email,
            $this->translator->translate('mail.passwort_reset.betreff'),
            $this->translator->translate('mail.passwort_reset.text', [
                'name' => $user->displayName,
                'link' => $this->appUrl . '/passwort/neu?token=' . rawurlencode($token),
                'minuten' => TokenType::PasswordReset->lifetimeMinutes(),
            ]),
            $user->displayName,
        ));

        $this->audit->record(new AuditEntry('user.password_reset_requested', 'user', $user->id, [], $user->id));
    }

    /**
     * @throws TokenException
     * @throws AccountException
     */
    public function resetPassword(string $token, string $newPassword): User
    {
        if (mb_strlen($newPassword) < PasswordHasher::MIN_LENGTH) {
            // Erst pruefen, dann einloesen: Ein zu kurzes Passwort soll den
            // Token nicht verbrennen.
            throw new AccountException(\sprintf(
                'Das Passwort muss mindestens %d Zeichen haben.',
                PasswordHasher::MIN_LENGTH,
            ));
        }

        $record = $this->tokens->redeem($token, TokenType::PasswordReset);

        $this->users->updatePasswordHash($record->userId, $this->hasher->hash($newPassword));
        $this->verification->clearLoginFailures($record->userId);

        $this->audit->record(new AuditEntry('user.password_reset', 'user', $record->userId, [], $record->userId));

        return $this->users->findById($record->userId) ?? throw new TokenException('Das Konto gibt es nicht mehr.');
    }

    /**
     * @throws AccountException
     */
    public function changePassword(User $user, string $current, string $new): void
    {
        $hash = $this->users->passwordHashFor($user->id ?? 0);

        if ($hash === null || !$this->hasher->verify($current, $hash)) {
            throw new AccountException('Das aktuelle Passwort stimmt nicht.');
        }

        if (mb_strlen($new) < PasswordHasher::MIN_LENGTH) {
            throw new AccountException(\sprintf(
                'Das Passwort muss mindestens %d Zeichen haben.',
                PasswordHasher::MIN_LENGTH,
            ));
        }

        $this->users->updatePasswordHash($user->id ?? 0, $this->hasher->hash($new));
        $this->audit->record(new AuditEntry('user.password_changed', 'user', $user->id, [], $user->id));
    }

    // ------------------------------------------------------ Zwei-Faktor

    /**
     * Legt ein Geheimnis an, schaltet die zweite Stufe aber noch nicht scharf —
     * das passiert erst, wenn ein Code daraus stimmt.
     */
    public function beginTwoFactorSetup(User $user): string
    {
        $secret = $this->totp->generateSecret();
        $this->verification->storeTotpSecret($user->id ?? 0, $secret);

        return $secret;
    }

    /**
     * @throws AccountException
     */
    public function confirmTwoFactor(User $user, string $code): void
    {
        $secret = $this->verification->totpSecret($user->id ?? 0);

        if ($secret === null) {
            throw new AccountException('Die Einrichtung wurde nicht begonnen.');
        }

        if (!$this->totp->verify($secret, $code)) {
            throw new AccountException('Der Code stimmt nicht. Prüfe die Uhrzeit deines Geräts.');
        }

        $this->verification->confirmTotp($user->id ?? 0, $this->clock->now());
        $this->audit->record(new AuditEntry('user.two_factor_enabled', 'user', $user->id, [], $user->id));
    }

    /**
     * @throws AccountException
     */
    public function disableTwoFactor(User $user, string $password): void
    {
        $hash = $this->users->passwordHashFor($user->id ?? 0);

        // Ohne Passwortpruefung koennte eine uebernommene Sitzung die zweite
        // Stufe einfach abschalten.
        if ($hash === null || !$this->hasher->verify($password, $hash)) {
            throw new AccountException('Das Passwort stimmt nicht.');
        }

        $this->verification->clearTotp($user->id ?? 0);
        $this->audit->record(new AuditEntry('user.two_factor_disabled', 'user', $user->id, [], $user->id));
    }

    public function verifyTwoFactorCode(int $userId, string $code): bool
    {
        $secret = $this->verification->confirmedTotpSecret($userId);

        return $secret !== null && $this->totp->verify($secret, $code);
    }

    public function twoFactorEnabled(int $userId): bool
    {
        return $this->verification->confirmedTotpSecret($userId) !== null;
    }

    /**
     * Telefonnummern in eine einheitliche Form bringen. Bewusst nachsichtig:
     * Wer 0176 / 1234567 schreibt, meint dasselbe wie +49 176 1234567.
     */
    public static function normalizePhone(string $phone): ?string
    {
        $trimmed = trim($phone);
        $digits = preg_replace('/[^\d+]/', '', $trimmed) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = '+' . substr($digits, 2);
        }

        $onlyDigits = ltrim($digits, '+');

        if (\strlen($onlyDigits) < 7 || \strlen($onlyDigits) > 15) {
            return null;
        }

        return $digits;
    }
}
