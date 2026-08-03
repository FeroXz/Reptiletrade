<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Support;

use Reptilienmarkt\Domain\Mail\Mailer;
use Reptilienmarkt\Domain\Mail\MailMessage;

/**
 * Sammelt Mails, statt sie zu verschicken — so laesst sich pruefen, was in
 * einem Bestaetigungslink steht.
 */
final class CollectingMailer implements Mailer
{
    /** @var list<MailMessage> */
    private array $messages = [];

    public function send(MailMessage $message): bool
    {
        $this->messages[] = $message;

        return true;
    }

    /**
     * @return list<MailMessage>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    public function clear(): void
    {
        $this->messages = [];
    }
}
