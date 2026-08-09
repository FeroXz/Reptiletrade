<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Mail\MailMessage;
use Reptilienmarkt\Domain\Mail\MailOutboxEntry;
use Reptilienmarkt\Domain\Mail\MailOutboxRepository;
use Reptilienmarkt\Domain\Mail\MailStatus;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoMailOutboxRepository implements MailOutboxRepository
{
    private const string COLUMNS = 'id, recipient, recipient_name, subject, body, purpose, user_id, '
        . 'status, attempts, last_error, created_at, sent_at';

    public function __construct(private Database $database) {}

    public function queue(MailMessage $message, DateTimeImmutable $now): int
    {
        $stamp = Timestamp::utc($now);

        $this->database->execute(
            'INSERT INTO mail_outbox (recipient, recipient_name, subject, body, purpose, user_id, created_at, updated_at)
             VALUES (:recipient, :name, :subject, :body, :purpose, :user_id, :now, :now)',
            [
                'recipient' => $message->to,
                'name' => $message->toName,
                'subject' => $message->subject,
                'body' => $message->body,
                'purpose' => $message->purpose,
                'user_id' => $message->userId,
                'now' => $stamp,
            ],
        );

        return $this->database->lastInsertId();
    }

    public function due(int $limit = 50): array
    {
        $rows = $this->database->select(
            'SELECT ' . self::COLUMNS . " FROM mail_outbox WHERE status = 'wartend' ORDER BY id LIMIT :limit",
            ['limit' => $limit],
        );

        return array_map($this->map(...), $rows);
    }

    public function markSent(int $id, DateTimeImmutable $at): void
    {
        $stamp = Timestamp::utc($at);

        $this->database->execute(
            "UPDATE mail_outbox
                SET status = 'gesendet', attempts = attempts + 1, last_error = NULL, sent_at = :now, updated_at = :now
              WHERE id = :id AND status = 'wartend'",
            ['id' => $id, 'now' => $stamp],
        );
    }

    public function markRetry(int $id, string $error, DateTimeImmutable $at): void
    {
        $this->database->execute(
            "UPDATE mail_outbox
                SET attempts = attempts + 1, last_error = :error, updated_at = :now
              WHERE id = :id AND status = 'wartend'",
            ['id' => $id, 'error' => self::shorten($error), 'now' => Timestamp::utc($at)],
        );
    }

    public function markFailed(int $id, string $error, DateTimeImmutable $at): void
    {
        $this->database->execute(
            "UPDATE mail_outbox
                SET status = 'fehlgeschlagen', attempts = attempts + 1, last_error = :error, updated_at = :now
              WHERE id = :id AND status = 'wartend'",
            ['id' => $id, 'error' => self::shorten($error), 'now' => Timestamp::utc($at)],
        );
    }

    public function countPendingBefore(DateTimeImmutable $before): int
    {
        return (int) (string) $this->database->scalar(
            "SELECT COUNT(*) FROM mail_outbox WHERE status = 'wartend' AND created_at < :vor",
            ['vor' => Timestamp::utc($before)],
        );
    }

    public function recentFailures(int $limit = 10): array
    {
        $rows = $this->database->select(
            'SELECT ' . self::COLUMNS . " FROM mail_outbox WHERE status = 'fehlgeschlagen' ORDER BY id DESC LIMIT :limit",
            ['limit' => $limit],
        );

        return array_map($this->map(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): MailOutboxEntry
    {
        $name = $row['recipient_name'];
        $fehler = $row['last_error'];

        return new MailOutboxEntry(
            (int) $row['id'],
            new MailMessage(
                (string) $row['recipient'],
                (string) $row['subject'],
                (string) $row['body'],
                \is_string($name) ? $name : null,
                (string) $row['purpose'],
                $row['user_id'] === null ? null : (int) $row['user_id'],
            ),
            MailStatus::from((string) $row['status']),
            (int) $row['attempts'],
            \is_string($fehler) ? $fehler : null,
            Timestamp::parse((string) $row['created_at']) ?? new DateTimeImmutable('@0'),
            Timestamp::parse(\is_string($row['sent_at']) ? $row['sent_at'] : null),
        );
    }

    /**
     * Der Fehlertext geht ins Dashboard und ins Protokoll. Ein SMTP-Server,
     * der bei einer Ablehnung seine halbe Hilfeseite mitschickt, soll die
     * Spalte nicht sprengen.
     */
    private static function shorten(string $error): string
    {
        return mb_substr(trim($error), 0, 500);
    }
}
