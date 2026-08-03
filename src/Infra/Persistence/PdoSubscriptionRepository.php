<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Billing\Subscription;
use Reptilienmarkt\Domain\Billing\SubscriptionRepository;
use Reptilienmarkt\Domain\Billing\SubscriptionStatus;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoSubscriptionRepository implements SubscriptionRepository
{
    private const string COLUMNS = 'id, user_id, plan_key, status, provider, provider_reference, '
        . 'current_period_start, current_period_end, cancel_at_period_end, created_at';

    public function __construct(private Database $database) {}

    public function findById(int $id): ?Subscription
    {
        $row = $this->database->selectOne('SELECT ' . self::COLUMNS . ' FROM subscriptions WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->map($row);
    }

    public function activeForUser(int $userId): ?Subscription
    {
        // Auch "gekuendigt" zaehlt: Das Abo laeuft bis zum Periodenende weiter.
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . " FROM subscriptions
              WHERE user_id = :user AND status IN ('aktiv','gekuendigt','zahlung_offen')
              ORDER BY current_period_end DESC LIMIT 1",
            ['user' => $userId],
        );

        return $row === null ? null : $this->map($row);
    }

    public function findByProviderReference(string $provider, string $reference): ?Subscription
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM subscriptions WHERE provider = :provider AND provider_reference = :ref',
            ['provider' => $provider, 'ref' => $reference],
        );

        return $row === null ? null : $this->map($row);
    }

    public function save(Subscription $subscription): int
    {
        $now = Timestamp::now();

        if ($subscription->id === null) {
            $this->database->execute(
                'INSERT INTO subscriptions
                    (user_id, plan_key, status, provider, provider_reference,
                     current_period_start, current_period_end, cancel_at_period_end, created_at, updated_at)
                 VALUES (:user, :plan, :status, :provider, :ref, :start, :end, :cancel, :now, :now)',
                [
                    'user' => $subscription->userId,
                    'plan' => $subscription->planKey,
                    'status' => $subscription->status->value,
                    'provider' => $subscription->provider,
                    'ref' => $subscription->providerReference,
                    'start' => Timestamp::utc($subscription->currentPeriodStart),
                    'end' => Timestamp::utc($subscription->currentPeriodEnd),
                    'cancel' => $subscription->cancelAtPeriodEnd ? 1 : 0,
                    'now' => $now,
                ],
            );

            return $this->database->lastInsertId();
        }

        $this->database->execute(
            'UPDATE subscriptions SET plan_key = :plan, status = :status, provider = :provider,
                                      provider_reference = :ref, current_period_start = :start,
                                      current_period_end = :end, cancel_at_period_end = :cancel, updated_at = :now
              WHERE id = :id',
            [
                'plan' => $subscription->planKey,
                'status' => $subscription->status->value,
                'provider' => $subscription->provider,
                'ref' => $subscription->providerReference,
                'start' => Timestamp::utc($subscription->currentPeriodStart),
                'end' => Timestamp::utc($subscription->currentPeriodEnd),
                'cancel' => $subscription->cancelAtPeriodEnd ? 1 : 0,
                'now' => $now,
                'id' => $subscription->id,
            ],
        );

        return $subscription->id;
    }

    public function updateStatus(int $id, SubscriptionStatus $status, DateTimeImmutable $at): void
    {
        $this->database->execute(
            'UPDATE subscriptions SET status = :status, updated_at = :now WHERE id = :id',
            ['status' => $status->value, 'now' => Timestamp::utc($at), 'id' => $id],
        );
    }

    public function markCancelAtPeriodEnd(int $id, DateTimeImmutable $at): void
    {
        $this->database->execute(
            "UPDATE subscriptions SET cancel_at_period_end = 1, status = 'gekuendigt', updated_at = :now
              WHERE id = :id",
            ['now' => Timestamp::utc($at), 'id' => $id],
        );
    }

    public function extendPeriod(int $id, DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $at): void
    {
        $this->database->execute(
            'UPDATE subscriptions SET current_period_start = :start, current_period_end = :end, updated_at = :now
              WHERE id = :id',
            [
                'start' => Timestamp::utc($start),
                'end' => Timestamp::utc($end),
                'now' => Timestamp::utc($at),
                'id' => $id,
            ],
        );
    }

    public function expiredBefore(DateTimeImmutable $moment, int $limit = 100): array
    {
        $rows = $this->database->select(
            'SELECT ' . self::COLUMNS . " FROM subscriptions
              WHERE status IN ('aktiv','gekuendigt') AND current_period_end <= :moment
              ORDER BY current_period_end ASC LIMIT :limit",
            ['moment' => Timestamp::utc($moment), 'limit' => $limit],
        );

        return array_map($this->map(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Subscription
    {
        return new Subscription(
            (int) $row['id'],
            (int) $row['user_id'],
            (string) $row['plan_key'],
            SubscriptionStatus::from((string) $row['status']),
            Timestamp::parse((string) $row['current_period_start']) ?? new DateTimeImmutable(),
            Timestamp::parse((string) $row['current_period_end']) ?? new DateTimeImmutable(),
            (bool) $row['cancel_at_period_end'],
            (string) $row['provider'],
            \is_string($row['provider_reference']) ? $row['provider_reference'] : null,
            Timestamp::parse(\is_string($row['created_at']) ? $row['created_at'] : null),
        );
    }
}
