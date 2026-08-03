<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Support;

use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Http\Session\NotAuthenticatedException;
use Reptilienmarkt\Http\Session\Viewer;

/**
 * Betrachter ohne Sitzung — so laesst sich der Zugriffsschutz eines
 * Controllers pruefen, ohne HTTP und Cookies aufzubauen.
 */
final class StubViewer implements Viewer
{
    public function __construct(private readonly ?User $viewer) {}

    public function get(): ?User
    {
        return $this->viewer;
    }

    public function require(): User
    {
        return $this->viewer ?? throw new NotAuthenticatedException();
    }

    public function isAuthenticated(): bool
    {
        return $this->viewer !== null;
    }
}
