<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\View;

use Reptilienmarkt\Domain\Content\ContentEntryRepository;
use Reptilienmarkt\Domain\Content\ContentPermission;
use Reptilienmarkt\Domain\Content\MenuItem;
use Reptilienmarkt\Domain\Content\MenuRepository;
use Reptilienmarkt\Domain\Content\MenuTargetType;
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
        private readonly MenuRepository $menus,
        private readonly ContentEntryRepository $entries,
    ) {}

    /** @var array<string, list<MenuItem>> */
    private array $resolvedMenus = [];

    /**
     * Die sichtbaren Eintraege eines Menues, mit aufgeloesten Pfaden.
     *
     * Zwischengespeichert je Anfrage: Kopf- und Fussbereich fragen sonst
     * dieselbe Liste zweimal ab. Ein Menue, das es nicht gibt, ergibt eine
     * leere Liste — die Kopfzeile soll nicht deshalb ausfallen.
     *
     * @return list<MenuItem>
     */
    public function menu(string $slug): array
    {
        if (isset($this->resolvedMenus[$slug])) {
            return $this->resolvedMenus[$slug];
        }

        $user = $this->viewer->get();
        $sichtbar = [];

        foreach ($this->menus->items($slug) as $item) {
            if (!$item->visibility->allows($user)) {
                continue;
            }

            $pfad = $this->resolveTarget($item);

            // Ein Eintrag, dessen Inhalt es nicht mehr gibt, verschwindet
            // still aus dem Menue. Ein Verweis ins Leere waere fuer den
            // Besucher schlechter; dass er da ist, meldet bin/doctor.php.
            if ($pfad === '') {
                continue;
            }

            $sichtbar[] = $item->withResolved($pfad, []);
        }

        $this->resolvedMenus[$slug] = $sichtbar;

        return $sichtbar;
    }

    /**
     * Der Pfad hinter einem Menueeintrag. Bei "entry" wird er nachgeschlagen —
     * so wandert er von selbst mit, wenn sich der Slug aendert.
     */
    private function resolveTarget(MenuItem $item): string
    {
        if ($item->targetType !== MenuTargetType::Entry) {
            return $item->targetValue;
        }

        if (!ctype_digit($item->targetValue)) {
            return '';
        }

        $entry = $this->entries->findById((int) $item->targetValue);

        return $entry !== null && $entry->isPublic() ? $entry->path : '';
    }

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
