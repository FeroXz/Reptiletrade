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
     * Der Abmeldetoken des Kontos — bei jedem Aufruf derselbe.
     *
     * Abgeleitet statt gespeichert: Der Token ist die user_id und ein
     * HMAC-SHA-256 darueber, gebildet mit einem konto-eigenen Geheimnis
     * (users.unsubscribe_secret). Damit steht in der Datenbank weiterhin kein
     * Klartexttoken, der Link ist aber jederzeit reproduzierbar — er muss es
     * sein, weil die Mail im Job-Worker gebaut wird und nicht im Request, der
     * den Anlass ausgeloest hat. Der frueher uebliche Weg, pro Versand einen
     * neuen Zufallstoken auszustellen, entwertete den Abmeldelink jeder
     * aelteren Mail und machte damit genau den Knopf kaputt, den Mailprogramme
     * anbieten.
     *
     * Ungueltig machen bleibt moeglich, aber bewusst: Nur ein Wechsel des
     * Geheimnisses (rotateUnsubscribeSecret) entwertet die Links — an der
     * Kontoloeschung und auf Knopfdruck, nicht bei jedem Versand.
     */
    public function issueUnsubscribeToken(int $userId): string
    {
        $secret = $this->preferences->unsubscribeSecret($userId);

        if ($secret === null) {
            // Der Regelfall ist das nicht: Neue Konten bekommen ihr Geheimnis
            // beim Anlegen, Bestandskonten haben es aus der Migration. Bleibt
            // eine Zeile uebrig, die auf anderem Weg entstanden ist, waere die
            // Alternative eine Ausnahme — und damit eine Mail, die wegen ihres
            // Abmeldelinks nicht rausgeht. Einmal nachziehen ist der bessere
            // Tausch; ein zweites Mal kommt es hier nicht vorbei.
            $secret = self::newSecret();
            $this->preferences->storeUnsubscribeSecret($userId, $secret, $this->clock->now());
        }

        return $userId . '-' . self::signature($userId, $secret);
    }

    /**
     * Wechselt das Geheimnis und entwertet damit alle Abmeldelinks des Kontos.
     *
     * Der bewusste Gegenpol zum stabilen Token: Wer den Verdacht hat, dass eine
     * alte Mail in fremde Haende geraten ist, macht hier alle Links auf einmal
     * ungueltig. Abmelden kann ein fremder Link ohnehin nur Kanaele des eigenen
     * Kontos, aber "kann nichts Schlimmes" ist kein Grund, es nicht abstellen
     * zu koennen.
     */
    public function rotateUnsubscribeSecret(int $userId, ?int $actorId = null): void
    {
        $this->preferences->storeUnsubscribeSecret($userId, self::newSecret(), $this->clock->now());

        $this->audit->record(new AuditEntry(
            'notification.unsubscribe_secret_rotated',
            'user',
            $userId,
            [],
            $actorId ?? $userId,
        ));
    }

    /**
     * Prueft Token und Kanal, **ohne etwas zu aendern**.
     *
     * Fuer die Bestaetigungsseite, die der Abmeldelink zeigt: Sie muss den
     * Kanal nennen duerfen und dieselben Meldungen geben wie die Abmeldung
     * selbst — deshalb steht der Wortlaut hier und nicht zweimal.
     *
     * @return int das betroffene Konto
     *
     * @throws NotificationException
     */
    public function requireAccountForToken(string $plainToken, NotificationChannel $channel): int
    {
        if ($channel->isMandatory()) {
            throw new NotificationException(
                'Diese Nachrichten lassen sich nicht abbestellen — sie betreffen dein Konto und deine Rechte.',
            );
        }

        $userId = $this->accountForToken($plainToken);

        // Eine gemeinsame Meldung fuer "gibt es nicht" und "entwertet": Der
        // Unterschied hilft nur dem, der Token durchprobiert.
        if ($userId === null) {
            throw new NotificationException(
                'Dieser Abmeldelink ist nicht mehr gültig. Melde dich an, um deine Benachrichtigungen einzustellen.',
            );
        }

        return $userId;
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
        $userId = $this->requireAccountForToken($plainToken, $channel);

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
     * Das Konto zu einem Abmeldetoken — oder null, wenn er nicht passt.
     *
     * Nachgeschlagen wird ueber die user_id aus dem Token; erst danach
     * entscheidet der Vergleich der Signatur. Wer eine fremde id einsetzt,
     * kommt an dieser Stelle nicht weiter, weil er das Geheimnis des Kontos
     * nicht kennt.
     */
    public function accountForToken(string $plainToken): ?int
    {
        $teile = explode('-', trim($plainToken), 2);

        if (\count($teile) !== 2 || $teile[0] === '' || !ctype_digit($teile[0]) || $teile[1] === '') {
            return null;
        }

        $userId = (int) $teile[0];
        $secret = $this->preferences->unsubscribeSecret($userId);

        if ($secret === null) {
            return null;
        }

        // hash_equals statt "===": Die Laufzeit des Vergleichs soll nicht
        // verraten, wie viele Zeichen einer geratenen Signatur stimmen.
        return hash_equals(self::signature($userId, $secret), $teile[1]) ? $userId : null;
    }

    /**
     * 256 Bit — dieselbe Groesse wie bei den uebrigen Geheimnissen im Bestand.
     */
    private static function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * HMAC-SHA-256 statt schlichtem Hash: Der Token soll sich nur mit dem
     * Geheimnis bilden lassen, nicht aus der oeffentlich bekannten user_id.
     */
    private static function signature(int $userId, string $secret): string
    {
        return hash_hmac('sha256', (string) $userId, $secret);
    }
}
