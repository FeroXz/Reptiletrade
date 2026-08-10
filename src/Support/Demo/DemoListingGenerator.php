<?php

declare(strict_types=1);

namespace Reptilienmarkt\Support\Demo;

use Reptilienmarkt\Domain\Audit\AuditActorType;
use Reptilienmarkt\Domain\Audit\AuditEntry;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Domain\Search\SearchIndex;
use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Infra\Search\ListingIndexer;

/**
 * Legt Beispielanzeigen an und nimmt sie wieder zurueck.
 *
 * Beide Werkzeuge teilen sich diese Klasse: tools/generate_demo_listings.php
 * fuer Entwicklung und Messungen, tools/generate_demo_listings_produktion.php
 * fuer eine Schaufensterbestueckung im Produktivbetrieb. Was der eine erzeugt,
 * raeumt der andere restlos wieder ab — es gibt nur einen Datensatz und nur
 * eine Definition davon, was "Beispieldaten" sind.
 *
 * Die Zugehoerigkeit haengt allein an den Beispielkonten: Jede Anzeige gehoert
 * einem Konto nach dem Muster demo###@example.tld mit einem Passwort-Hash, der
 * keiner ist. Damit ist die Menge exakt bestimmbar — remove() faehrt genau
 * diese Anzeigen ab und laesst echte Daten unberuehrt.
 */
