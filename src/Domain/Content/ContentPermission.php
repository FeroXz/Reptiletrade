<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;

/**
 * Beantwortet die einzige Berechtigungsfrage des Redaktionssystems.
 *
 * Die Redaktion darf Inhalte und Medien — sonst nichts. Nutzerverwaltung,
 * Moderation, Artenstamm und Betrieb bleiben der Verwaltung vorbehalten. Wer
 * Texte schreibt, braucht keinen Zugriff auf Ausweisscans.
 *
 * Der Zuschnitt ist bewusst grob: eine Rolle, kein Rechtebaum. Ein feineres
 * Modell verlangt jemanden, der es pflegt, und beantwortet in einer Redaktion
 * von zwei bis fuenf Leuten keine Frage, die sich stellt.
 */
final readonly class ContentPermission
{
    public function __construct(private ContentEditorRepository $editors) {}

    public function mayEdit(?User $user): bool
    {
        if ($user === null || !$user->isActive()) {
            return false;
        }

        // Die Verwaltung darf alles — auch ohne Eintrag in content_editors.
        if ($user->role === Role::Admin) {
            return true;
        }

        return $this->editors->isEditor($user->id ?? 0);
    }

    /**
     * Nur die Verwaltung darf Berechtigungen vergeben. Ein Redakteur, der sich
     * Kollegen ernennen kann, ist ein Administrator mit anderem Namen.
     */
    public function mayGrant(?User $user): bool
    {
        return $user !== null && $user->isActive() && $user->role === Role::Admin;
    }
}
