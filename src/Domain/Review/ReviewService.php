<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Review;

use Reptilienmarkt\Domain\Audit\AuditEntry;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Domain\Message\Conversation;
use Reptilienmarkt\Support\Clock;

/**
 * Bewertungen entstehen ausschliesslich aus einem beidseitig bestaetigten
 * Handel.
 *
 * Das ist die eigentliche Schutzmassnahme: Ohne diese Bedingung liesse sich
 * ein Konto mit erfundenen Bewertungen aufwerten oder ein fremdes mit
 * erfundenen Handeln herabsetzen. Wer bewerten will, muss einen Handel haben,
 * den die Gegenseite ebenfalls bestaetigt hat.
 */
final readonly class ReviewService
{
    public function __construct(
        private ReviewRepository $reviews,
        private AuditLog $audit,
        private Clock $clock,
    ) {}

    /**
     * @throws ReviewException
     */
    public function submit(Conversation $conversation, int $authorId, int $rating, ?string $comment): Review
    {
        if (!$conversation->involves($authorId)) {
            throw new ReviewException('Dieses Gespräch gehört nicht zu deinem Konto.');
        }

        $confirmedAt = $conversation->dealConfirmedAt();
        if ($confirmedAt === null) {
            throw new ReviewException('Bewerten geht erst, wenn beide Seiten den Handel bestätigt haben.');
        }

        if ($rating < Review::MIN_RATING || $rating > Review::MAX_RATING) {
            throw new ReviewException(\sprintf('Die Bewertung muss zwischen %d und %d liegen.', Review::MIN_RATING, Review::MAX_RATING));
        }

        if ($this->reviews->findByListingAndAuthor($conversation->listingId, $authorId) !== null) {
            throw new ReviewException('Zu diesem Handel hast du bereits bewertet.');
        }

        $comment = $comment === null ? null : trim($comment);
        if ($comment === '') {
            $comment = null;
        }

        if ($comment !== null && mb_strlen($comment) > Review::MAX_COMMENT_LENGTH) {
            throw new ReviewException(\sprintf('Der Kommentar darf höchstens %d Zeichen haben.', Review::MAX_COMMENT_LENGTH));
        }

        $review = new Review(
            null,
            $conversation->listingId,
            $authorId,
            $conversation->counterpartOf($authorId),
            $rating,
            $comment,
            $confirmedAt,
            $this->clock->now(),
        );

        $id = $this->reviews->save($review);

        $this->audit->record(new AuditEntry(
            'review.submitted',
            'review',
            $id,
            ['listing_id' => $conversation->listingId, 'bewertung' => $rating],
            $authorId,
        ));

        return $this->reviews->findById($id) ?? $review;
    }

    public function summaryFor(int $userId): ReviewSummary
    {
        $raw = $this->reviews->summaryFor($userId);

        return new ReviewSummary($raw['anzahl'], $raw['schnitt'], $raw['verteilung']);
    }

    /**
     * Die juengsten Bewertungen ueber einen Nutzer.
     *
     * @return list<Review>
     */
    public function recent(int $userId, int $limit = 10): array
    {
        return $this->reviews->forUser($userId, $limit);
    }

    /**
     * Kann dieser Nutzer aus diesem Gespraech heraus bewerten?
     */
    public function mayReview(Conversation $conversation, int $userId): bool
    {
        return $conversation->involves($userId)
            && $conversation->dealConfirmed()
            && $this->reviews->findByListingAndAuthor($conversation->listingId, $userId) === null;
    }
}
