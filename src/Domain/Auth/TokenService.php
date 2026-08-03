<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Auth;

use Reptilienmarkt\Support\Clock;

/**
 * Einmal-Token fuer Passwort-Reset und Verifizierung.
 *
 * Der Klartext existiert genau einmal: beim Ausstellen, auf dem Weg zum
 * Nutzer. In der Datenbank steht nur der SHA-256-Hash. Damit ist ein
 * Datenbank-Leck kein Kontouebernahme-Leck.
 *
 * Bewusst SHA-256 und nicht Argon2id: Der Token ist bereits 256 Bit
 * Zufall — ein langsames Verfahren schuetzt hier vor nichts, kostet aber bei
 * jedem Klick auf einen Bestaetigungslink Rechenzeit.
 */
final readonly class TokenService
{
    public function __construct(
        private TokenRepository $tokens,
        private Clock $clock,
    ) {}

    /**
     * Stellt einen Token aus und entwertet aeltere desselben Typs.
     *
     * @return string der Klartext — danach nicht mehr rekonstruierbar
     */
    public function issue(int $userId, TokenType $type): string
    {
        $now = $this->clock->now();

        $this->tokens->invalidateAll($userId, $type, $now);

        $plain = $type->isNumericCode()
            ? str_pad((string) random_int(0, 999999), 6, '0', \STR_PAD_LEFT)
            : bin2hex(random_bytes(32));

        $this->tokens->store(
            $userId,
            $type,
            self::hash($plain),
            $now->modify(\sprintf('+%d minutes', $type->lifetimeMinutes())),
        );

        return $plain;
    }

    /**
     * Loest einen Token ein. Der Token ist danach verbraucht, auch wenn der
     * aufrufende Vorgang scheitert — ein zweiter Versuch mit demselben Link
     * soll nicht gehen.
     *
     * @throws TokenException
     */
    public function redeem(string $plain, TokenType $expected): TokenRecord
    {
        $record = $this->tokens->findUnusedByHash(self::hash(trim($plain)));

        // Eine gemeinsame Meldung fuer "gibt es nicht", "falscher Typ",
        // "abgelaufen" und "schon benutzt": Der Unterschied hilft nur dem,
        // der Token durchprobiert.
        if ($record === null || $record->type !== $expected || !$record->isUsable($this->clock->now())) {
            throw new TokenException('Dieser Link ist ungültig oder abgelaufen. Bitte fordere einen neuen an.');
        }

        $this->tokens->markUsed($record->id, $this->clock->now());

        return $record;
    }

    public function invalidateAll(int $userId, TokenType $type): void
    {
        $this->tokens->invalidateAll($userId, $type, $this->clock->now());
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
