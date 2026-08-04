<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Contact;

use Reptilienmarkt\Domain\Audit\AuditActorType;
use Reptilienmarkt\Domain\Audit\AuditEntry;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Domain\Mail\Mailer;
use Reptilienmarkt\Domain\Mail\MailMessage;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Support\Translator;

/**
 * Anfragen an die Verwaltung.
 *
 * Sie landen in der Datenbank und gehen zusaetzlich als Mail hinaus. Nur Mail
 * waere zu wenig: Im Postfach sieht niemand, was noch offen ist, und eine
 * verlorene Nachricht faellt erst auf, wenn jemand nachfragt.
 *
 * Angemeldet sein ist keine Bedingung. Ausgerechnet wer nicht mehr hineinkommt —
 * gesperrtes Konto, vergessene Adresse — muss schreiben koennen.
 */
final readonly class ContactService
{
    public const int MAX_SUBJECT = 150;

    public const int MAX_BODY = 5000;

    public function __construct(
        private ContactRepository $messages,
        private Mailer $mailer,
        private Translator $translator,
        private AuditLog $audit,
        private string $adminAddress,
    ) {}

    /**
     * @param array<string, mixed> $input
     *
     * @throws ContactException
     */
    public function submit(array $input, ?User $user, ?string $ipAddress): int
    {
        $name = $this->text($input, 'name', 80);
        $email = $this->text($input, 'email', 190);
        $subject = $this->text($input, 'betreff', self::MAX_SUBJECT);
        $body = $this->text($input, 'nachricht', self::MAX_BODY);
        $topic = ContactTopic::tryFrom($this->text($input, 'thema', 30)) ?? ContactTopic::Frage;

        // Angemeldete muessen ihre Adresse nicht abtippen — und sollen sie
        // auch nicht faelschen koennen.
        if ($user !== null) {
            $name = $user->displayName;
            $email = $user->email;
        }

        if ($name === '') {
            throw new ContactException('Bitte gib einen Namen an.');
        }

        if (filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            throw new ContactException('Bitte gib eine gültige E-Mail-Adresse an, damit wir antworten können.');
        }

        if (mb_strlen($subject) < 3) {
            throw new ContactException('Bitte gib einen Betreff an.');
        }

        if (mb_strlen($body) < 20) {
            throw new ContactException('Bitte beschreibe dein Anliegen in ein paar Sätzen.');
        }

        $id = $this->messages->save(
            new ContactMessage(null, $user?->id, $name, $email, $topic, $subject, $body),
            $ipAddress,
        );

        $this->notify($topic, $subject, $body, $name, $email, $id);

        // Der Inhalt gehoert nicht in den Audit-Trail: Er kann alles enthalten,
        // bis hin zu Gesundheitsangaben, und der Trail wird nie geloescht.
        $this->audit->record(new AuditEntry(
            'contact.received',
            'contact',
            $id,
            ['thema' => $topic->value, 'angemeldet' => $user !== null],
            $user?->id,
            $user === null ? AuditActorType::System : AuditActorType::User,
        ));

        return $id;
    }

    private function notify(ContactTopic $topic, string $subject, string $body, string $name, string $email, int $id): void
    {
        if ($this->adminAddress === '') {
            return;
        }

        $this->mailer->send(new MailMessage(
            $this->adminAddress,
            $this->translator->translate('mail.kontakt.betreff', ['thema' => $topic->label(), 'betreff' => $subject]),
            $this->translator->translate('mail.kontakt.text', [
                'nummer' => $id,
                'thema' => $topic->label(),
                'name' => $name,
                'email' => $email,
                'betreff' => $subject,
                'nachricht' => $body,
            ]),
        ));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function text(array $input, string $key, int $max): string
    {
        $value = $input[$key] ?? '';

        return \is_string($value) ? mb_substr(trim($value), 0, $max) : '';
    }
}
