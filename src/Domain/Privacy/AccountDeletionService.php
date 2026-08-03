<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Privacy;

use Reptilienmarkt\Domain\Audit\AuditActorType;
use Reptilienmarkt\Domain\Audit\AuditEntry;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Infra\Storage\PrivateStorage;
use Reptilienmarkt\Infra\Storage\PublicImageStorage;
use Reptilienmarkt\Support\Clock;
use Reptilienmarkt\Support\Timestamp;

/**
 * Kontoloeschung nach Artikel 17 DSGVO.
 *
 * Zwei Wege, und die Wahl faellt nicht der Nutzer:
 *
 * Hat das Konto **keine** Bewertungen abgegeben oder erhalten, wird es
 * vollstaendig geloescht. Es haengt nichts daran, was ohne es unverstaendlich
 * wuerde.
 *
 * Gibt es dagegen Bewertungen, wird **anonymisiert statt geloescht**. Eine
 * Bewertung ist eine Aussage ueber einen Handel, an dem zwei Seiten beteiligt
 * waren; sie mit dem Konto zu loeschen wuerde die Bewertungshistorie der
 * Gegenseite verfaelschen — die hat ein berechtigtes Interesse daran, dass ihre
 * Bewertungen bestehen bleiben. Uebrig bleibt ein Konto ohne Namen, ohne
 * Adresse, ohne Kontaktdaten: "Gelöschtes Konto".
 *
 * In beiden Faellen verschwinden Bilder und Nachweise von der Platte.
 */
