<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Listing\ListingRepository;
use Reptilienmarkt\Domain\Message\Conversation;
use Reptilienmarkt\Domain\Message\ConversationRepository;
use Reptilienmarkt\Domain\Message\MessagingException;
use Reptilienmarkt\Domain\Message\MessagingService;
use Reptilienmarkt\Domain\Review\ReviewException;
use Reptilienmarkt\Domain\Review\ReviewService;
use Reptilienmarkt\Domain\Species\SpeciesRepository;
use Reptilienmarkt\Domain\Trust\RateLimitExceededException;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Domain\User\UserRepository;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Reptilienmarkt\Support\Translator;
use Twig\Environment;

/**
 * Das interne Postfach.
 */
final readonly class MessageController
{
    public function __construct(
        private ConversationRepository $conversations,
        private MessagingService $messaging,
        private ReviewService $reviews,
        private ListingRepository $listings,
        private SpeciesRepository $species,
        private UserRepository $users,
        private Viewer $currentUser,
        private SessionManager $session,
        private Translator $translator,
        private Environment $twig,
    ) {}

    public function inbox(Request $request): Response
    {
        $user = $this->currentUser->require();

        return Response::html($this->twig->render('postfach/liste.html.twig', [
            'gespraeche' => $this->conversations->inbox($user->id ?? 0),
            'ungelesen' => $this->conversations->unreadCount($user->id ?? 0),
            'meldungen' => $this->session->takeFlashes(),
            'csrf' => $this->session->csrfToken(),
        ]));
    }

    /**
     * Startet ein Gespraech zu einer Anzeige — der Knopf auf der Detailseite.
     */
    public function start(Request $request): Response
    {
        $user = $this->currentUser->require();
        $this->guardCsrf($request);

        $listingId = $this->requireId($request, 'id');

        try {
            $conversation = $this->messaging->openConversation($listingId, $user, $request->clientIp);
        } catch (MessagingException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect(\sprintf('/anzeige/%d/', $listingId));
        } catch (RateLimitExceededException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect(\sprintf('/anzeige/%d/', $listingId));
        }

        return Response::redirect(\sprintf('/postfach/%d/', $conversation->id));
    }

    public function show(Request $request): Response
    {
        $user = $this->currentUser->require();
        $conversation = $this->requireOwnConversation($request, $user);

        return Response::html($this->twig->render('postfach/gespraech.html.twig', $this->viewData($conversation, $user)));
    }

    public function send(Request $request): Response
    {
        $user = $this->currentUser->require();
        $conversation = $this->requireOwnConversation($request, $user);
        $this->guardCsrf($request);

        $body = $request->body['nachricht'] ?? '';

        try {
            $result = $this->messaging->send(
                $conversation,
                $user,
                \is_string($body) ? $body : '',
                $request->clientIp,
            );

            $this->session->flash($result->sent ? 'erfolg' : 'fehler', $result->message);
        } catch (MessagingException $exception) {
            $this->session->flash('fehler', $exception->getMessage());
        } catch (RateLimitExceededException $exception) {
            $this->session->flash('fehler', $exception->getMessage());
        }

        return Response::redirect(\sprintf('/postfach/%d/', $conversation->id));
    }

    public function confirmDeal(Request $request): Response
    {
        $user = $this->currentUser->require();
        $conversation = $this->requireOwnConversation($request, $user);
        $this->guardCsrf($request);

        try {
            $updated = $this->messaging->confirmDeal($conversation, $user->id ?? 0);

            $this->session->flash('erfolg', $updated->dealConfirmed()
                ? $this->translator->translate('postfach.handel_beidseitig')
                : $this->translator->translate('postfach.handel_wartet'));
        } catch (MessagingException $exception) {
            $this->session->flash('fehler', $exception->getMessage());
        }

        return Response::redirect(\sprintf('/postfach/%d/', $conversation->id));
    }

    public function review(Request $request): Response
    {
        $user = $this->currentUser->require();
        $conversation = $this->requireOwnConversation($request, $user);
        $this->guardCsrf($request);

        $rating = $request->body['sterne'] ?? '';
        $comment = $request->body['kommentar'] ?? '';

        try {
            $this->reviews->submit(
                $conversation,
                $user->id ?? 0,
                is_numeric($rating) ? (int) $rating : 0,
                \is_string($comment) ? $comment : null,
            );

            $this->session->flash('erfolg', $this->translator->translate('bewertung.gespeichert'));
        } catch (ReviewException $exception) {
            $this->session->flash('fehler', $exception->getMessage());
        }

        return Response::redirect(\sprintf('/postfach/%d/', $conversation->id));
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(Conversation $conversation, User $user): array
    {
        $listing = $this->listings->findById($conversation->listingId);
        $counterpart = $this->users->findById($conversation->counterpartOf($user->id ?? 0));

        return [
            'gespraech' => $conversation,
            'nachrichten' => $this->messaging->thread($conversation, $user->id ?? 0),
            'anzeige' => $listing,
            'art' => $listing === null ? null : $this->species->findById($listing->speciesId),
            'gegenueber' => $counterpart,
            'ich_bin_kaeufer' => $conversation->isBuyer($user->id ?? 0),
            'selbst_bestaetigt' => $conversation->hasConfirmed($user->id ?? 0),
            'darf_bewerten' => $this->reviews->mayReview($conversation, $user->id ?? 0),
            'maskierung_bis' => $this->messaging->masker()->firstMessages(),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ];
    }

    private function requireOwnConversation(Request $request, User $user): Conversation
    {
        $conversation = $this->conversations->findById($this->requireId($request, 'id'));

        // 404 statt 403 — ein fremdes Gespraech soll nicht einmal in seiner
        // Existenz bestaetigt werden.
        if ($conversation === null || !$conversation->involves($user->id ?? 0)) {
            throw HttpException::notFound('Gespräch nicht gefunden.');
        }

        return $conversation;
    }

    private function requireId(Request $request, string $name): int
    {
        $value = $request->attribute($name);

        if ($value === null || !ctype_digit($value)) {
            throw HttpException::notFound('Nicht gefunden.');
        }

        return (int) $value;
    }

    private function guardCsrf(Request $request): void
    {
        $this->session->assertCsrf($request);
    }
}
