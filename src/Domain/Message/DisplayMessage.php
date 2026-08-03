<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Message;

/**
 * Eine Nachricht, wie sie im Browser erscheint. Der maskierte Text ist eine
 * Ansichtssache — in der Datenbank steht weiterhin das Original, weil die
 * Moderation im Missbrauchsfall den echten Wortlaut braucht.
 */
final readonly class DisplayMessage
{
    public function __construct(
        public Message $message,
        public string $body,
        public bool $wasMasked,
        public bool $isOwn,
    ) {}
}
