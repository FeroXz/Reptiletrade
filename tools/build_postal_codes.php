<?php

declare(strict_types=1);

/**
 * Baut die eingebettete PLZ-Tabelle (data/postal_codes.tsv.gz) aus offenen Quellen.
 *
 * Dieses Skript laeuft NICHT im Betrieb, sondern nur beim Aktualisieren des Datensatzes.
 * Quellen und Lizenzen sind in data/README.md dokumentiert.
 *
 * Aufruf: php tools/build_postal_codes.php --sources=/pfad/zu/quellen [--out=data/postal_codes.tsv.gz]
 *
 * Erwartete Dateien im Quellverzeichnis:
 *   cities.json          GeoNames-Ortsdaten mit Koordinaten (CC BY 4.0)
 *   admin1.json          GeoNames-Verwaltungseinheiten (CC BY 4.0)
 *   de_plz.js            PLZ/Ort/Bundesland Deutschland (MIT)
 *   at_plz.js            PLZ/Ort Oesterreich (MIT)
 *   ch_plz.json          PLZ/Ort/Kanton/Koordinaten Schweiz (MIT, GeoNames-basiert)
 */

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * @param list<string> $argv
 */
function option(array $argv, string $name, ?string $default = null): ?string
{
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--' . $name . '=')) {
            return substr($argument, strlen($name) + 3);
        }
    }

    return $default;
}

function normalizePlace(string $name): string
{
    $name = mb_strtolower($name, 'UTF-8');
    $name = strtr($name, [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'á' => 'a',
        'ô' => 'o', 'ó' => 'o', 'ç' => 'c', 'í' => 'i', 'ì' => 'i', 'ú' => 'u', 'ù' => 'u', 'ñ' => 'n',
    ]);
    $name = (string) preg_replace('/\s*\(.*?\)\s*/u', ' ', $name);
    $name = (string) preg_replace('/\bsankt\b|\bst\.\s*/u', 'st ', $name);
    $name = (string) preg_replace('/[^a-z0-9]+/u', ' ', $name);

    return trim((string) preg_replace('/\s+/', ' ', $name));
}

/**
 * Deutsche und oesterreichische Ortsnamen stehen in GeoNames teils englisch.
 *
 * @return list<string>
 */
function nameCandidates(string $name): array
{
    static $aliases = [
        'wien' => 'vienna',
        'muenchen' => 'munich',
        'koeln' => 'cologne',
        'nuernberg' => 'nuremberg',
        'hannover' => 'hanover',
        'braunschweig' => 'brunswick',
        'kassel' => 'kassel',
        'salzburg' => 'salzburg',
    ];

    $normalized = normalizePlace($name);
    $candidates = [$normalized];

    if (isset($aliases[$normalized])) {
        $candidates[] = $aliases[$normalized];
    }

    // "Wien-Parlament", "Wien Postfach", "Frankfurt am Main" -> fuehrender Ortsteil
    foreach (['-', '/', ','] as $separator) {
        if (str_contains($name, $separator)) {
            $head = normalizePlace(explode($separator, $name, 2)[0]);
            $candidates[] = $head;
            if (isset($aliases[$head])) {
                $candidates[] = $aliases[$head];
            }
        }
    }

    $words = explode(' ', $normalized);
    if (count($words) > 1) {
        $first = $words[0];
        if (mb_strlen($first) >= 4) {
            $candidates[] = $first;
            if (isset($aliases[$first])) {
                $candidates[] = $aliases[$first];
            }
        }
    }

    return array_values(array_unique(array_filter($candidates, static fn(string $c): bool => $c !== '')));
}

/** @var list<string> $argv */
$argv = $argv ?? [];
$sources = option($argv, 'sources');
$out = option($argv, 'out', dirname(__DIR__) . '/data/postal_codes.tsv.gz');

if ($sources === null || !is_dir($sources) || $out === null) {
    fwrite(\STDERR, "Aufruf: php tools/build_postal_codes.php --sources=<verzeichnis> [--out=<datei>]\n");
    exit(1);
}

// ---------------------------------------------------------------- Ortsindex
/** @var array<string, mixed> $citiesRaw */
$citiesRaw = json_decode((string) file_get_contents($sources . '/cities.json'), true, 512, \JSON_THROW_ON_ERROR);
/** @var array<string, mixed> $admin1Raw */
$admin1Raw = json_decode((string) file_get_contents($sources . '/admin1.json'), true, 512, \JSON_THROW_ON_ERROR);

$admin1Names = [];
foreach ($admin1Raw as $entry) {
    if (is_array($entry) && isset($entry['code'], $entry['name']) && is_string($entry['code']) && is_string($entry['name'])) {
        $admin1Names[$entry['code']] = $entry['name'];
    }
}

