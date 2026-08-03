<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Message;

use Reptilienmarkt\Domain\Audit\AuditEntry;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Domain\Listing\ListingRepository;
use Reptilienmarkt\Domain\Trust\ContactMasker;
use Reptilienmarkt\Domain\Trust\FraudKeywordFilter;
use Reptilienmarkt\Domain\Trust\RateLimiter;
use Reptilienmarkt\Domain\Trust\RateLimitExceededException;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Support\Clock;

/**
 * Das interne Postfach.
 *
 * Hier laufen die drei Schutzmassnahmen zusammen: Rate-Limits gegen
 * massenhaftes Anschreiben, Wortfilter gegen die bekannten Betrugsmuster und
 * Kontaktmaskierung in den ersten Nachrichten. Die Reihenfolge ist bewusst
 * gewaehlt — erst das Limit (billig), dann der Filter (teurer), dann das
 * Schreiben.
 */
final readonly class MessagingService
{
    public const int MAX_BODY_LENGTH = 4000;

    public function __construct(
        private ConversationRepository $conversations,
        private MessageRepository $messages,
        private ListingRepository $listings,
        private RateLimiter $rateLimiter,
        private FraudKeywordFilter $keywords,
        private ContactMasker $masker,
        private AuditLog $audit,
        private Clock $clock,
    ) {}

    /**
     * Startet ein Gespraech zur Anzeige oder nimmt das bestehende auf.
     *
     * @throws MessagingException
     * @throws RateLimitExceededException
     */
    public function openConversation(int $listingId, User $buyer, ?string $ipAddress = null): Conversation
    {
        $listing = $this->listings->findById($listingId);

        if ($listing === null) {
            throw new MessagingException('Diese Anzeige gibt es nicht mehr.');
        }

        if ($listing->userId === ($buyer->id ?? 0)) {
            throw new MessagingException('Du kannst dir nicht selbst schreiben.');
        }

        $existing = $this->conversations->findForListingAndBuyer($listingId, $buyer->id ?? 0);
        if ($existing !== null) {
            return $existing;
        }

        $decision = $this->rateLimiter->attempt('konversation.konto', (string) ($buyer->id ?? 0));
        if (!$decision->allowed) {
            throw new RateLimitExceededException($decision);
        }

        $conversation = new Conversation(
            null,
            $listingId,
            $buyer->id ?? 0,
            $listing->userId,
            ConversationStatus::Offen,
            createdAt: $this->clock->now(),
        );

        $id = $this->conversations->create($conversation);

        $this->audit->record(new AuditEntry(
            'conversation.opened',
            'conversation',
            $id,
            ['listing_id' => $listingId],
            $buyer->id,
            ipAddress: $ipAddress,
        ));

        return $this->conversations->findById($id) ?? $conversation;
    }

    /**
     * @throws MessagingException
     * @throws RateLimitExceededException
     */
    public function send(Conversation $conversation, User $sender, string $body, ?string $ipAddress = null): SendResult
    {
        $senderId = $sender->id ?? 0;

        if (!$conversation->involves($senderId)) {
            throw new MessagingException('Dieses Gespräch gehört nicht zu deinem Konto.');
        }

        if (!$conversation->status->acceptsMessages()) {
            throw new MessagingException('Dieses Gespräch ist geschlossen.');
        }

        $body = trim($body);
        if ($body === '') {
            throw new MessagingException('Die Nachricht ist leer.');
        }

        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw new MessagingException(\sprintf('Die Nachricht darf höchstens %d Zeichen haben.', self::MAX_BODY_LENGTH));
        }

        $this->guardRateLimits($senderId, $ipAddress);

        $verdict = $this->keywords->inspect($body);

        if ($verdict->blocked) {
            $this->audit->record(new AuditEntry(
                'message.blocked',
                'conversation',
                $conversation->id,
                $verdict->auditPayload(),
                $sender->id,
                ipAddress: $ipAddress,
            ));

            return new SendResult(false, null, $verdict, $verdict->senderMessage());
        }

        $now = $this->clock->now();

        $messageId = $this->messages->add(new Message(
            null,
            $conversation->id ?? 0,
            $senderId,
            // Die laufende Nummer vergibt die Ablage beim Einfuegen.
            0,
            $body,
            $verdict->flagged ? $verdict->reasonSummary() : null,
            createdAt: $now,
        ));

        $this->conversations->registerMessage($conversation->id ?? 0, $now);

        if ($verdict->flagged) {
            $this->audit->record(new AuditEntry(
                'message.flagged',
                'message',
                $messageId,
                $verdict->auditPayload(),
                $sender->id,
                ipAddress: $ipAddress,
            ));
        }

        return new SendResult(true, $messageId, $verdict, 'Nachricht gesendet.');
    }

    /**
     * Nachrichten eines Gespraechs, aufbereitet fuer die Anzeige: maskiert,
     * soweit die Maskierung greift, und als gelesen markiert.
     *
     * @return list<DisplayMessage>
     */
    public function thread(Conversation $conversation, int $viewerId): array
    {
        $messages = $this->messages->forConversation($conversation->id ?? 0);
        $this->messages->markRead($conversation->id ?? 0, $viewerId, $this->clock->now());

        $display = [];
        foreach ($messages as $message) {
            $masked = $this->masker->maskForSequence($message->body, $message->sequence);

            $display[] = new DisplayMessage(
                $message,
                $masked,
                $masked !== $message->body,
                $message->isFrom($viewerId),
            );
        }

        return $display;
    }

    /**
     * Bestaetigt den Handel von einer Seite. Erst wenn beide bestaetigt haben,
     * ist eine Bewertung moeglich.
     */
    public function confirmDeal(Conversation $conversation, int $userId): Conversation
    {
        if (!$conversation->involves($userId)) {
            throw new MessagingException('Dieses Gespräch gehört nicht zu deinem Konto.');
        }

        if ($conversation->hasConfirmed($userId)) {
            return $conversation;
        }

        $this->conversations->confirmDeal($conversation->id ?? 0, $conversation->isBuyer($userId), $this->clock->now());

        $updated = $this->conversations->findById($conversation->id ?? 0) ?? $conversation;

        if ($updated->dealConfirmed()) {
            $this->audit->record(new AuditEntry(
                'conversation.deal_confirmed',
                'conversation',
                $conversation->id,
                ['listing_id' => $conversation->listingId],
                $userId,
            ));
        }

        return $updated;
    }

    public function masker(): ContactMasker
    {
        return $this->masker;
    }

    /**
     * @throws RateLimitExceededException
     */
    private function guardRateLimits(int $senderId, ?string $ipAddress): void
    {
        $perAccount = $this->rateLimiter->attempt('nachricht.konto', (string) $senderId);
        if (!$perAccount->allowed) {
            throw new RateLimitExceededException($perAccount);
        }

        if ($ipAddress === null) {
            return;
        }

        $perIp = $this->rateLimiter->attempt('nachricht.ip', $ipAddress);
        if (!$perIp->allowed) {
            throw new RateLimitExceededException($perIp);
        }
    }
}
