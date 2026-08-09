<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Notification;

use Reptilienmarkt\Domain\Audit\AuditActorType;
use Reptilienmarkt\Domain\Audit\AuditEntry;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Support\Clock;

/**
 * Wer bekommt welche Mail — und wie kommt man wieder heraus.
 *
 * Diese Schicht steht vor jedem Versand, der nicht system.wichtig ist. Sie ist
 * bewusst die einzige Stelle, die darueber entscheidet: Eine Abfrage, die im
 * aufrufenden Dienst steht, wird beim naechsten neuen Mailanlass vergessen.
 */
final readonly class NotificationPreferenceService
{
    public function __construct(
        private NotificationPreferenceRepository $preferences,
        private AuditLog $audit,
        private Clock $clock,
    ) {}

    public function mayNotify(int $userId, NotificationChannel $channel): bool
    {
        if ($channel->isMandatory()) {
            return true;
        }

        return $this->preferences->forUser($userId)[$channel->value] ?? $channel->defaultEnabled();
    }

    /**
     * Der Zustand aller Kanaele, auch der nie angefassten.
     *
     * @return array<string, bool>
     */
    public function all(int $userId): array
    {
        $gesetzt = $this->preferences->forUser($userId);
        $zustand = [];

        foreach (NotificationChannel::cases() as $kanal) {
            $zustand[$kanal->value] = $kanal->isMandatory()
                ? true
                : ($gesetzt[$kanal->value] ?? $kanal->defaultEnabled());
        }

        return $zustand;
    }

    /**
     * Uebernimmt ein abgeschicktes Formular.
     *
     * Erwartet die Schluessel der **angehakten** Kaestchen. Ein nicht
     * angehaktes Kaestchen schickt der Browser nicht mit — deshalb zaehlt hier
     * die Abwesenheit als "aus" und nicht als "unveraendert".
     *
     * @param list<string> $enabledKeys
     */
    public function save(int $userId, array $enabledKeys, ?int $actorId = null): void
    {
        $now = $this->clock->now();
        $geaendert = [];

        foreach (NotificationChannel::selectable() as $kanal) {
            $an = \in_array($kanal->value, $enabledKeys, true);

            if ($this->mayNotify($userId, $kanal) === $an) {
                continue;
            }

            $this->preferences->set($userId, $kanal, $an, $now);
            $geaendert[$kanal->value] = $an;
        }

        if ($geaendert === []) {
            return;
        }

        $this->audit->record(new AuditEntry(
            'notification.preferences_changed',
            'user',
            $userId,
            ['kanaele' => $geaendert],
            $actorId ?? $userId,
        ));
    }

    /**
     * Stellt einen Abmeldetoken aus und liefert den Klartext.
     *
     * Der vorige Token gilt danach nicht mehr. Das ist kein Versehen, sondern
     * die Folge davon, dass in der Datenbank nur der Hash steht: Der Klartext
     * laesst sich nicht rekonstruieren, also muss jede Mail ihren eigenen
     * bekommen. Der Preis ist, dass der Abmeldelink einer aelteren Mail ins
     * Leere laeuft — die Bestaetigungsseite sagt das und verweist auf die
     * Einstellungen, statt den Nutzer ratlos stehen zu lassen.
     */
    public function issueUnsubscribeToken(int $userId): string
    {
        $plain = bin2hex(random_bytes(32));

        $this->preferences->storeUnsubscribeHash($userId, self::hash($plain), $this->clock->now());

        return $plain;
    }

    /**
     * Schaltet genau einen Kanal ab — ohne Anmeldung, allein ueber den Token.
     *
     * @return int das betroffene Konto
     *
     * @throws NotificationException
     */
    public function unsubscribe(string $plainToken, NotificationChannel $channel): int
    {
        if ($channel->isMandatory()) {
            throw new NotificationException(
                'Diese Nachrichten lassen sich nicht abbestellen — sie betreffen dein Konto und deine Rechte.',
            );
        }

        $userId = $this->preferences->findUserIdByUnsubscribeHash(self::hash(trim($plainToken)));

        // Eine gemeinsame Meldung fuer "gibt es nicht" und "ueberholt": Der
        // Unterschied hilft nur dem, der Token durchprobiert.
        if ($userId === null) {
            throw new NotificationException(
                'Dieser Abmeldelink ist nicht mehr gültig. Melde dich an, um deine Benachrichtigungen einzustellen.',
            );
        }

        $this->preferences->set($userId, $channel, false, $this->clock->now());

        // Ohne Anmeldung gibt es keinen handelnden Menschen im Sinne der
        // Sitzung — gehandelt hat der Kontoinhaber ueber seinen Token.
        $this->audit->record(new AuditEntry(
            'notification.unsubscribed',
            'user',
            $userId,
            ['kanal' => $channel->value],
            $userId,
            AuditActorType::User,
        ));

        return $userId;
    }

    /**
     * SHA-256 wie bei den uebrigen Token: Der Wert ist schon 256 Bit Zufall,
     * ein langsames Verfahren schuetzt hier vor nichts.
     */
    private static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
