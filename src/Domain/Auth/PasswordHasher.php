<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Auth;

use InvalidArgumentException;

/**
 * Argon2id mit bewusst gesetzten Parametern.
 *
 * Die Kosten stehen hier und nicht in der .env: Wer sie senkt, senkt die
 * Sicherheit aller kuenftigen Hashes. needsRehash() zieht bestehende Hashes
 * beim naechsten erfolgreichen Login nach.
 */
final readonly class PasswordHasher
{
    public const int MIN_LENGTH = 10;

    public const int MAX_LENGTH = 4096;

    /**
     * @param int $memoryCost KiB
     */
    public function __construct(
        private int $memoryCost = 65536,
        private int $timeCost = 4,
        private int $threads = 1,
    ) {}

    public function hash(string $password): string
    {
        $this->guardLength($password);

        return password_hash($password, \PASSWORD_ARGON2ID, $this->options());
    }

    public function verify(string $password, string $hash): bool
    {
        if ($hash === '' || \strlen($password) > self::MAX_LENGTH) {
            return false;
        }

        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, \PASSWORD_ARGON2ID, $this->options());
    }

    /**
     * Gegen Benutzerauszaehlung: Bei unbekannter E-Mail wird trotzdem gerechnet,
     * damit die Antwortzeit nichts verraet.
     */
    public function burnCycles(): void
    {
        password_verify('dummy', $this->hash('dummy-passwort-fuer-zeitausgleich'));
    }

    private function guardLength(string $password): void
    {
        $length = mb_strlen($password);

        if ($length < self::MIN_LENGTH) {
            throw new InvalidArgumentException(\sprintf('Das Passwort muss mindestens %d Zeichen haben.', self::MIN_LENGTH));
        }

        if (\strlen($password) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('Das Passwort ist zu lang.');
        }
    }

    /**
     * @return array{memory_cost: int, time_cost: int, threads: int}
     */
    private function options(): array
    {
        return [
            'memory_cost' => $this->memoryCost,
            'time_cost' => $this->timeCost,
            'threads' => $this->threads,
        ];
    }
}