final readonly class DemoListingGenerator
{
    /** Muster der Beispielkonten. */
    public const string EMAIL_PATTERN = 'demo%03d@example.tld';

    /** Dasselbe Muster als LIKE-Ausdruck. */
    public const string EMAIL_LIKE = 'demo%@example.tld';

    /**
     * Kein Argon2id-Hash, sondern eine Zeichenkette, die password_verify()
     * niemals bestaetigt: An einem Beispielkonto meldet sich niemand an.
     */
    public const string PASSWORD_HASH = 'beispieldaten-kein-login';

    /** Erkennungszeichen im Titel — im Betrieb muss man sie auf einen Blick sehen. */
    public const string TITLE_PREFIX = 'Beispielanzeige';

    public const int DEFAULT_BATCH_SIZE = 500;

    /**
     * Steht so in jeder Beispielanzeige. Der erste Satz ist der wichtige: Wer
     * die Anzeige im Produktivbetrieb sieht, soll sie nicht fuer ein Angebot
     * halten.
     */
    public const string DESCRIPTION = 'Beispielanzeige zur Veranschaulichung des Marktplatzes. '
        . 'Dieses Tier gibt es nicht — die Anzeige ist kein Angebot und keine Aufforderung zur Kontaktaufnahme. '
        . 'Der beschreibende Text steht hier nur, damit Trefferliste, Filter und Suche mit echten Textlaengen laufen.';

    private const int USER_COUNT = 200;

    /**
     * Der Hash, den fruehere Laeufe des Entwicklungswerkzeugs geschrieben
     * haben. Er bleibt in der Bedingung, damit remove() auch Datenbestaende von
     * vorher restlos abraeumt.
     */
    private const string LEGACY_PASSWORD_HASH = 'argon2id$demo';

    /**
     * Die Bedingung, die ein Beispielkonto ausmacht. Zwei Merkmale muessen
     * zusammenkommen: Nur die E-Mail zu pruefen waere zu grob, wenn sich je ein
     * echtes Konto so nennt.
     */
    private const string USER_FILTER = "email_canonical LIKE '" . self::EMAIL_LIKE . "'"
        . " AND password_hash IN ('" . self::PASSWORD_HASH . "', '" . self::LEGACY_PASSWORD_HASH . "')";

    public function __construct(
        private Database $database,
        private ListingIndexer $indexer,
        private SearchIndex $index,
        private AuditLog $auditLog,
    ) {}

    /**
     * Wie viele Beispielkonten und Beispielanzeigen gerade in der Datenbank
     * liegen. bin/doctor.php fragt darueber nach Altlasten im Produktivbetrieb.
     *
     * @return array{users: int, listings: int}
     */
    public function inventory(): array
    {
        return [
            'users' => $this->toInt($this->database->scalar('SELECT COUNT(*) FROM users WHERE ' . self::USER_FILTER)),
            'listings' => $this->toInt($this->database->scalar(
                'SELECT COUNT(*) FROM listings WHERE user_id IN (SELECT id FROM users WHERE ' . self::USER_FILTER . ')',
            )),
        ];
    }

    /**
     * Legt $count Beispielanzeigen an. Mehrfache Laeufe addieren sich; die
     * Beispielkonten entstehen nur beim ersten Mal.
     *
     * Geschrieben wird in Stapeln: Eine einzige Transaktion ueber 50 000
     * Anzeigen haelt die Schreibsperre der Datenbank minutenlang und laesst
     * jeden Besucher warten, der in dieser Zeit etwas speichert.
     *
     * @param list<string>                   $mediaPaths Bildpfade unterhalb von STORAGE_PUBLIC. Leer = keine
     *                                                   Bildzeilen; im Betrieb ist ein fehlendes Bild besser
     *                                                   als ein kaputtes.
     * @param (callable(int, int): void)|null $progress   Fortschritt: erledigt, gesamt
     */
    public function generate(
        int $count,
        DemoIndexStrategy $strategy = DemoIndexStrategy::Inkrementell,
        int $batchSize = self::DEFAULT_BATCH_SIZE,
        array $mediaPaths = [],
        ?int $seed = null,
        ?callable $progress = null,
    ): DemoListingReport {
        $wanted = max(1, $count);
        $batchSize = max(1, $batchSize);

        if ($seed !== null) {
            mt_srand($seed);
        }

        $start = microtime(true);

        $newUsers = $this->ensureUsers();
        $catalog = $this->catalog($mediaPaths);
        $number = $this->nextNumber();

        $listings = 0;
        $morphs = 0;
        $media = 0;
        $indexed = 0;

        while ($listings < $wanted) {
            $size = min($batchSize, $wanted - $listings);

            /** @var array{ids: list<int>, morphs: int, media: int} $batch */
            $batch = $this->database->transaction(
                fn(Database $database): array => $this->insertBatch($database, $catalog, $size, $number + $listings),
            );

            $listings += \count($batch['ids']);
            $morphs += $batch['morphs'];
            $media += $batch['media'];

            if ($strategy === DemoIndexStrategy::Inkrementell) {
                $indexed += $this->indexBatch($batch['ids']);
            }

            if ($progress !== null) {
                $progress($listings, $wanted);
            }
        }

        if ($strategy === DemoIndexStrategy::Neuaufbau) {
            $indexed = $this->indexer->rebuildAll();
            $this->index->optimize();
        }

        $this->auditLog->record(new AuditEntry(
            action: 'demo.listings_created',
            entityType: 'demo_listings',
            data: ['anzeigen' => $listings, 'konten' => $newUsers, 'merkmale' => $morphs, 'bilder' => $media],
            actorType: AuditActorType::System,
        ));

        return new DemoListingReport($newUsers, $listings, $morphs, $media, $indexed, microtime(true) - $start);
    }

    /**
     * Nimmt saemtliche Beispieldaten zurueck: Anzeigen samt Merkmalen, Bildern
     * und Volltextindex, danach die Beispielkonten selbst.
     *
     * Geloescht wird ueber die Anzeigen und nicht ueber die Konten allein: Ein
     * direktes DELETE auf listings loest den Trigger listings_search_delete
     * sicher aus, waehrend das Verhalten bei kaskadierten Loeschungen an
     * PRAGMA recursive_triggers haengt. Sonst blieben verwaiste Eintraege im
     * Suchindex zurueck und die Trefferliste zeigte Anzeigen, die es nicht
     * mehr gibt.
     */
    public function remove(int $batchSize = self::DEFAULT_BATCH_SIZE): DemoListingReport
    {
        $batchSize = max(1, $batchSize);
        $start = microtime(true);

        $listings = 0;

        do {
            $removed = $this->database->transaction(static fn(Database $database): int => $database->execute(
                'DELETE FROM listings WHERE id IN (
                    SELECT id FROM listings WHERE user_id IN (SELECT id FROM users WHERE ' . self::USER_FILTER . ')
                     LIMIT :limit
                 )',
                ['limit' => $batchSize],
            ));

            $listings += $removed;
        } while ($removed > 0);

        $users = $this->database->transaction(
            static fn(Database $database): int => $database->execute('DELETE FROM users WHERE ' . self::USER_FILTER),
        );

        $this->auditLog->record(new AuditEntry(
            action: 'demo.listings_removed',
            entityType: 'demo_listings',
            data: ['anzeigen' => $listings, 'konten' => $users],
            actorType: AuditActorType::System,
        ));

        return new DemoListingReport($users, $listings, 0, 0, 0, microtime(true) - $start);
    }

    /**
     * @return int Anzahl neu angelegter Beispielkonten
     */
    private function ensureUsers(): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        return $this->database->transaction(static function (Database $database) use ($now): int {
            $statement = $database->pdo()->prepare(
                'INSERT INTO users (email, email_canonical, password_hash, display_name, role, created_at, updated_at)
                 VALUES (:email, :canonical, :hash, :name, :role, :now, :now)
                 ON CONFLICT(email_canonical) DO NOTHING',
            );

            $created = 0;
            for ($i = 1; $i <= self::USER_COUNT; ++$i) {
                $email = \sprintf(self::EMAIL_PATTERN, $i);
                $statement->execute([
                    'email' => $email,
                    'canonical' => $email,
                    'hash' => self::PASSWORD_HASH,
                    'name' => \sprintf('Beispiel-Züchter %03d', $i),
                    'role' => $i % 5 === 0 ? 'breeder' : 'seller',
                    'now' => $now,
                ]);

                $created += $statement->rowCount();
            }

            return $created;
        });
    }

    /**
     * @param list<string> $mediaPaths
     */
    private function catalog(array $mediaPaths): DemoCatalog
    {
        /** @var list<array{id: int, common_name: string, min_gewicht: int|null}> $species */
        $species = [];
        foreach ($this->database->select('SELECT id, common_name_de, min_abgabe_gewicht_g FROM species ORDER BY id') as $row) {
            $species[] = [
                'id' => (int) $row['id'],
                'common_name' => (string) $row['common_name_de'],
                'min_gewicht' => $row['min_abgabe_gewicht_g'] === null ? null : (int) $row['min_abgabe_gewicht_g'],
            ];
        }

        if ($species === []) {
            throw DemoDataException::emptySpecies();
        }

        /** @var array<int, list<int>> $morphs */
        $morphs = [];
        foreach ($this->database->select('SELECT id, species_id FROM morphs') as $row) {
            $morphs[(int) $row['species_id']][] = (int) $row['id'];
        }

        /** @var list<array{country: string, postal_code: string, lat: float, lng: float}> $places */
        $places = [];
        foreach ($this->database->select('SELECT country, postal_code, lat, lng FROM postal_codes') as $row) {
            $places[] = [
                'country' => (string) $row['country'],
                'postal_code' => (string) $row['postal_code'],
                'lat' => (float) $row['lat'],
                'lng' => (float) $row['lng'],
            ];
        }

        if ($places === []) {
            throw DemoDataException::emptyPostalCodes();
        }

        /** @var list<int> $users */
        $users = [];
        foreach ($this->database->select('SELECT id FROM users WHERE ' . self::USER_FILTER . ' ORDER BY id') as $row) {
            $users[] = (int) $row['id'];
        }

        return new DemoCatalog($species, $morphs, $places, $users, $mediaPaths);
    }

    /**
     * Fortlaufende Nummer im Titel. Zaehlt ueber mehrere Laeufe weiter, damit
     * ein zweiter Aufruf keine zweite "Beispielanzeige 1" erzeugt.
     */
    private function nextNumber(): int
    {
        return $this->inventory()['listings'] + 1;
    }

    /**
     * @return array{ids: list<int>, morphs: int, media: int}
     */
    private function insertBatch(Database $database, DemoCatalog $catalog, int $size, int $firstNumber): array
    {
        $listingStatement = $database->pdo()->prepare(
            'INSERT INTO listings (user_id, type, species_id, title, description, price_cents, currency, negotiable,
                                   sex, hatch_date, weight_g, count_available, cb_status, status, postal_code, country,
                                   lat, lng, handover, created_at, updated_at, expires_at, bumped_at, view_count, is_featured)
             VALUES (:user_id, :type, :species_id, :title, :description, :price_cents, :currency, :negotiable,
                     :sex, :hatch_date, :weight_g, 1, :cb_status, :status, :postal_code, :country,
                     :lat, :lng, :handover, :created_at, :created_at, :expires_at, :bumped_at, :views, :featured)',
        );

        $morphStatement = $database->pdo()->prepare(
            'INSERT INTO listing_morphs (listing_id, morph_id, zygosity) VALUES (:listing_id, :morph_id, :zygosity)
             ON CONFLICT(listing_id, morph_id) DO NOTHING',
        );

        $mediaStatement = $database->pdo()->prepare(
            'INSERT INTO listing_media (listing_id, media_type, path, sort_order, is_primary, created_at)
             VALUES (:listing_id, :type, :path, 0, 1, :now)',
        );

        $types = ['verkauf', 'verkauf', 'verkauf', 'verkauf', 'tausch', 'abgabe', 'gesuch', 'nachzucht_vorbestellung'];
        $sexes = ['m', 'w', 'unbekannt'];
        $cbStatuses = ['nz', 'nz', 'nz', 'wf', 'unbekannt'];
        $handovers = ['abholung', 'abholung', 'uebergabe_boerse', 'tiertransport'];
        $statuses = ['aktiv', 'aktiv', 'aktiv', 'aktiv', 'aktiv', 'aktiv', 'aktiv', 'aktiv', 'reserviert', 'verkauft', 'entwurf', 'abgelaufen'];
        $zygosities = ['visual', 'visual', 'visual', 'het', 'poss_het_66', 'poss_het_50'];

        $speciesCount = \count($catalog->species);
        $placeCount = \count($catalog->places);
        $userCount = \count($catalog->users);
        $mediaCount = \count($catalog->mediaPaths);

        /** @var list<int> $ids */
        $ids = [];
        $morphRows = 0;
        $mediaRows = 0;

        for ($i = 0; $i < $size; ++$i) {
            // Die ersten Arten bekommen ueberproportional viele Anzeigen — so
            // sieht die Verteilung aus wie auf einem echten Marktplatz.
            $speciesIndex = mt_rand(0, 99) < 60 ? mt_rand(0, min(9, $speciesCount - 1)) : mt_rand(0, $speciesCount - 1);
            $entry = $catalog->species[$speciesIndex];
            $place = $catalog->places[mt_rand(0, $placeCount - 1)];

            $ageDays = mt_rand(30, 1800);
            $createdAt = gmdate('Y-m-d\TH:i:s\Z', time() - mt_rand(0, 120) * 86400);
            $type = $types[mt_rand(0, \count($types) - 1)];

            $listingStatement->execute([
                'user_id' => $catalog->users[mt_rand(0, $userCount - 1)],
                'type' => $type,
                'species_id' => $entry['id'],
                'title' => \sprintf('%s %d — %s', self::TITLE_PREFIX, $firstNumber + $i, $entry['common_name']),
                'description' => self::DESCRIPTION,
                'price_cents' => $type === 'tausch' || $type === 'gesuch' ? null : mt_rand(2000, 250000),
                'currency' => $place['country'] === 'CH' ? 'CHF' : 'EUR',
                'negotiable' => mt_rand(0, 1),
                'sex' => $sexes[mt_rand(0, 2)],
                'hatch_date' => gmdate('Y-m-d', time() - $ageDays * 86400),
                'weight_g' => max(1, ($entry['min_gewicht'] ?? 20) + mt_rand(0, 200)),
                'cb_status' => $cbStatuses[mt_rand(0, \count($cbStatuses) - 1)],
                'status' => $statuses[mt_rand(0, \count($statuses) - 1)],
                'postal_code' => $place['postal_code'],
                'country' => $place['country'],
                'lat' => $place['lat'],
                'lng' => $place['lng'],
                'handover' => $handovers[mt_rand(0, \count($handovers) - 1)],
                'created_at' => $createdAt,
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + mt_rand(1, 60) * 86400),
                'bumped_at' => $createdAt,
                'views' => mt_rand(0, 900),
                'featured' => mt_rand(0, 49) === 0 ? 1 : 0,
            ]);

            $listingId = (int) $database->pdo()->lastInsertId();
            $ids[] = $listingId;

            $available = $catalog->morphs[$entry['id']] ?? [];
            if ($available !== []) {
                $wanted = mt_rand(0, 3);
                for ($m = 0; $m < $wanted; ++$m) {
                    $morphStatement->execute([
                        'listing_id' => $listingId,
                        'morph_id' => $available[mt_rand(0, \count($available) - 1)],
                        'zygosity' => $zygosities[mt_rand(0, \count($zygosities) - 1)],
                    ]);

                    $morphRows += $morphStatement->rowCount();
                }
            }

            if ($mediaCount > 0 && mt_rand(0, 9) < 7) {
                $mediaStatement->execute([
                    'listing_id' => $listingId,
                    'type' => 'bild',
                    'path' => $catalog->mediaPaths[$listingId % $mediaCount],
                    'now' => $createdAt,
                ]);

                ++$mediaRows;
            }
        }

        return ['ids' => $ids, 'morphs' => $morphRows, 'media' => $mediaRows];
    }

    /**
     * @param list<int> $ids
     */
    private function indexBatch(array $ids): int
    {
        return $this->database->transaction(function (Database $database) use ($ids): int {
            foreach ($ids as $id) {
                $this->indexer->indexListing($id);
            }

            return \count($ids);
        });
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
