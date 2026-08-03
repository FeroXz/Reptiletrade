<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Identity;

use Reptilienmarkt\Domain\User\User;

/**
 * Woher die Nutzeridentitaet kommt.
 *
 * Die Plattform laeuft vollstaendig ohne fremde Identitaetsquelle — die
 * Voreinstellung ist LocalIdentityProvider, der schlicht die eigene
 * users-Tabelle bedient. Dieses Interface ist die Naht, an der eine spaetere
 * Kopplung an das DragonReptiles-CMS ansetzt (OAuth2-Bridge oder gemeinsame
 * Tabelle), ohne dass Anmeldung, Assistent oder Postfach etwas davon merken.
 *
 * Bewusst schmal gehalten: Was hier nicht steht, muss eine Bridge auch nicht
 * liefern.
 */
interface IdentityProvider
{
    /**
     * Kennung der Quelle — fuer Anzeige und Audit-Trail.
     */
    public function name(): string;

    /**
     * Darf sich hier ueberhaupt jemand mit E-Mail und Passwort anmelden?
     * Eine externe Quelle antwortet mit false und leitet stattdessen um.
     */
    public function supportsPasswordLogin(): bool;

    /**
     * Legt die Anmeldeadresse fest, falls die Anmeldung woanders passiert.
     */
    public function loginUrl(?string $returnPath = null): ?string;

    /**
     * Loest eine externe Kennung in ein Konto auf. Der lokale Anbieter kennt
     * keine externen Kennungen und liefert immer null.
     */
    public function findByExternalId(string $externalId): ?User;
}
