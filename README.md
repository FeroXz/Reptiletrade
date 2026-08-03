# Reptilienmarkt

Verkaufs- und Tauschplattform für Terrarientiere im DACH-Raum. Eigenständige PHP-Anwendung,
kein CMS-Plugin.

Architekturentscheidungen (Router, SQLite vs. PostgreSQL, Migrationsstrategie, Klassenübersicht):
[`docs/ARCHITEKTUR.md`](docs/ARCHITEKTUR.md).

## Stand

| Phase | Inhalt | Status |
|---|---|---|
| 1 | Datenmodell und Migrationen | umgesetzt |
| 2 | Rechts-Engine (`LegalGuard`) | umgesetzt |
| 3 | Suche und Browsing | umgesetzt |
| 4 | Anzeige erstellen | offen |
| 5 | Nutzer, Vertrauen, Kommunikation | offen |
| 6 | Monetarisierung (vorbereiten) | offen |
| 7 | Admin, DSGVO, Betrieb | offen |

## Voraussetzungen

PHP 8.3 oder neuer mit `pdo_sqlite`, `mbstring`, `json`, `zlib`. SQLite ab 3.35 mit FTS5,
`json_valid` und den Mathematikfunktionen (`SQLITE_ENABLE_MATH_FUNCTIONS`) — letztere rechnen die
Haversine-Distanz der Umkreissuche. Kein Docker nötig, die Anwendung ist für Debian/LAMP ausgelegt.

Node wird **nur zur Entwicklungszeit** gebraucht: Das gebaute CSS liegt unter `public/assets/` im
Repository.

## Einrichtung

```bash
composer install
cp .env.example .env          # APP_ENV=local für die Entwicklung
php bin/migrate.php up
php bin/seed.php
```

`bin/seed.php` legt Artenstamm, Merkmalskatalog, Postleitzahlen, Rechtstexte und Betriebsschalter an
(Laufzeit unter einer Sekunde).

Lokal starten:

```bash
php -S 127.0.0.1:8000 -t public public/index.php
```

Frontend-Assets neu bauen (nur nach Änderungen an `assets/app.css` oder den Templates):

```bash
npm install && npm run build
```

## Kommandozeile

| Befehl | Zweck |
|---|---|
| `php bin/migrate.php status` | Übersicht angewandter und offener Migrationen |
| `php bin/migrate.php up [--step=N] [--pretend]` | Migrationen anwenden |
| `php bin/migrate.php down [--step=N]` | Letzten Batch bzw. N Migrationen zurücknehmen |
| `php bin/migrate.php fresh` | Neu aufbauen (in `APP_ENV=production` gesperrt) |
| `php bin/seed.php` | Stammdaten einspielen, wiederholbar (Upsert) |
| `php bin/import_postal_codes.php [--geonames=DE.txt]` | Postleitzahlen einspielen bzw. durch exakte GeoNames-Zentroide ersetzen |
| `php bin/reindex.php` | Volltextindex vollständig neu aufbauen |
| `php tools/generate_demo_listings.php --anzahl=50000` | Demo-Anzeigen für Messungen (nicht in Produktion) |
| `php tools/benchmark_search.php --schreiben` | Suche messen und `docs/SUCHE.md` schreiben |

## Qualitätssicherung

```bash
vendor/bin/phpunit
vendor/bin/php-cs-fixer fix --dry-run --diff
vendor/bin/phpstan analyse            # Level 8
```

## Rechts-Engine

`Reptilienmarkt\Legal\LegalGuard` ist bei jedem Listing-Submit und bei jeder Statusänderung auf
`aktiv` aufzurufen:

```php
$decision = $container->get(LegalGuard::class)->evaluate($context);

$decision->blocked;         // Veröffentlichung nicht möglich
$decision->requiresReview;  // Status "pruefung", Admin-Freigabe nötig
$decision->requiredFields;  // Feldnamen für den Formularschritt "Rechtsnachweise"
$decision->notices;         // Hinweistexte aus der Tabelle legal_texts
$decision->auditPayload();  // Begründungen für audit_log.data_json
```

