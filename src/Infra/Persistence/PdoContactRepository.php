<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Contact\ContactMessage;
use Reptilienmarkt\Domain\Contact\ContactRepository;
use Reptilienmarkt\Domain\Contact\ContactTopic;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoContactRepository implements ContactRepository
{
    private const string COLUMNS = 'id, user_id, name, email, topic, subject, body, status, created_at, '
        . 'handled_at, handled_note';

    public function __construct(private Database $database) {}

    public function save(ContactMessage $message, ?string $ipAddress): int
    {
        $this->database->execute(
            'INSERT INTO contact_messages (user_id, name, email, topic, subject, body, ip_address, created_at)
             VALUES (:user, :name, :email, :topic, :subject, :body, :ip, :now)',
            [
                'user' => $message->userId,
                'name' => $message->name,
                'email' => $message->email,
                'topic' => $message->topic->value,
                'subject' => $message->subject,
                'body' => $message->body,
                'ip' => $ipAddress,
                'now' => Timestamp::now(),
            ],
        );

        return $this->database->lastInsertId();
    }

    public function find(int $id): ?ContactMessage
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM contact_messages WHERE id = :id',
            ['id' => $id],
        );

        return $row === null ? null : $this->map($row);
    }

    public function recent(bool $onlyOpen = true, int $limit = 100): array
    {
        $rows = $this->database->select(
            'SELECT ' . self::COLUMNS . ' FROM contact_messages'
            . ($onlyOpen ? " WHERE status = 'offen'" : '')
            . ' ORDER BY created_at DESC LIMIT :limit',
            ['limit' => $limit],
        );

        return array_map($this->map(...), $rows);
    }

    public function markHandled(int $id, int $adminId, ?string $note): void
    {
        $this->database->execute(
            "UPDATE contact_messages
                SET status = 'erledigt', handled_by = :admin, handled_at = :now, handled_note = :note
              WHERE id = :id",
            ['admin' => $adminId, 'now' => Timestamp::now(), 'note' => $note, 'id' => $id],
        );
    }

    public function openCount(): int
    {
        $value = $this->database->scalar("SELECT COUNT(*) FROM contact_messages WHERE status = 'offen'");

        return (int) (is_numeric($value) ? $value : 0);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): ContactMessage
    {
        return new ContactMessage(
            (int) $row['id'],
            $row['user_id'] === null ? null : (int) $row['user_id'],
            (string) $row['name'],
            (string) $row['email'],
            ContactTopic::from((string) $row['topic']),
            (string) $row['subject'],
            (string) $row['body'],
            (string) $row['status'] === 'erledigt',
            new DateTimeImmutable((string) $row['created_at']),
            \is_string($row['handled_at']) ? new DateTimeImmutable($row['handled_at']) : null,
            \is_string($row['handled_note']) && $row['handled_note'] !== '' ? $row['handled_note'] : null,
        );
    }
}
