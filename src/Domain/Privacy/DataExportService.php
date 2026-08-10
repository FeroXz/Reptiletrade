<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Privacy;

use Reptilienmarkt\Domain\Audit\AuditEntry;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Support\Clock;

/**
 * Datenauskunft nach Artikel 15 DSGVO.
 *
 * Ausgegeben wird alles, was zu diesem Konto gespeichert ist — in einem
 * Format, das man weiterverwenden kann, nicht in einer PDF-Wueste.
 *
 * Zwei Dinge bleiben bewusst draussen: der Passwort-Hash, weil seine Ausgabe
 * niemandem nuetzt und einem Angreifer mit gestohlener Sitzung alles gibt, und
 * das TOTP-Geheimnis aus demselben Grund. Beides sind Zugangsmittel, keine
 * Daten ueber die Person.
 *
 * Nachrichten der Gegenseite werden mitgegeben, weil sie Teil der eigenen
 * Kommunikation sind — aber ohne deren Kontaktdaten und ohne Konto-Interna.
 */
final readonly class DataExportService
{
    public function __construct(
        private Database $database,
        private AuditLog $audit,
        private Clock $clock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        $userId = $user->id ?? 0;

        $daten = [
            'hinweis' => 'Auskunft nach Artikel 15 DSGVO. Zugangsmittel wie Passwort-Hash und '
                . 'Zwei-Faktor-Geheimnis sind bewusst nicht enthalten — sie sind keine Daten über dich, '
                . 'sondern Schlüssel zu deinem Konto.',
            'erstellt_am' => $this->clock->now()->format('c'),
            'konto' => $this->row(
                'SELECT id, email, display_name, role, status, email_verified_at, phone, phone_verified_at,
                        identity_verified_at, is_commercial, erlaubnis_11_number, imprint, postal_code, country,
                        last_login_at, created_at, updated_at
                   FROM users WHERE id = :id',
                ['id' => $userId],
            ),
            'zuechterprofil' => $this->row(
                'SELECT slug, headline, description, focus_species_json, breeding_since, website, is_public,
                        created_at, updated_at
                   FROM breeder_profiles WHERE user_id = :id',
                ['id' => $userId],
            ),
            'anzeigen' => $this->rows(
                'SELECT id, type, species_id, title, description, price_cents, currency, sex, hatch_date, weight_g,
                        count_available, cb_status, status, postal_code, country, handover, legal_confirmations,
                        view_count, created_at, updated_at, expires_at
                   FROM listings WHERE user_id = :id ORDER BY id',
                ['id' => $userId],
            ),
            'rechtsnachweise' => $this->rows(
                'SELECT d.id, d.listing_id, d.doc_type, d.reference_number, d.issuing_authority, d.issue_date,
                        d.verified_at, d.original_filename, d.mime_type, d.byte_size, d.created_at
                   FROM legal_docs d JOIN listings l ON l.id = d.listing_id
                  WHERE l.user_id = :id ORDER BY d.id',
                ['id' => $userId],
            ),
            'kontonachweise' => $this->rows(
                'SELECT id, doc_type, original_filename, mime_type, byte_size, status, reviewed_at, created_at
                   FROM user_documents WHERE user_id = :id ORDER BY id',
                ['id' => $userId],
            ),
            'gespraeche' => $this->conversations($userId),
            'bewertungen_erhalten' => $this->rows(
                'SELECT id, listing_id, rating, comment, deal_confirmed_at, created_at
                   FROM reviews WHERE to_user_id = :id ORDER BY id',
                ['id' => $userId],
            ),
            'bewertungen_abgegeben' => $this->rows(
                'SELECT id, listing_id, to_user_id, rating, comment, deal_confirmed_at, created_at
                   FROM reviews WHERE from_user_id = :id ORDER BY id',
                ['id' => $userId],
            ),
            'meldungen_abgegeben' => $this->rows(
                'SELECT id, target_type, target_id, reason, description, status, created_at
                   FROM reports WHERE reporter_id = :id ORDER BY id',
                ['id' => $userId],
            ),
            'merkliste' => $this->rows(
                'SELECT f.listing_id, l.title, f.created_at
                   FROM listing_favorites f JOIN listings l ON l.id = f.listing_id
                  WHERE f.user_id = :id ORDER BY f.created_at',
                ['id' => $userId],
            ),
            // Nur die Abweichungen — mehr steht nicht in der Tabelle. Wer nie
            // etwas eingestellt hat, hat hier eine leere Liste und laeuft auf
            // den Voreinstellungen.
            'benachrichtigungen' => $this->rows(
                'SELECT channel_key, enabled, updated_at
                   FROM notification_preferences WHERE user_id = :id ORDER BY channel_key',
                ['id' => $userId],
            ),
            // Ohne "body", und das ist kein Versehen: Der Text enthaelt
            // Abmelde- und Bestaetigungslinks, und ein Auskunftsdownload ist
            // eine Datei, die weitergereicht wird — per Mail an den Anwalt, in
            // die Cloud, auf den USB-Stick. Wer die Auskunft bekommt, haette
            // damit die Links des Kontos in der Hand. Was in der Mail stand,
            // steht ohnehin im Postfach des Empfaengers.
            'versendete_mails' => $this->rows(
                'SELECT created_at, sent_at, purpose, subject, status
                   FROM mail_outbox WHERE user_id = :id ORDER BY id',
                ['id' => $userId],
            ),
            'gespeicherte_suchen' => $this->rows(
                'SELECT id, name, filter_json, alert_frequency, last_alert_at, created_at
                   FROM saved_searches WHERE user_id = :id ORDER BY id',
                ['id' => $userId],
            ),
            'abos' => $this->rows(
                'SELECT id, plan_key, status, current_period_start, current_period_end, cancel_at_period_end,
                        created_at
                   FROM subscriptions WHERE user_id = :id ORDER BY id',
                ['id' => $userId],
            ),
            'zahlungen' => $this->rows(
                'SELECT id, purpose, reference_type, reference_id, amount_cents, currency, tax_rate, status,
                        paid_at, created_at
                   FROM payments WHERE user_id = :id ORDER BY id',
                ['id' => $userId],
            ),
            'nachzucht_ankuendigungen' => $this->rows(
                'SELECT id, species_id, title, description, expected_at, morph_note, status, created_at
                   FROM breeding_announcements WHERE user_id = :id ORDER BY id',
                ['id' => $userId],
            ),
            'sitzungen' => $this->rows(
                'SELECT ip_address, user_agent, created_at, last_seen_at, expires_at
                   FROM sessions WHERE user_id = :id ORDER BY created_at',
                ['id' => $userId],
            ),
            'genetik_berichte' => $this->rows(
                'SELECT id, species_id, listing_a_id, listing_b_id, titel, result_json, created_at
                   FROM genetics_simulations WHERE user_id = :id ORDER BY id',
                ['id' => $userId],
            ),
            'verlauf' => $this->rows(
                'SELECT occurred_at, action, entity_type, entity_id, data_json
                   FROM audit_log WHERE actor_user_id = :id ORDER BY id',
                ['id' => $userId],
            ),
        ];

        $this->audit->record(new AuditEntry('privacy.data_exported', 'user', $user->id, [], $user->id));

        return $daten;
    }

    public function toJson(User $user): string
    {
        return json_encode(
            $this->export($user),
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        );
    }

    public function filename(User $user): string
    {
        return \sprintf('reptilienmarkt-auskunft-%d-%s.json', $user->id ?? 0, $this->clock->now()->format('Y-m-d'));
    }

    /**
     * Gespraeche mit Nachrichten beider Seiten — aber ohne Konto-Interna der
     * Gegenseite. Wer Auskunft verlangt, bekommt seine Kommunikation, nicht
     * das Adressbuch der anderen.
     *
     * @return list<array<string, mixed>>
     */
    private function conversations(int $userId): array
    {
        $gespraeche = $this->rows(
            'SELECT id, listing_id, buyer_id, seller_id, status, deal_confirmed_buyer_at, deal_confirmed_seller_at,
                    message_count, created_at, last_message_at
               FROM conversations WHERE buyer_id = :id OR seller_id = :id ORDER BY id',
            ['id' => $userId],
        );

        $mitNachrichten = [];

        foreach ($gespraeche as $gespraech) {
            $gespraech['nachrichten'] = $this->rows(
                'SELECT sequence, sender_id, body, created_at, read_at
                   FROM messages WHERE conversation_id = :id ORDER BY sequence',
                ['id' => (int) $gespraech['id']],
            );

            $mitNachrichten[] = $gespraech;
        }

        return $mitNachrichten;
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>|null
     */
    private function row(string $sql, array $parameters): ?array
    {
        return $this->database->selectOne($sql, $parameters);
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $parameters): array
    {
        return $this->database->select($sql, $parameters);
    }
}
