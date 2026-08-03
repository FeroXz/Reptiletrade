<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Session;

use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Domain\User\UserRepository;

/**
 * Loest den angemeldeten Nutzer aus der Sitzung auf. Einmal je Anfrage,
 * danach zwischengespeichert.
 */
final class CurrentUser implements Viewer
{
    private ?User $user = null;

    private bool $resolved = false;

    public function __construct(
        private readonly SessionManager $session,
        private readonly UserRepository $users,
    ) {}

    public function get(): ?User
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;
        $userId = $this->session->userId();

        if ($userId === null) {
            return null;
        }

        $user = $this->users->findById($userId);

        // Konto geloescht oder gesperrt? Dann ist die Sitzung wertlos.
        $this->user = $user !== null && $user->isActive() ? $user : null;

        return $this->user;
    }

    /**
     * @throws NotAuthenticatedException
     */
    public function require(): User
    {
        $user = $this->get();

        if ($user === null) {
            throw new NotAuthenticatedException();
        }

        return $user;
    }

    public function isAuthenticated(): bool
    {
        return $this->get() !== null;
    }
}
