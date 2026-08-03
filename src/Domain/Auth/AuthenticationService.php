<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Auth;

use DateTimeImmutable;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Domain\User\UserRepository;
use Reptilienmarkt\Domain\User\UserStatus;
use Reptilienmarkt\Support\Clock;
use Reptilienmarkt\Support\Timestamp;

/**
 * Registrierung und Anmeldung.
 *
 * Grundsatz: Nach aussen unterscheidet nichts zwischen "E-Mail unbekannt" und
 * "Passwort falsch" — weder die Meldung noch die Antwortzeit.
 */
final readonly class AuthenticationService
{
    public const int MAX_FAILED_ATTEMPTS = 8;

    public const int LOCK_MINUTES = 15;

    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher,
        private Clock $clock,
    ) {}

    /**
     * @throws RegistrationException
     */
    public function register(string $email, string $displayName, string $password, Role $role = Role::Seller): User
    {
        $email = trim($email);
        $displayName = trim($displayName);

        if (filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            throw new RegistrationException('Bitte gib eine gültige E-Mail-Adresse an.');
        }

        if (mb_strlen($displayName) < 2) {
            throw new RegistrationException('Der Anzeigename braucht mindestens zwei Zeichen.');
        }

        if (mb_strlen($password) < PasswordHasher::MIN_LENGTH) {
            throw new RegistrationException(\sprintf(
                'Das Passwort muss mindestens %d Zeichen haben.',
                PasswordHasher::MIN_LENGTH,
            ));
        }

        if ($this->users->findByEmail($email) !== null) {
            throw new RegistrationException('Zu dieser E-Mail-Adresse gibt es bereits ein Konto.');
        }

        $user = new User(null, $email, $displayName, $role);
        $id = $this->users->create($user, $this->hasher->hash($password));

        $created = $this->users->findById($id);
        \assert($created instanceof User);

        return $created;
    }

    /**
     * @throws AuthenticationException
     */
    public function authenticate(string $email, string $password): User
    {
        $user = $this->users->findByEmail(trim($email));

        if ($user === null || $user->id === null) {
            // Gleiche Rechenzeit wie bei einem existierenden Konto.
            $this->hasher->burnCycles();

            throw new AuthenticationException('E-Mail-Adresse oder Passwort ist falsch.');
        }

        $lockedUntil = $this->users->lockedUntil($user->id);
        if ($lockedUntil !== null && new DateTimeImmutable($lockedUntil) > $this->clock->now()) {
            throw new AuthenticationException(\sprintf(
                'Zu viele Fehlversuche. Bitte in %d Minuten erneut versuchen.',
                self::LOCK_MINUTES,
            ));
        }

        $hash = $this->users->passwordHashFor($user->id);

        if ($hash === null || !$this->hasher->verify($password, $hash)) {
            $attempts = $this->users->recordFailedLogin($user->id);

            if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
                $this->users->lockUntil(
                    $user->id,
                    Timestamp::utc($this->clock->now()->modify(\sprintf('+%d minutes', self::LOCK_MINUTES))),
                );
            }

            throw new AuthenticationException('E-Mail-Adresse oder Passwort ist falsch.');
        }

        if ($user->status !== UserStatus::Aktiv) {
            throw new AuthenticationException('Dieses Konto ist gesperrt.');
        }

        // Kosten nachziehen, wenn die Parameter inzwischen erhoeht wurden.
        if ($this->hasher->needsRehash($hash)) {
            $this->users->updatePasswordHash($user->id, $this->hasher->hash($password));
        }

        $this->users->recordSuccessfulLogin($user->id);

        return $user;
    }
}
