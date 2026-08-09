<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

interface MediaUsageRepository
{
    /**
     * Schreibt die Verwendungen eines Ziels neu.
     *
     * Ersetzen statt einzeln pflegen: Wer einen Block loescht, denkt nicht an
     * die Verwendungstabelle — und eine Verwendung, die stehenbleibt, sperrt
     * ein Bild fuer immer gegen das Loeschen.
     *
     * @param list<int> $mediaIds
     */
    public function replaceFor(MediaUsageContext $context, int $contextId, array $mediaIds): void;

    /**
     * Wo wird dieses Medium verwendet?
     *
     * @return list<array{context: MediaUsageContext, context_id: int, titel: string, pfad: string}>
     */
    public function forMedia(int $mediaId): array;

    /**
     * Wie viele Verwendungen hat jedes dieser Medien? Fuer die Uebersicht, die
     * sonst je Kachel eine Abfrage brauchte.
     *
     * @param list<int> $mediaIds
     *
     * @return array<int, int> Medien-ID => Anzahl
     */
    public function countsFor(array $mediaIds): array;

    public function isUsed(int $mediaId): bool;
}
