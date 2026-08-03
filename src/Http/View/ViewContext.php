<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\View;

use Reptilienmarkt\Domain\Message\ConversationRepository;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Http\Session\Viewer;

/**
 * Was jede Seite ueber den Betrachter wissen muss: angemeldet oder nicht,
 * Rolle, ungelesene Nachrichten.
 *
 * Der Zaehler wird erst beim ersten Zugriff geholt — die Marktuebersicht
 * eines nicht angemeldeten Besuchers loest damit keine einzige zusaetzliche
 * Abfrage aus.
 */
final class ViewContext
{
    private bool $unreadResolved = false;

    private int $unread = 0;

    public function __construct(
        private readonly Viewer $viewer,
        private readonly ConversationRepository $conversations,
    ) {}

    public function user(): ?User
    {
        return $this->viewer->get();
    }

    public function isAuthenticated(): bool
    {
        return $this->viewer->isAuthenticated();
    }

    public function isModerator(): bool
    {
        return $this->viewer->get()?->isModerator() ?? false;
    }

    public function unreadMessages(): int
    {
        if ($this->unreadResolved) {
            return $this->unread;
        }

        $this->unreadResolved = true;
        $user = $this->viewer->get();

        if ($user !== null) {
            $this->unread = $this->conversations->unreadCount($user->id ?? 0);
        }

        return $this->unread;
    }
}
