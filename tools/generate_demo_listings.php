<?php

declare(strict_types=1);

/**
 * Erzeugt Demo-Anzeigen fuer Leistungsmessungen und die lokale Entwicklung.
 *
 * NICHT fuer den Produktivbetrieb: Die Anzeigen sind erfunden und tragen keine
 * Rechtsnachweise. Aufruf: php tools/generate_demo_listings.php [--anzahl=50000]
 */

use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Infra\Search\ListingIndexer;
use Reptilienmarkt\Support\Container;
use Reptilienmarkt\Support\Env;

if (\PHP_SAPI !== 'cli') {
    exit("Nur auf der Kommandozeile.\n");
}

/** @var Container $container */
$container = require dirname(__DIR__) . '/config/bootstrap.php';

if (Env::string('APP_ENV', 'production') === 'production') {
    fwrite(\STDERR, "Demo-Daten sind in der Produktionsumgebung gesperrt.\n");
    exit(1);
}

/** @var list<string> $argv */
$argv = $argv ?? [];
$count = 50000;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--anzahl=')) {
        $count = max(1, (int) substr($argument, 9));
    }
}

$database = $container->get(Database::class);
$pdo = $database->pdo();

mt_srand(20260802);

/** @var list<array{id: int, min_alter: int|null, min_gewicht: int|null}> $species */
$species = [];
foreach ($database->select('SELECT id, min_abgabe_alter_wochen, min_abgabe_gewicht_g FROM species ORDER BY id') as $row) {
    $species[] = [
        'id' => (int) $row['id'],
        'min_alter' => $row['min_abgabe_alter_wochen'] === null ? null : (int) $row['min_abgabe_alter_wochen'],
        'min_gewicht' => $row['min_abgabe_gewicht_g'] === null ? null : (int) $row['min_abgabe_gewicht_g'],
    ];
}

if ($species === []) {
    fwrite(\STDERR, "Kein Artenstamm vorhanden — bitte zuerst bin/seed.php ausfuehren.\n");
    exit(1);
}

/** @var array<int, list<int>> $morphsBySpecies */
$morphsBySpecies = [];
foreach ($database->select('SELECT id, species_id FROM morphs') as $row) {
    $morphsBySpecies[(int) $row['species_id']][] = (int) $row['id'];
}

/** @var list<array{country: string, postal_code: string, lat: float, lng: float}> $places */
$places = [];
foreach ($database->select('SELECT country, postal_code, lat, lng FROM postal_codes') as $row) {
    $places[] = [
        'country' => (string) $row['country'],
        'postal_code' => (string) $row['postal_code'],
        'lat' => (float) $row['lat'],
        'lng' => (float) $row['lng'],
    ];
}

if ($places === []) {
    fwrite(\STDERR, "Keine Postleitzahlen vorhanden — bitte zuerst bin/seed.php ausfuehren.\n");
    exit(1);
}

echo "Demo-Nutzer ...\n";

$now = gmdate('Y-m-d\TH:i:s\Z');
$userIds = [];

$database->transaction(static function (Database $db) use ($now): void {
    $statement = $db->pdo()->prepare(
        'INSERT INTO users (email, email_canonical, password_hash, display_name, role, created_at, updated_at)
         VALUES (:email, :canonical, :hash, :name, :role, :now, :now)
         ON CONFLICT(email_canonical) DO NOTHING',
    );

    for ($i = 1; $i <= 200; ++$i) {
        $email = sprintf('demo%03d@example.tld', $i);
        $statement->execute([
            'email' => $email,
            'canonical' => $email,
            'hash' => 'argon2id$demo',
            'name' => sprintf('Demo-Züchter %03d', $i),
            'role' => $i % 5 === 0 ? 'breeder' : 'seller',
            'now' => $now,
        ]);
    }
});

foreach ($database->select("SELECT id FROM users WHERE email_canonical LIKE 'demo%@example.tld'") as $row) {
    $userIds[] = (int) $row['id'];
}

printf("  %d Nutzer\n", count($userIds));

echo "Anzeigen ...\n";

$types = ['verkauf', 'verkauf', 'verkauf', 'verkauf', 'tausch', 'abgabe', 'gesuch', 'nachzucht_vorbestellung'];
$sexes = ['m', 'w', 'unbekannt'];
$cbStatuses = ['nz', 'nz', 'nz', 'wf', 'unbekannt'];
$handovers = ['abholung', 'abholung', 'uebergabe_boerse', 'tiertransport'];
$statuses = ['aktiv', 'aktiv', 'aktiv', 'aktiv', 'aktiv', 'aktiv', 'aktiv', 'aktiv', 'reserviert', 'verkauft', 'entwurf', 'abgelaufen'];
$zygosities = ['visual', 'visual', 'visual', 'het', 'poss_het_66', 'poss_het_50'];

$start = microtime(true);
$created = 0;

