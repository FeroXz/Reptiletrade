<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\View;

use Reptilienmarkt\Domain\Content\ContentPermission;
use Reptilienmarkt\Domain\Message\ConversationRepository;
use Reptilienmarkt\Domain\User\Role;
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
        private readonly ContentPermission $content,
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

    /**
     * Getrennt von isModerator: Die Verwaltung darf mehr als die Moderation,
     * und der Verweis darauf soll nur dort auftauchen, wo er auch traegt.
     */
    public function isAdmin(): bool
    {
        return $this->viewer->get()?->role === Role::Admin;
    }

    /**
     * Darf der Betrachter die Redaktion bedienen? Getrennt von isAdmin, weil
     * ein Redakteur den Verweis auf /admin/inhalte braucht, den auf /admin/
     * aber nicht — dort duerfte er ohnehin nichts.
     */
    public function isEditor(): bool
    {
        return $this->content->mayEdit($this->viewer->get());
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
