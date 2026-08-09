<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Message;

use Reptilienmarkt\Domain\Listing\ListingRepository;
use Reptilienmarkt\Domain\Mail\Mailer;
use Reptilienmarkt\Domain\Mail\MailMessage;
use Reptilienmarkt\Domain\Notification\NotificationChannel;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Domain\User\UserRepository;
use Reptilienmarkt\Support\Clock;
use Reptilienmarkt\Support\Translator;

/**
 * Sagt der Gegenseite Bescheid, dass etwas im Postfach liegt.
 *
 * Ohne diesen Weg funktioniert der Marktplatz nicht: Wer angeschrieben wird,
 * erfaehrt es sonst nur, wenn er zufaellig die Seite oeffnet — und der
 * Interessent haelt das ausbleibende Echo fuer Desinteresse.
 *
 * ## Warum die Mail keinen Nachrichtentext enthaelt
 *
 * Telefonnummern und Adressen werden in den ersten Nachrichten maskiert, und
 * die Maskierung greift beim **Anzeigen**, nicht beim Speichern. Eine Mail mit
 * dem Nachrichtentext wuerde die Maskierung also schlicht umgehen — der Text
 * ginge unmaskiert an eine Adresse, die die Plattform nicht kontrolliert, und
 * bliebe dort. Deshalb nur Absendername, Anzeigentitel und Link: Wer lesen
 * will, kommt zurueck ins Postfach, wo die Regeln gelten.
 *
 * ## Warum zusammengefasst statt einzeln
 *
 * Zwei Grenzen, und beide braucht es:
 *
 * 1. **Hoechstens eine Mail je Gespraech und Stunde.** Ein Gespraech ist ein
 *    Hin und Her; ohne die Grenze wuerde ein lebhafter Austausch das Postfach
 *    des anderen fluten und die Mails als Ganzes entwerten.
 * 2. **Keine zweite Mail, solange die erste nicht gelesen ist.** Wer die
 *    vorige Nachricht noch nicht gesehen hat, weiss bereits, dass da etwas
 *    liegt. Eine weitere Mail traegt keine neue Information — sie wiederholt
 *    nur lauter.
 *
 * Die zweite Grenze allein reichte nicht: Wer sofort liest und dann eine
 * Antwort abwartet, bekaeme bei jeder Nachricht wieder eine Mail. Die erste
 * allein reichte auch nicht: Nach einer Stunde Schweigen ist die Nachricht
 * immer noch ungelesen, und eine zweite Mail dazu bringt nichts.
 */
final readonly class MessageNotifier
{
    /**
     * Der Abstand zwischen zwei Mails zum selben Gespraech an dieselbe Seite.
     */
    public const int THROTTLE_MINUTES = 60;

    public function __construct(
        private ConversationRepository $conversations,
        private MessageRepository $messages,
        private ListingRepository $listings,
        private UserRepository $users,
        private Mailer $mailer,
        private Translator $translator,
        private Clock $clock,
        private string $appUrl = 'https://example.tld',
    ) {}

    /**
     * Eine neue Nachricht liegt vor.
     *
     * $newMessageId ist die gerade geschriebene — sie zaehlt beim Pruefen auf
     * Ungelesenes nicht mit, sonst waere die Antwort immer "da ist schon was".
     */
    public function newMessage(Conversation $conversation, User $sender, int $newMessageId): void
    {
        $this->notify($conversation, $sender, $newMessageId, 'mail.nachricht');
    }

    /**
     * Jemand hat ein Gespraech zu einer Anzeige begonnen.
     *
     * Eigener Wortlaut, weil noch keine Nachricht darin steht: "Neue Nachricht"
     * waere gelogen, und der Anbieter faende ein leeres Gespraech vor.
     */
    public function conversationOpened(Conversation $conversation, User $buyer): void
    {
        $this->notify($conversation, $buyer, null, 'mail.gespraech');
    }

    private function notify(Conversation $conversation, User $sender, ?int $newMessageId, string $textKey): void
    {
        $conversationId = $conversation->id ?? 0;
        $empfaengerId = $conversation->counterpartOf($sender->id ?? 0);

        if ($conversationId === 0 || $empfaengerId === 0) {
            return;
        }

        if ($this->throttled($conversation, $empfaengerId)) {
            return;
        }

        if ($this->messages->unreadCountInConversation($conversationId, $empfaengerId, $newMessageId) > 0) {
            return;
        }

        $empfaenger = $this->users->findById($empfaengerId);

        if ($empfaenger === null || $empfaenger->email === '') {
            return;
        }

        $listing = $this->listings->findById($conversation->listingId);

        $platzhalter = [
            'name' => $empfaenger->displayName,
            'absender' => $sender->displayName,
            'anzeige' => $listing === null ? '' : $listing->title,
            'link' => \sprintf('%s/postfach/%d/', rtrim($this->appUrl, '/'), $conversationId),
        ];

        $this->mailer->send(new MailMessage(
            $empfaenger->email,
            $this->translator->translate($textKey . '.betreff', $platzhalter),
            $this->translator->translate($textKey . '.text', $platzhalter),
            $empfaenger->displayName,
            NotificationChannel::NachrichtNeu->value,
            $empfaengerId,
        ));

        // Auch dann vermerken, wenn der Kanal abgeschaltet ist und die Mail
        // gar nicht eingereiht wurde: Der Vermerk beantwortet die Frage "wann
        // habe ich es zuletzt versucht", und ein Versuch war es.
        $this->conversations->markNotified(
            $conversationId,
            $conversation->isBuyer($empfaengerId),
            $this->clock->now(),
        );
    }

    private function throttled(Conversation $conversation, int $empfaengerId): bool
    {
        $zuletzt = $conversation->notifiedAtFor($empfaengerId);

        if ($zuletzt === null) {
            return false;
        }

        $vergangen = ($this->clock->now()->getTimestamp() - $zuletzt->getTimestamp()) / 60;

        return $vergangen < self::THROTTLE_MINUTES;
    }
}