final readonly class AccountDeletionService
{
    public function __construct(
        private Database $database,
        private PublicImageStorage $images,
        private PrivateStorage $privateStorage,
        private AuditLog $audit,
        private Clock $clock,
    ) {}

    /**
     * Was passieren wuerde — fuer die Ruecksprache mit dem Nutzer, bevor es
     * unwiderruflich wird.
     */
    public function preview(User $user): DeletionPreview
    {
        $userId = $user->id ?? 0;

        return new DeletionPreview(
            $this->count('SELECT COUNT(*) FROM listings WHERE user_id = :id', $userId),
            $this->count('SELECT COUNT(*) FROM conversations WHERE buyer_id = :id OR seller_id = :id', $userId),
            $this->count('SELECT COUNT(*) FROM reviews WHERE to_user_id = :id', $userId),
            $this->count('SELECT COUNT(*) FROM reviews WHERE from_user_id = :id', $userId),
            $this->requiresAnonymization($userId),
        );
    }

    /**
     * @throws PrivacyException
     */
    public function delete(User $user, ?int $actorId = null): DeletionResult
    {
        $userId = $user->id ?? 0;

        if ($userId === 0) {
            throw new PrivacyException('Das Konto lässt sich nicht bestimmen.');
        }

        // Ein laufendes Abo waere nach dem Loeschen nicht mehr kuendbar.
        if ($this->count("SELECT COUNT(*) FROM subscriptions WHERE user_id = :id AND status = 'aktiv'", $userId) > 0) {
            throw new PrivacyException(
                'Es läuft noch eine Mitgliedschaft. Bitte kündige sie zuerst — danach lässt sich das Konto löschen.',
            );
        }

        $anonymisieren = $this->requiresAnonymization($userId);
        $dateien = $this->removeFiles($userId);

        $this->database->transaction(function (Database $database) use ($userId, $anonymisieren): void {
            // Immer weg, unabhaengig vom Weg: Zugangsmittel, Nachweise,
            // Sitzungen, Token. Sie haben nach der Loeschung keinen Zweck mehr.
            $database->execute('DELETE FROM sessions WHERE user_id = :id', ['id' => $userId]);
            $database->execute('DELETE FROM user_tokens WHERE user_id = :id', ['id' => $userId]);
            $database->execute('DELETE FROM user_documents WHERE user_id = :id', ['id' => $userId]);
            $database->execute('DELETE FROM saved_searches WHERE user_id = :id', ['id' => $userId]);
            $database->execute('DELETE FROM breeder_profiles WHERE user_id = :id', ['id' => $userId]);
            $database->execute('DELETE FROM breeding_announcements WHERE user_id = :id', ['id' => $userId]);

            if (!$anonymisieren) {
                // Ohne Bewertungen haengt nichts an dem Konto — die
                // Fremdschluessel raeumen den Rest auf.
                $database->execute('DELETE FROM listings WHERE user_id = :id', ['id' => $userId]);
                $database->execute('DELETE FROM users WHERE id = :id', ['id' => $userId]);

                return;
            }

            // Anonymisieren: Die Anzeigen verschwinden, das Konto bleibt als
            // namenlose Huelle stehen, damit Bewertungen nicht ins Leere zeigen.
            $database->execute("UPDATE listings SET status = 'gesperrt' WHERE user_id = :id", ['id' => $userId]);
            $database->execute(
                'DELETE FROM listing_media WHERE listing_id IN (SELECT id FROM listings WHERE user_id = :id)',
                ['id' => $userId],
            );

            $database->execute(
                <<<'SQL'
                    UPDATE users
                       SET email = :email,
                           email_canonical = :email,
                           display_name = 'Gelöschtes Konto',
                           password_hash = '',
                           phone = NULL,
                           phone_verified_at = NULL,
                           email_verified_at = NULL,
                           identity_verified_at = NULL,
                           erlaubnis_11_number = NULL,
                           imprint = NULL,
                           postal_code = NULL,
                           country = NULL,
                           lat = NULL,
                           lng = NULL,
                           totp_secret = NULL,
                           totp_confirmed_at = NULL,
                           status = 'geloescht',
                           anonymized_at = :now,
                           updated_at = :now
                     WHERE id = :id
                    SQL,
                [
                    // Eindeutig bleiben muss sie wegen des Index, aussagen darf
                    // sie nichts mehr.
                    'email' => \sprintf('geloescht-%d@invalid.local', $userId),
                    'now' => Timestamp::utc($this->clock->now()),
                    'id' => $userId,
                ],
            );

            // Der Wortlaut abgegebener Bewertungen faellt weg — die Wertung
            // bleibt, damit der Schnitt der Gegenseite stimmt.
            $database->execute('UPDATE reviews SET comment = NULL WHERE from_user_id = :id', ['id' => $userId]);

            // Nachrichteninhalte sind Kommunikationsdaten und gehen mit.
            $database->execute(
                <<<'SQL'
                    UPDATE messages
                       SET body = '[Nachricht gelöscht]'
                     WHERE sender_id = :id
                    SQL,
                ['id' => $userId],
            );
        });

        // Der Audit-Trail haelt die Loeschung fest, ohne die geloeschten Daten
        // zu wiederholen — sonst waere er eine Kopie dessen, was gerade
        // verschwinden sollte.
        $this->audit->record(new AuditEntry(
            $anonymisieren ? 'privacy.account_anonymized' : 'privacy.account_deleted',
            'user',
            $userId,
            ['dateien' => $dateien],
            $actorId,
            $actorId === null ? AuditActorType::System : AuditActorType::User,
        ));

        return new DeletionResult($anonymisieren, $dateien);
    }

    /**
     * Bewertungen sind der Grund fuer die Anonymisierung — in beide
     * Richtungen: erhaltene, weil sie zum Konto gehoeren, und abgegebene, weil
     * sie beim Empfaenger stehen.
     */
    private function requiresAnonymization(int $userId): bool
    {
        return $this->count(
            'SELECT COUNT(*) FROM reviews WHERE to_user_id = :id OR from_user_id = :id',
            $userId,
        ) > 0;
    }

    /**
     * @return int Anzahl entfernter Dateien
     */
    private function removeFiles(int $userId): int
    {
        $entfernt = 0;

        $bilder = $this->database->select(
            "SELECT m.path FROM listing_media m JOIN listings l ON l.id = m.listing_id
              WHERE l.user_id = :id AND m.media_type = 'bild'",
            ['id' => $userId],
        );

        foreach ($bilder as $bild) {
            $this->images->delete((string) $bild['path']);
            ++$entfernt;
        }

        $nachweise = $this->database->select(
            'SELECT private_path FROM user_documents WHERE user_id = :id
             UNION ALL
             SELECT d.private_path FROM legal_docs d JOIN listings l ON l.id = d.listing_id
              WHERE l.user_id = :id AND d.private_path IS NOT NULL',
            ['id' => $userId],
        );

        foreach ($nachweise as $nachweis) {
            $pfad = $nachweis['private_path'] ?? null;

            if (\is_string($pfad) && $pfad !== '') {
                $this->privateStorage->delete($pfad);
                ++$entfernt;
            }
        }

        return $entfernt;
    }

    private function count(string $sql, int $userId): int
    {
        $value = $this->database->scalar($sql, ['id' => $userId]);

        return (int) (is_numeric($value) ? $value : 0);
    }
}
