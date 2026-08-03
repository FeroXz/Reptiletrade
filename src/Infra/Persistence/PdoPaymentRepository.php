<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Billing\Money;
use Reptilienmarkt\Domain\Billing\Payment;
use Reptilienmarkt\Domain\Billing\PaymentPurpose;
use Reptilienmarkt\Domain\Billing\PaymentRepository;
use Reptilienmarkt\Domain\Billing\PaymentStatus;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoPaymentRepository implements PaymentRepository
{
    private const string COLUMNS = 'id, user_id, purpose, reference_type, reference_id, amount_cents, currency, '
        . 'tax_rate, status, provider, provider_reference, paid_at, created_at';

    public function __construct(private Database $database) {}

    public function findById(int $id): ?Payment
    {
        $row = $this->database->selectOne('SELECT ' . self::COLUMNS . ' FROM payments WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->map($row);
    }

    public function findByProviderReference(string $provider, string $reference): ?Payment
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM payments WHERE provider = :provider AND provider_reference = :ref',
            ['provider' => $provider, 'ref' => $reference],
        );

        return $row === null ? null : $this->map($row);
    }

    public function save(Payment $payment): int
    {
        if ($payment->id === null) {
            $this->database->execute(
                'INSERT INTO payments
                    (user_id, purpose, reference_type, reference_id, amount_cents, currency, tax_rate,
                     status, provider, provider_reference, paid_at, created_at)
                 VALUES (:user, :purpose, :ref_type, :ref_id, :amount, :currency, :tax,
                         :status, :provider, :provider_ref, :paid, :now)',
                $this->parameters($payment),
            );

            return $this->database->lastInsertId();
        }

        // Bewusst nur die Felder, die sich vor der Zahlung noch aendern:
        // Betrag und Zweck eines Belegs stehen fest.
        $this->database->execute(
            'UPDATE payments SET status = :status, provider = :provider, provider_reference = :provider_ref,
                                 paid_at = :paid
              WHERE id = :id',
            [
                'status' => $payment->status->value,
                'provider' => $payment->provider,
                'provider_ref' => $payment->providerReference,
                'paid' => Timestamp::utcOrNull($payment->paidAt),
                'id' => $payment->id,
            ],
        );

        return $payment->id;
    }

    public function markPaid(int $id, DateTimeImmutable $at): void
    {
        // Nur einmal: Ein wiederholt zugestellter Webhook darf keinen zweiten
        // Zahlungszeitpunkt setzen.
        $this->database->execute(
            "UPDATE payments SET status = 'bezahlt', paid_at = :now WHERE id = :id AND status <> 'bezahlt'",
            ['now' => Timestamp::utc($at), 'id' => $id],
        );
    }

    public function updateStatus(int $id, PaymentStatus $status): void
    {
        $this->database->execute(
            'UPDATE payments SET status = :status WHERE id = :id',
            ['status' => $status->value, 'id' => $id],
        );
    }

    public function forUser(int $userId, int $limit = 50): array
    {
        $rows = $this->database->select(
            'SELECT ' . self::COLUMNS . ' FROM payments WHERE user_id = :user
             ORDER BY created_at DESC, id DESC LIMIT :limit',
            ['user' => $userId, 'limit' => $limit],
        );

        return array_map($this->map(...), $rows);
    }

    /**
     * @return array<string, scalar|null>
     */
    private function parameters(Payment $payment): array
    {
        return [
            'user' => $payment->userId,
            'purpose' => $payment->purpose->value,
            'ref_type' => $payment->referenceType,
            'ref_id' => $payment->referenceId,
            'amount' => $payment->amount->cents,
            'currency' => $payment->amount->currency,
            'tax' => $payment->taxRate,
            'status' => $payment->status->value,
            'provider' => $payment->provider,
            'provider_ref' => $payment->providerReference,
            'paid' => Timestamp::utcOrNull($payment->paidAt),
            'now' => Timestamp::utcOrNull($payment->createdAt) ?? Timestamp::now(),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Payment
    {
        return new Payment(
            (int) $row['id'],
            (int) $row['user_id'],
            PaymentPurpose::from((string) $row['purpose']),
            new Money((int) $row['amount_cents'], (string) $row['currency']),
            PaymentStatus::from((string) $row['status']),
            \is_string($row['reference_type']) ? $row['reference_type'] : null,
            $row['reference_id'] === null ? null : (int) $row['reference_id'],
            (float) $row['tax_rate'],
            (string) $row['provider'],
            \is_string($row['provider_reference']) ? $row['provider_reference'] : null,
            Timestamp::parse(\is_string($row['paid_at']) ? $row['paid_at'] : null),
            Timestamp::parse(\is_string($row['created_at']) ? $row['created_at'] : null),
        );
    }
}