$database->transaction(static function (Database $db) use (
    $count,
    $species,
    $morphsBySpecies,
    $places,
    $userIds,
    $types,
    $sexes,
    $cbStatuses,
    $handovers,
    $statuses,
    $zygosities,
    &$created
): void {
    $listingStatement = $db->pdo()->prepare(
        'INSERT INTO listings (user_id, type, species_id, title, description, price_cents, currency, negotiable,
                               sex, hatch_date, weight_g, count_available, cb_status, status, postal_code, country,
                               lat, lng, handover, created_at, updated_at, expires_at, bumped_at, view_count, is_featured)
         VALUES (:user_id, :type, :species_id, :title, :description, :price_cents, :currency, :negotiable,
                 :sex, :hatch_date, :weight_g, 1, :cb_status, :status, :postal_code, :country,
                 :lat, :lng, :handover, :created_at, :created_at, :expires_at, :bumped_at, :views, :featured)',
    );

    $morphStatement = $db->pdo()->prepare(
        'INSERT INTO listing_morphs (listing_id, morph_id, zygosity) VALUES (:listing_id, :morph_id, :zygosity)
         ON CONFLICT(listing_id, morph_id) DO NOTHING',
    );

    $mediaStatement = $db->pdo()->prepare(
        'INSERT INTO listing_media (listing_id, media_type, path, sort_order, is_primary, created_at)
         VALUES (:listing_id, :type, :path, 0, 1, :now)',
    );

    $speciesCount = count($species);
    $placeCount = count($places);
    $userCount = count($userIds);

    for ($i = 0; $i < $count; ++$i) {
        // Die ersten Arten bekommen ueberproportional viele Anzeigen — so
        // sieht die Verteilung aus wie auf einem echten Marktplatz.
        $speciesIndex = mt_rand(0, 99) < 60 ? mt_rand(0, min(9, $speciesCount - 1)) : mt_rand(0, $speciesCount - 1);
        $entry = $species[$speciesIndex];
        $place = $places[mt_rand(0, $placeCount - 1)];

        $ageDays = mt_rand(30, 1800);
        $createdAt = gmdate('Y-m-d\TH:i:s\Z', time() - mt_rand(0, 120) * 86400);
        $status = $statuses[mt_rand(0, count($statuses) - 1)];
        $type = $types[mt_rand(0, count($types) - 1)];

        $listingStatement->execute([
            'user_id' => $userIds[mt_rand(0, $userCount - 1)],
            'type' => $type,
            'species_id' => $entry['id'],
            'title' => sprintf('Demo-Anzeige %d', $i + 1),
            'description' => 'Nachzucht aus eigener Haltung, kerngesund und gut im Futter. Abgabe nur in erfahrene Hände.',
            'price_cents' => $type === 'tausch' || $type === 'gesuch' ? null : mt_rand(2000, 250000),
            'currency' => $place['country'] === 'CH' ? 'CHF' : 'EUR',
            'negotiable' => mt_rand(0, 1),
            'sex' => $sexes[mt_rand(0, 2)],
            'hatch_date' => gmdate('Y-m-d', time() - $ageDays * 86400),
            'weight_g' => max(1, ($entry['min_gewicht'] ?? 20) + mt_rand(0, 200)),
            'cb_status' => $cbStatuses[mt_rand(0, count($cbStatuses) - 1)],
            'status' => $status,
            'postal_code' => $place['postal_code'],
            'country' => $place['country'],
            'lat' => $place['lat'],
            'lng' => $place['lng'],
            'handover' => $handovers[mt_rand(0, count($handovers) - 1)],
            'created_at' => $createdAt,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + mt_rand(1, 60) * 86400),
            'bumped_at' => $createdAt,
            'views' => mt_rand(0, 900),
            'featured' => mt_rand(0, 49) === 0 ? 1 : 0,
        ]);

        $listingId = (int) $db->pdo()->lastInsertId();

        $available = $morphsBySpecies[$entry['id']] ?? [];
        if ($available !== []) {
            $wanted = mt_rand(0, 3);
            for ($m = 0; $m < $wanted; ++$m) {
                $morphStatement->execute([
                    'listing_id' => $listingId,
                    'morph_id' => $available[mt_rand(0, count($available) - 1)],
                    'zygosity' => $zygosities[mt_rand(0, count($zygosities) - 1)],
                ]);
            }
        }

        if (mt_rand(0, 9) < 7) {
            $mediaStatement->execute([
                'listing_id' => $listingId,
                'type' => 'bild',
                'path' => sprintf('demo/%d.webp', $listingId % 20),
                'now' => $createdAt,
            ]);
        }

        ++$created;
    }
});

printf("  %d Anzeigen in %.1f s\n", $created, microtime(true) - $start);

echo "Volltextindex ...\n";
$indexStart = microtime(true);
$indexed = $container->get(ListingIndexer::class)->rebuildAll();
$container->get(\Reptilienmarkt\Domain\Search\SearchIndex::class)->optimize();
printf("  %d Eintraege in %.1f s\n", $indexed, microtime(true) - $indexStart);

$pdo->exec('ANALYZE');
echo "ANALYZE ausgefuehrt.\n";

exit(0);
