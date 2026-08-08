<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

use Reptilienmarkt\Support\Clock;

/**
 * Vorschaulinks auf Entwuerfe.
 *
 * Der Link geht an jemanden, der sich nicht anmelden kann — die Geschaeftsfuehrung,
 * einen Fachgutachter, den Anwalt. Deshalb drei Eigenschaften:
 *
 * 1. **Nicht erratbar.** 32 Zufallsbytes, nicht die ID des Eintrags.
 * 2. **Nur der Hash liegt in der Datenbank.** Wer sie liest, kann den Link
 *    nicht benutzen — dieselbe Regel wie bei den Einmal-Token in user_tokens.
 * 3. **Kurz gueltig.** 24 Stunden. Ein Vorschaulink, der ein Jahr spaeter noch
 *    geht, ist eine unbemerkte Hintertuer auf jeden Entwurf.
 */
final readonly class PreviewService
{
    public const int LIFETIME_HOURS = 24;

    public function __construct(
        private PreviewTokenRepository $tokens,
        private Clock $clock,
    ) {}

    /**
     * Legt einen Link an und liefert den Klartext-Token. Er ist danach nirgends
     * mehr abrufbar — wer ihn verliert, erzeugt einen neuen.
     */
    public function create(int $entryId, ?int $createdBy): string
    {
        $token = bin2hex(random_bytes(32));
        $now = $this->clock->now();

        $this->tokens->store(
            self::hash($token),
            $entryId,
            $now->modify('+' . self::LIFETIME_HOURS . ' hours'),
            $createdBy,
            $now,
        );

        return $token;
    }

    public function path(string $token): string
    {
        return '/vorschau/' . $token;
    }

    /**
     * @return int|null die ID des Eintrags, oder null bei unbekanntem oder abgelaufenem Link
     */
    public function resolve(string $token): ?int
    {
        // Ein Token, das nicht wie eines aussieht, wird gar nicht erst
        // nachgeschlagen: Das spart die Abfrage und haelt den Vergleich
        // gleichlang.
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }

        return $this->tokens->resolve(self::hash($token), $this->clock->now());
    }

    public function purgeExpired(): int
    {
        return $this->tokens->purgeExpired($this->clock->now());
    }

    /**
     * SHA-256 ohne Salz und ohne Streckung — richtig hier, falsch bei
     * Passwoertern: Der Token hat 256 Bit Zufall, es gibt nichts zu erraten und
     * kein Woerterbuch, gegen das sich streckt.
     */
    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
