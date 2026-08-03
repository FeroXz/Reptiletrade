<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Identity;

use Reptilienmarkt\Domain\User\User;

/**
 * Die Voreinstellung: Konten leben in der eigenen users-Tabelle, angemeldet
 * wird mit E-Mail und Passwort.
 */
final readonly class LocalIdentityProvider implements IdentityProvider
{
    public function name(): string
    {
        return 'local';
    }

    public function supportsPasswordLogin(): bool
    {
        return true;
    }

    /**
     * Der lokale Anbieter hat immer eine Anmeldeseite — null gibt es hier
     * nicht, das Interface laesst es fuer externe Quellen offen.
     */
    public function loginUrl(?string $returnPath = null): string
    {
        return $returnPath === null || !str_starts_with($returnPath, '/')
            ? '/anmelden'
            : '/anmelden?weiter=' . rawurlencode($returnPath);
    }

    public function findByExternalId(string $externalId): ?User
    {
        // Es gibt keine externen Kennungen — das ist keine Fehlersituation.
        return null;
    }
}
