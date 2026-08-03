<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Session;

use Reptilienmarkt\Domain\User\User;

/**
 * Wer schaut gerade zu? Controller haengen an dieser Schnittstelle, nicht an
 * der Sitzungsverwaltung — so laesst sich der Zugriffsschutz pruefen, ohne
 * eine Sitzung aufzubauen.
 */
interface Viewer
{
    public function get(): ?User;

    /**
     * @throws NotAuthenticatedException
     */
    public function require(): User;

    public function isAuthenticated(): bool;
}
