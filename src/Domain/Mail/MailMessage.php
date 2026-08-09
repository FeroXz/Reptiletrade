<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Mail;

final readonly class MailMessage
{
    /**
     * $purpose ordnet die Mail einem Anlass zu (z. B. "konto.verify"). Er
     * steht im Postausgang und macht dort erkennbar, worum es geht, ohne dass
     * jemand den Text lesen muss — im Betrieb schaut man auf Zeilen, nicht auf
     * Inhalte. $userId haengt die Mail an das Konto, damit Auskunft und
     * Kontoloeschung sie finden.
     */
    public function __construct(
        public string $to,
        public string $subject,
        public string $body,
        public ?string $toName = null,
        public string $purpose = 'allgemein',
        public ?int $userId = null,
    ) {}

    public function recipient(): string
    {
        return $this->toName === null ? $this->to : \sprintf('%s <%s>', $this->toName, $this->to);
    }
}
