<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Message\Conversation;
use Reptilienmarkt\Domain\Message\ConversationRepository;
use Reptilienmarkt\Domain\Message\ConversationStatus;
use Reptilienmarkt\Domain\Message\ConversationSummary;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoConversationRepository implements ConversationRepository
{
    private const string COLUMNS = 'id, listing_id, buyer_id, seller_id, status, deal_confirmed_buyer_at, '
        . 'deal_confirmed_seller_at, message_count, created_at, last_message_at';

    public function __construct(private Database $database) {}

    public function findById(int $id): ?Conversation
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM conversations WHERE id = :id',
            ['id' => $id],
        );

        return $row === null ? null : $this->map($row);
    }

    public function findForListingAndBuyer(int $listingId, int $buyerId): ?Conversation
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM conversations WHERE listing_id = :listing AND buyer_id = :buyer',
            ['listing' => $listingId, 'buyer' => $buyerId],
        );

        return $row === null ? null : $this->map($row);
    }

    public function create(Conversation $conversation): int
    {
        $this->database->execute(
            'INSERT INTO conversations (listing_id, buyer_id, seller_id, status, created_at)
             VALUES (:listing, :buyer, :seller, :status, :now)',
            [
                'listing' => $conversation->listingId,
                'buyer' => $conversation->buyerId,
                'seller' => $conversation->sellerId,
                'status' => $conversation->status->value,
                'now' => Timestamp::utcOrNull($conversation->createdAt) ?? Timestamp::now(),
            ],
        );

        return $this->database->lastInsertId();
    }

    public function inbox(int $userId, int $limit = 50, int $offset = 0): array
    {
        // Eine Abfrage fuer die ganze Liste: Titel, Gegenueber, Titelbild,
        // Ungelesenzaehler und Vorschau der letzten Nachricht.
        $rows = $this->database->select(
            <<<'SQL'
                SELECT c.id,
                       c.listing_id,
                       c.status,
                       c.message_count,
                       c.last_message_at,
                       c.buyer_id,
                       c.seller_id,
                       c.deal_confirmed_buyer_at,
                       c.deal_confirmed_seller_at,
                       l.title AS listing_title,
                       (SELECT m.path FROM listing_media m
                         WHERE m.listing_id = c.listing_id AND m.media_type = 'bild' AND m.is_primary = 1
                         LIMIT 1) AS listing_image,
                       CASE WHEN c.buyer_id = :user THEN c.seller_id ELSE c.buyer_id END AS counterpart_id,
                       (SELECT u.display_name FROM users u
                         WHERE u.id = CASE WHEN c.buyer_id = :user THEN c.seller_id ELSE c.buyer_id END) AS counterpart_name,
                       (SELECT COUNT(*) FROM messages m
                         WHERE m.conversation_id = c.id AND m.sender_id <> :user AND m.read_at IS NULL) AS unread,
                       (SELECT m.body FROM messages m
                         WHERE m.conversation_id = c.id
                         ORDER BY m.sequence DESC LIMIT 1) AS last_body
                  FROM conversations c
                  JOIN listings l ON l.id = c.listing_id
                 WHERE c.buyer_id = :user OR c.seller_id = :user
                 ORDER BY COALESCE(c.last_message_at, c.created_at) DESC, c.id DESC
                 LIMIT :limit OFFSET :offset
                SQL,
            ['user' => $userId, 'limit' => $limit, 'offset' => $offset],
        );

        $summaries = [];

        foreach ($rows as $row) {
            $isBuyer = (int) $row['buyer_id'] === $userId;
            $ownConfirmation = $isBuyer
                ? $row['deal_confirmed_buyer_at'] !== null
                : $row['deal_confirmed_seller_at'] !== null;

            $body = $row['last_body'];

            $summaries[] = new ConversationSummary(
                (int) $row['id'],
                (int) $row['listing_id'],
                (string) $row['listing_title'],
                \is_string($row['listing_image']) ? $row['listing_image'] : null,
                (int) $row['counterpart_id'],
                \is_string($row['counterpart_name']) ? $row['counterpart_name'] : 'Gelöschtes Konto',
                ConversationStatus::from((string) $row['status']),
                (int) $row['message_count'],
                (int) $row['unread'],
                \is_string($body) ? mb_substr(trim($body), 0, 120) : null,
                Timestamp::parse(\is_string($row['last_message_at']) ? $row['last_message_at'] : null),
                $row['deal_confirmed_buyer_at'] !== null && $row['deal_confirmed_seller_at'] !== null,
                $ownConfirmation,
            );
        }

        return $summaries;
    }

    public function unreadCount(int $userId): int
    {
        $value = $this->database->scalar(
            <<<'SQL'
                SELECT COUNT(*)
                  FROM messages m
                  JOIN conversations c ON c.id = m.conversation_id
                 WHERE m.sender_id <> :user
                   AND m.read_at IS NULL
                   AND (c.buyer_id = :user OR c.seller_id = :user)
                SQL,
            ['user' => $userId],
        );

        return (int) (is_numeric($value) ? $value : 0);
    }

    public function confirmDeal(int $conversationId, bool $asBuyer, DateTimeImmutable $at): void
    {
        $column = $asBuyer ? 'deal_confirmed_buyer_at' : 'deal_confirmed_seller_at';

        $this->database->execute(
            \sprintf('UPDATE conversations SET %s = :at WHERE id = :id AND %s IS NULL', $column, $column),
            ['at' => Timestamp::utc($at), 'id' => $conversationId],
        );
    }

    public function updateStatus(int $conversationId, ConversationStatus $status): void
    {
        $this->database->execute(
            'UPDATE conversations SET status = :status WHERE id = :id',
            ['status' => $status->value, 'id' => $conversationId],
        );
    }

    public function registerMessage(int $conversationId, DateTimeImmutable $at): void
    {
        $this->database->execute(
            'UPDATE conversations SET message_count = message_count + 1, last_message_at = :at WHERE id = :id',
            ['at' => Timestamp::utc($at), 'id' => $conversationId],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Conversation
    {
        return new Conversation(
            (int) $row['id'],
            (int) $row['listing_id'],
            (int) $row['buyer_id'],
            (int) $row['seller_id'],
            ConversationStatus::from((string) $row['status']),
            Timestamp::parse(\is_string($row['deal_confirmed_buyer_at']) ? $row['deal_confirmed_buyer_at'] : null),
            Timestamp::parse(\is_string($row['deal_confirmed_seller_at']) ? $row['deal_confirmed_seller_at'] : null),
            (int) $row['message_count'],
            Timestamp::parse(\is_string($row['created_at']) ? $row['created_at'] : null),
            Timestamp::parse(\is_string($row['last_message_at']) ? $row['last_message_at'] : null),
        );
    }
}
