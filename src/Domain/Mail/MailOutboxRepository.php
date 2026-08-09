<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Mail;

use DateTimeImmutable;

interface MailOutboxRepository
{
    /**
     * Reiht eine Nachricht ein und liefert deren Kennung.
     */
    public function queue(MailMessage $message, DateTimeImmutable $now): int;

    /**
     * Was noch zuzustellen ist, in Eingangsreihenfolge.
     *
     * @return list<MailOutboxEntry>
     */
    public function due(int $limit = 50): array;

    public function markSent(int $id, DateTimeImmutable $at): void;

    /**
     * Ein Versuch ist gescheitert, ein weiterer folgt.
     */
    public function markRetry(int $id, string $error, DateTimeImmutable $at): void;

    /**
     * Endgueltig aufgegeben. Die Zeile bleibt stehen — sie ist der einzige
     * Beleg dafuer, dass jemand seine Mail nicht bekommen hat.
     */
    public function markFailed(int $id, string $error, DateTimeImmutable $at): void;

    /**
     * Wie viele Mails laenger als bis $before im Ausgang liegen. Das ist die
     * Frage, die bin/doctor.php stellt: Ein stiller Postausgang ist nicht
     * derselbe Zustand wie ein voller.
     */
    public function countPendingBefore(DateTimeImmutable $before): int;

    /**
     * Die zuletzt endgueltig gescheiterten Mails — fuer das Dashboard.
     *
     * @return list<MailOutboxEntry>
     */
    public function recentFailures(int $limit = 10): array;
}
