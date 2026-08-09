<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Message\Message;
use Reptilienmarkt\Domain\Message\MessageRepository;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoMessageRepository implements MessageRepository
{
    private const string COLUMNS = 'id, conversation_id, sender_id, sequence, body, flagged_reason, read_at, created_at';

    public function __construct(private Database $database) {}

    public function findById(int $id): ?Message
    {
        $row = $this->database->selectOne('SELECT ' . self::COLUMNS . ' FROM messages WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->map($row);
    }

    public function forConversation(int $conversationId, int $limit = 200): array
    {
        $rows = $this->database->select(
            'SELECT ' . self::COLUMNS . ' FROM messages WHERE conversation_id = :id ORDER BY sequence ASC LIMIT :limit',
            ['id' => $conversationId, 'limit' => $limit],
        );

        return array_map($this->map(...), $rows);
    }

    /**
     * Die laufende Nummer vergibt die Datenbank, nicht der Aufrufer.
     *
     * Ein aus dem Speicher gerechnetes `messageCount + 1` geht schief, sobald
     * das Conversation-Objekt veraltet ist — beim Doppelklick, in zwei
     * Browsertabs oder wenn der aufrufende Code dasselbe Objekt zweimal
     * verwendet. Das Ergebnis waere eine verletzte Eindeutigkeit und ein
     * Serverfehler statt einer gesendeten Nachricht. Hier ermittelt SQLite die
     * Nummer im selben Statement, in dem es einfuegt; damit kann zwischen
     * Lesen und Schreiben nichts dazwischenkommen.
     */
    public function add(Message $message): int
    {
        $this->database->execute(
            'INSERT INTO messages (conversation_id, sender_id, sequence, body, flagged_reason, created_at)
             SELECT :conversation, :sender,
                    COALESCE((SELECT MAX(sequence) FROM messages WHERE conversation_id = :conversation), 0) + 1,
                    :body, :flagged, :now',
            [
                'conversation' => $message->conversationId,
                'sender' => $message->senderId,
                'body' => $message->body,
                'flagged' => $message->flaggedReason,
                'now' => Timestamp::utcOrNull($message->createdAt) ?? Timestamp::now(),
            ],
        );

        return $this->database->lastInsertId();
    }

    public function markRead(int $conversationId, int $readerId, DateTimeImmutable $at): int
    {
        return $this->database->execute(
            'UPDATE messages SET read_at = :at
              WHERE conversation_id = :id AND sender_id <> :reader AND read_at IS NULL',
            ['at' => Timestamp::utc($at), 'id' => $conversationId, 'reader' => $readerId],
        );
    }

    public function unreadCountInConversation(int $conversationId, int $readerId, ?int $exceptId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM messages
                 WHERE conversation_id = :id AND sender_id <> :reader AND read_at IS NULL';
        $parameter = ['id' => $conversationId, 'reader' => $readerId];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :ausser';
            $parameter['ausser'] = $exceptId;
        }

        $value = $this->database->scalar($sql, $parameter);

        return (int) (is_numeric($value) ? $value : 0);
    }

    public function flagged(int $limit = 50): array
    {
        $rows = $this->database->select(
            'SELECT ' . self::COLUMNS . ' FROM messages WHERE flagged_reason IS NOT NULL
             ORDER BY created_at DESC LIMIT :limit',
            ['limit' => $limit],
        );

        return array_map($this->map(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Message
    {
        return new Message(
            (int) $row['id'],
            (int) $row['conversation_id'],
            (int) $row['sender_id'],
            (int) $row['sequence'],
            (string) $row['body'],
            \is_string($row['flagged_reason']) ? $row['flagged_reason'] : null,
            Timestamp::parse(\is_string($row['read_at']) ? $row['read_at'] : null),
            Timestamp::parse(\is_string($row['created_at']) ? $row['created_at'] : null),
        );
    }
}