/** @var array<string, array<string, array{float, float, string}>> $cityIndex */
$cityIndex = [];
foreach ($citiesRaw as $city) {
    if (!is_array($city)) {
        continue;
    }
    $country = (string) ($city['country'] ?? '');
    if (!in_array($country, ['DE', 'AT', 'CH'], true)) {
        continue;
    }
    $key = normalizePlace((string) ($city['name'] ?? ''));
    if ($key === '' || isset($cityIndex[$country][$key])) {
        continue;
    }
    $admin1Code = $country . '.' . (string) ($city['admin1'] ?? '');
    $cityIndex[$country][$key] = [
        (float) ($city['lat'] ?? 0),
        (float) ($city['lng'] ?? 0),
        $admin1Names[$admin1Code] ?? '',
    ];
}

/**
 * @param array<string, array<string, array{float, float, string}>> $index
 *
 * @return array{float, float, string}|null
 */
function lookupCity(array $index, string $country, string $place): ?array
{
    foreach (nameCandidates($place) as $candidate) {
        if (isset($index[$country][$candidate])) {
            return $index[$country][$candidate];
        }
    }

    return null;
}

/** @var array<string, array<string, array{place: string, admin1: string, lat: float|null, lng: float|null, source: string}>> $rows */
$rows = ['DE' => [], 'AT' => [], 'CH' => []];

// ---------------------------------------------------------------- Deutschland
$deSource = (string) file_get_contents($sources . '/de_plz.js');
preg_match_all(
    "/\{\s*'ort':\s*'(.*?)',\s*'zusatz':\s*'(.*?)',\s*'plz':\s*(\d+),\s*'vorwahl':\s*'?(\d*)'?,\s*'bundesland':\s*'(.*?)'\s*\}/u",
    $deSource,
    $deMatches,
    \PREG_SET_ORDER,
);

foreach ($deMatches as $match) {
    $plz = str_pad($match[3], 5, '0', \STR_PAD_LEFT);
    $ort = $match[1];
    $bundesland = $match[5];

    $existing = $rows['DE'][$plz] ?? null;
    if ($existing !== null && $existing['lat'] !== null) {
        continue;
    }

    $hit = lookupCity($cityIndex, 'DE', $ort);
    $rows['DE'][$plz] = [
        'place' => $existing['place'] ?? $ort,
        'admin1' => $bundesland,
        'lat' => $hit[0] ?? null,
        'lng' => $hit[1] ?? null,
        'source' => $hit === null ? 'interpoliert' : 'geonames_place',
    ];
    if ($hit !== null) {
        $rows['DE'][$plz]['place'] = $ort;
    }
}

// ---------------------------------------------------------------- Oesterreich
$atAdmin1ByPrefix = [
    '1' => 'Wien', '2' => 'Niederösterreich', '3' => 'Niederösterreich', '4' => 'Oberösterreich',
    '5' => 'Salzburg', '6' => 'Tirol', '7' => 'Burgenland', '8' => 'Steiermark', '9' => 'Kärnten',
];

// GeoNames fuehrt die oesterreichischen Bundeslaender englisch.
$atAdmin1German = [
    'State of Vienna' => 'Wien',
    'Vienna' => 'Wien',
    'Lower Austria' => 'Niederösterreich',
    'Upper Austria' => 'Oberösterreich',
    'Salzburg' => 'Salzburg',
    'State of Salzburg' => 'Salzburg',
    'Tyrol' => 'Tirol',
    'Vorarlberg' => 'Vorarlberg',
    'Burgenland' => 'Burgenland',
    'Styria' => 'Steiermark',
    'Carinthia' => 'Kärnten',
];

$atSource = (string) file_get_contents($sources . '/at_plz.js');
preg_match_all('/"(\d{4})":\s*"(.*?)"/u', $atSource, $atMatches, \PREG_SET_ORDER);

foreach ($atMatches as $match) {
    $plz = $match[1];
    $ort = stripcslashes($match[2]);
    if (isset($rows['AT'][$plz]) && $rows['AT'][$plz]['lat'] !== null) {
        continue;
    }

    $hit = lookupCity($cityIndex, 'AT', $ort);
    $admin1 = $hit !== null && $hit[2] !== ''
        ? ($atAdmin1German[$hit[2]] ?? $hit[2])
        : ($atAdmin1ByPrefix[$plz[0]] ?? '');
    // 65xx-69xx liegen in Vorarlberg, nicht in Tirol.
    if ($plz[0] === '6' && (int) $plz >= 6500 && ($hit === null || $hit[2] === '')) {
        $admin1 = 'Vorarlberg';
    }

    $rows['AT'][$plz] = [
        'place' => $ort,
        'admin1' => $admin1,
        'lat' => $hit[0] ?? null,
        'lng' => $hit[1] ?? null,
        'source' => $hit === null ? 'interpoliert' : 'geonames_place',
    ];
}