Das Regelwerk steht vollständig in [`config/legal_rules.php`](config/legal_rules.php), die Hinweistexte
in der Tabelle `legal_texts`. Beides ist ohne Codeänderung pflegbar; jede Regel lässt sich einzeln
über `enabled` abschalten. Ein Tippfehler in der Konfiguration führt beim Aufbau der Engine zu einem
Fehler und nicht zu einer stillschweigend übersprungenen Regel.

`LegalTextReview::warning()` liefert dem Admin-Dashboard die Meldung über Rechtstexte, deren Prüfung
länger als `review_max_age_months` zurückliegt. Nie geprüfte Texte zählen als überfällig — nach dem
Seed sind das zunächst alle.

> **Keine Rechtsberatung.** Die Engine setzt um, was der Betreiber konfiguriert hat. Der vollständige
> Disclaimer steht in `Reptilienmarkt\Legal\Disclaimer` und gehört über jede Admin-Ansicht der
> Rechts-Engine. Die Pflicht, Regelwerk, Rechtstexte und Artenstamm zu prüfen und aktuell zu halten,
> liegt beim Betreiber.

## Suche

Die Facettensuche liegt hinter `ListingSearchRepository`. Eine Suche besteht aus vier Abfragen:
Trefferseite, Gesamtzahl, je Facette eine Gruppierung **ohne die eigene Dimension** (sonst fiele
jeder nicht gewählte Wert auf null) und eine Abfrage für die Merkmalsnamen der Seite.

Die Umkreissuche läuft zweistufig: Bounding-Box über `idx_listings_geo`, danach Haversine auf dem
Vorfilter — nie über die ganze Tabelle. Messwerte und `EXPLAIN QUERY PLAN` aller Abfragetypen stehen
in [`docs/SUCHE.md`](docs/SUCHE.md); `PdoListingSearchRepository::explain()` erzeugt sie jederzeit neu.

URLs gibt es in zwei Formen, die dieselbe Suche ergeben:

```
/markt/bartagame/red-hypo-translucent/bayern/
/markt/?art_id=1&morph=7&morph=8&morph=9&region=Bayern
```

Der Pfad entsteht nur, wenn er verlustfrei ist — sobald eine Zygosität mitgefiltert wird, wandern die
Merkmale in die Query. `/art/pogona-vitticeps/` ist die kanonische Artenprofil-Landingpage.

Die Trefferliste wird serverseitig gerendert. `public/assets/markt.js` fängt Filterklicks ab, holt
dieselbe URL erneut und tauscht nur den Ergebnisbereich aus (`history.pushState`). Ohne JavaScript
funktioniert alles unverändert — jede Facette ist ein echter Link, jeder Filter ein echtes Formular.

## Datensätze

Alle Datensätze liegen lokal im Repository, zur Laufzeit gibt es keinen API-Aufruf. Herkunft,
Lizenzen und Genauigkeit stehen in [`data/README.md`](data/README.md).

> Der Schutzstatus im Artenstamm ist ein gepflegter Ausgangsdatensatz und **keine Rechtsberatung**.
> CITES-Anhänge und die Anhänge der EG-VO 338/97 ändern sich nach jeder Vertragsstaatenkonferenz.
> Die Pflicht zur Prüfung und laufenden Aktualisierung liegt beim Betreiber.

## Verzeichnisse

```
bin/          CLI: migrate, seed, import_postal_codes
config/       .env-Laden, Container
data/         Artenstamm, Merkmalskatalog, Postleitzahlen
docs/         Architekturplan
migrations/   Versionierte Migrationen, eine Datei je Migration
public/       Front-Controller und Assets
src/Domain/   Entitäten, Value Objects, Repository-Interfaces (framework- und PDO-frei)
src/Infra/    PDO-Repositories, Importer
src/Http/     Controller, Middleware
src/Legal/    LegalGuard und Regelwerk
src/Support/  Env, Container
storage/      db/, private/ (Rechtsdokumente, außerhalb des Webroots)
templates/    Twig
tests/
tools/        Werkzeuge zum Erzeugen der Datensätze (laufen nicht im Betrieb)
```
