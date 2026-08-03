<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Job;

/**
 * Bearbeitet eine Auftragsart.
 *
 * Ein Handler muss damit rechnen, denselben Auftrag mehrfach zu sehen: Ein
 * Worker kann zwischen Arbeit und Quittung abstuerzen. Deshalb sind alle
 * Handler idempotent geschrieben — zweimal ausgefuehrt darf nicht zweimal
 * wirken.
 */
interface JobHandler
{
    /**
     * Der Auftragstyp, z. B. "listing.expiry_notice".
     */
    public function type(): string;

    /**
     * @return string kurze Zusammenfassung fuers Protokoll
     */
    public function handle(Job $job): string;
}
