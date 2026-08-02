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
| 3 | Suche und Browsing | offen |
| 4 | Anzeige erstellen | offen |
| 5 | Nutzer, Vertrauen, Kommunikation | offen |
| 6 | Monetarisierung (vorbereiten) | offen |
| 7 | Admin, DSGVO, Betrieb | offen |

## Voraussetzungen

PHP 8.3 oder neuer mit `pdo_sqlite`, `mbstring`, `json`, `zlib`. SQLite ab 3.35 (FTS5, `json_valid`,
`VACUUM INTO`). Kein Docker nötig — die Anwendung ist für Debian/LAMP ausgelegt.

## Einrichtung

```bash
composer install
cp .env.example .env          # APP_ENV=local für die Entwicklung
php bin/migrate.php up
php bin/seed.php
```

`bin/seed.php` legt den Artenstamm, den Merkmalskatalog und die Postleitzahlentabelle an
(Laufzeit unter einer Sekunde).

## Kommandozeile

| Befehl | Zweck |
|---|---|
| `php bin/migrate.php status` | Übersicht angewandter und offener Migrationen |
| `php bin/migrate.php up [--step=N] [--pretend]` | Migrationen anwenden |
| `php bin/migrate.php down [--step=N]` | Letzten Batch bzw. N Migrationen zurücknehmen |
| `php bin/migrate.php fresh` | Neu aufbauen (in `APP_ENV=production` gesperrt) |
| `php bin/seed.php` | Stammdaten einspielen, wiederholbar (Upsert) |
| `php bin/import_postal_codes.php [--geonames=DE.txt]` | Postleitzahlen einspielen bzw. durch exakte GeoNames-Zentroide ersetzen |

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
