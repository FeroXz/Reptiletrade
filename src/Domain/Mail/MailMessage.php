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
     *
     * @param array<string, string> $headers zusaetzliche Kopfzeilen, z. B.
     *                                       List-Unsubscribe. Der Transport
     *                                       setzt sie, ueberschreibt damit aber
     *                                       nie From, To oder Subject
     */
    public function __construct(
        public string $to,
        public string $subject,
        public string $body,
        public ?string $toName = null,
        public string $purpose = 'allgemein',
        public ?int $userId = null,
        public array $headers = [],
    ) {}

    /**
     * Dieselbe Nachricht mit ergaenztem Text und Kopfzeilen.
     *
     * @param array<string, string> $headers
     */
    public function with(string $body, array $headers): self
    {
        return new self(
            $this->to,
            $this->subject,
            $body,
            $this->toName,
            $this->purpose,
            $this->userId,
            $headers + $this->headers,
        );
    }

    public function recipient(): string
    {
        return $this->toName === null ? $this->to : \sprintf('%s <%s>', $this->toName, $this->to);
    }
}
