<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Mail;

interface Mailer
{
    /**
     * Stellt eine Nachricht zu. Fehler beim Versand duerfen den aufrufenden
     * Vorgang nicht abbrechen — eine nicht zugestellte Bestaetigungsmail ist
     * aergerlich, ein abgebrochener Registrierungsvorgang schlimmer.
     */
    public function send(MailMessage $message): bool;
}