// ---------------------------------------------------------------- Schweiz
/** @var array<string, mixed> $chRaw */
$chRaw = json_decode((string) file_get_contents($sources . '/ch_plz.json'), true, 512, \JSON_THROW_ON_ERROR);
foreach ($chRaw as $plz => $entries) {
    if (!is_array($entries) || $entries === []) {
        continue;
    }
    $entry = $entries[0];
    if (!is_array($entry)) {
        continue;
    }

    $rows['CH'][(string) $plz] = [
        'place' => (string) ($entry['name'] ?? ''),
        'admin1' => (string) ($entry['canton'] ?? ''),
        'lat' => (float) ($entry['latitude'] ?? 0),
        'lng' => (float) ($entry['longitude'] ?? 0),
        'source' => 'geonames',
    ];
}

// ------------------------------------------------- Luecken numerisch schliessen
// Deutsche und oesterreichische Postleitzahlen sind geografisch geordnet: fehlende
// Zentroide werden aus den numerisch benachbarten PLZ desselben Praefixbereichs
// interpoliert und als "interpoliert" gekennzeichnet.
foreach (['DE' => 2, 'AT' => 1] as $country => $prefixLength) {
    // PHP wandelt numerische Array-Schluessel in Integer um — fuer Praefixvergleiche
    // brauchen wir die fuehrenden Nullen zurueck.
    $codes = array_map(\strval(...), array_keys($rows[$country]));
    sort($codes, \SORT_STRING);
    $count = count($codes);

    for ($i = 0; $i < $count; ++$i) {
        if ($rows[$country][$codes[$i]]['lat'] !== null) {
            continue;
        }

        $prefix = substr($codes[$i], 0, $prefixLength);
        $before = null;
        $after = null;

        for ($j = $i - 1; $j >= 0; --$j) {
            if (substr($codes[$j], 0, $prefixLength) !== $prefix) {
                break;
            }
            if ($rows[$country][$codes[$j]]['lat'] !== null) {
                $before = $codes[$j];

                break;
            }
        }

        for ($j = $i + 1; $j < $count; ++$j) {
            if (substr($codes[$j], 0, $prefixLength) !== $prefix) {
                break;
            }
            if ($rows[$country][$codes[$j]]['lat'] !== null) {
                $after = $codes[$j];

                break;
            }
        }

        if ($before === null && $after === null) {
            continue;
        }

        if ($before === null || $after === null) {
            $neighbour = $before ?? $after;
            assert($neighbour !== null);
            $rows[$country][$codes[$i]]['lat'] = $rows[$country][$neighbour]['lat'];
            $rows[$country][$codes[$i]]['lng'] = $rows[$country][$neighbour]['lng'];

            continue;
        }

        $distanceBefore = (int) $codes[$i] - (int) $before;
        $distanceAfter = (int) $after - (int) $codes[$i];
        $total = $distanceBefore + $distanceAfter;
        $weight = $total === 0 ? 0.5 : $distanceBefore / $total;

        $latBefore = (float) $rows[$country][$before]['lat'];
        $lngBefore = (float) $rows[$country][$before]['lng'];
        $latAfter = (float) $rows[$country][$after]['lat'];
        $lngAfter = (float) $rows[$country][$after]['lng'];

        $rows[$country][$codes[$i]]['lat'] = round($latBefore + ($latAfter - $latBefore) * $weight, 5);
        $rows[$country][$codes[$i]]['lng'] = round($lngBefore + ($lngAfter - $lngBefore) * $weight, 5);
    }
}

// ---------------------------------------------------------------- Schreiben
$handle = gzopen($out, 'wb9');
if ($handle === false) {
    fwrite(\STDERR, "Ausgabedatei nicht schreibbar: {$out}\n");
    exit(1);
}

gzwrite($handle, "country\tpostal_code\tplace_name\tadmin1\tlat\tlng\tsource\n");

$statistics = [];
$written = 0;

foreach (['DE', 'AT', 'CH'] as $country) {
    $codes = array_map(\strval(...), array_keys($rows[$country]));
    sort($codes, \SORT_STRING);

    foreach ($codes as $code) {
        $row = $rows[$country][$code];
        if ($row['lat'] === null || $row['lng'] === null) {
            $statistics[$country]['ohne_koordinate'] = ($statistics[$country]['ohne_koordinate'] ?? 0) + 1;

            continue;
        }

        gzwrite($handle, implode("\t", [
            $country,
            $code,
            str_replace(["\t", "\n"], ' ', $row['place']),
            str_replace(["\t", "\n"], ' ', $row['admin1']),
            (string) $row['lat'],
            (string) $row['lng'],
            $row['source'],
        ]) . "\n");

        $statistics[$country][$row['source']] = ($statistics[$country][$row['source']] ?? 0) + 1;
        ++$written;
    }
}

gzclose($handle);

printf("%d Zeilen geschrieben nach %s\n", $written, $out);
foreach ($statistics as $country => $counters) {
    ksort($counters);
    $parts = [];
    foreach ($counters as $key => $value) {
        $parts[] = "{$key}={$value}";
    }
    printf("  %s: %s\n", $country, implode(', ', $parts));
}
