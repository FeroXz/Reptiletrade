<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal;

/**
 * Eine Regel des Regelwerks. Regeln sind zustandslos, kennen nur ihren eigenen
 * Konfigurationsausschnitt und schreiben ihr Ergebnis in den Entwurf.
 */
interface LegalRule
{
    /**
     * Schluessel aus config/legal_rules.php, z. B. "anhang_a".
     */
    public function key(): string;

    /**
     * Greift die Regel bei diesem Sachverhalt ueberhaupt?
     */
    public function applies(LegalContext $context): bool;

    public function evaluate(LegalContext $context, LegalDecisionDraft $draft): void;
}
