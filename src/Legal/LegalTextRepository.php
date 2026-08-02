<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal;

interface LegalTextRepository
{
    public function find(string $key, string $jurisdiction = 'DE'): ?LegalText;

    /**
     * @return list<LegalText>
     */
    public function all(): array;

    /**
     * Legt an oder aktualisiert anhand (text_key, jurisdiction) und liefert die ID.
     */
    public function save(LegalText $text): int;

    /**
     * Legt nur an, wenn der Schluessel noch nicht existiert — der Seed darf
     * redaktionelle Aenderungen nicht ueberschreiben.
     *
     * @return bool true, wenn ein neuer Text angelegt wurde
     */
    public function insertIfMissing(LegalText $text): bool;

    public function markReviewed(string $key, string $jurisdiction, int $userId): void;
}
