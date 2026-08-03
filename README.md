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
| 4 | Anzeige erstellen | umgesetzt |
| 5 | Nutzer, Vertrauen, Kommunikation | umgesetzt |
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
| `php tools/smoke_wizard.php [--behalten]` | Abnahme Phase 4: Anzeige komplett anlegen und veröffentlichen |

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

## Konto und Sitzung

Eigene Implementierung, kein Fremdpaket. Passwörter mit Argon2id (64 MB, 4 Durchläufe); veraltete
Kosten werden bei der nächsten Anmeldung stillschweigend nachgezogen. Sitzungen liegen in der Tabelle
`sessions`, nicht in PHP-Filesessions — die Kennung wird bei jeder Anmeldung neu vergeben, damit eine
vorher untergeschobene Kennung wertlos ist.

Unbekannte E-Mail und falsches Passwort ergeben dieselbe Meldung und dieselbe Rechenzeit; nach
`AuthenticationService::MAX_FAILED_ATTEMPTS` Fehlversuchen ist das Konto 15 Minuten gesperrt. Jedes
Formular trägt einen CSRF-Token, geprüft mit `hash_equals`.

## Anzeigenassistent

Sieben Schritte, jeder speichert sofort in den Entwurf — es gibt keinen Zustand, der nur im Browser
lebt. Der Fortschritt wird aus dem Entwurf abgeleitet, nicht gespeichert, deshalb nimmt der Assistent
auch auf einem anderen Gerät an der richtigen Stelle wieder auf. `public/assets/anzeige.js` speichert
zwischendurch über `/anzeige/{id}/autosave/{schritt}`; ohne JavaScript bleibt jeder Schritt ein
normales Formular.

Der Morph-String entsteht aus den ausgewählten Merkmalen: sichtbare zuerst, dann `het`, dann
`66%/50% poss. het` — aus drei Merkmalen wird `Hypo Trans het Zero`. Der Generator liegt hinter
`GeneticsCalculator`, damit eine spätere Vererbungsrechnung ihn ersetzen kann, ohne den Assistenten
anzufassen. Er meldet außerdem unmögliche Kombinationen (letale Paarungen, `het` auf einem dominanten
Merkmal, zwei Merkmale desselben Genorts).

Vor dem Veröffentlichen läuft `LegalGuard`. Die Entscheidung landet in jedem Fall im Audit-Log —
`listing.published` oder `listing.publish_blocked` mit den auslösenden Regeln. Zusätzlich greift die
Auto-Moderation neuer Konten (siehe unten); beide Wege können unabhängig voneinander in die Prüfung
führen.

### Bilder und Rechtsnachweise

Bilder werden **neu gezeichnet statt bearbeitet**: dekodieren, EXIF-Ausrichtung einrechnen, auf eine
frische Leinwand kopieren, als WebP schreiben. Damit überlebt kein Metadatenblock — weder EXIF mit
GPS-Koordinaten noch IPTC oder XMP. Der Test dazu baut ein JPEG mit echten GPS-Koordinaten, weist
nach, dass sie darin stehen, und prüft danach das Ergebnis.

Rechtsnachweise liegen unter `storage/private/` außerhalb des Webroots. Es gibt keine URL, die auf
eine dieser Dateien zeigt; die Auslieferung läuft ausschließlich über
`LegalDocumentController::download()`, der erst die Anmeldung, dann die Zugehörigkeit prüft. Ein
fremdes Dokument beantwortet er mit **404, nicht 403** — ein 403 würde bestätigen, dass es die Datei
gibt.

## Vertrauen und Missbrauchsabwehr

Schwellwerte und Wortlisten stehen in [`config/trust.php`](config/trust.php), nicht im Code. Ein
unbekannter Schlüssel oder ein falscher Typ führt beim Aufbau zu einem Fehler — eine stillschweigend
übersprungene Schutzmaßnahme wäre schlimmer als ein Startfehler.

**Kontaktmaskierung.** In den ersten drei Nachrichten eines Gesprächs werden E-Mail-Adressen und
Telefonnummern ausgeblendet. Der Zweck ist nicht, den Austausch zu verhindern — das lässt sich
ohnehin umgehen —, sondern das massenhafte Einsammeln von Adressen durch automatisiertes Anschreiben
unattraktiv zu machen. Maskiert wird beim **Anzeigen**, nicht beim Speichern: In der Datenbank steht
weiter das Original, weil die Moderation im Missbrauchsfall den echten Wortlaut braucht.

**Keyword-Filter.** Ein Wortfilter ist ein Verdacht, kein Urteil. Deshalb hält nur die Gruppe der
Zahlungswege ohne Rückholmöglichkeit (Western Union, Gutscheinkarten, „PayPal Freunde") eine
Nachricht zurück; alles andere geht durch und landet in der Moderationsliste. Die Meldung an den
Absender nennt die Treffer nicht — sonst ließe sich die Wortliste durch Ausprobieren rekonstruieren.

**Rate-Limits** laufen über ein gleitendes Fenster. Die übliche Zählervariante mit festem Fenster
lässt an der Fenstergrenze die doppelte Menge durch. Ein abgewiesener Versuch wird nicht mitgezählt,
sonst verlängerte jeder entnervte Klick die Sperre.

**Auto-Moderation.** Die ersten drei Anzeigen eines neuen Kontos gehen in die Prüfung. Die Regel
greift nur bei jungen Konten: Wer seit Monaten dabei ist, ist kein Wegwerfkonto.

**Bewertungen** entstehen ausschließlich aus einem **beidseitig** bestätigten Handel. Ohne diese
Bedingung ließe sich ein Konto mit erfundenen Bewertungen aufwerten oder ein fremdes herabsetzen.

## Konto und Verifizierung

Drei aufeinander aufbauende Stufen: E-Mail → Telefon → Identität. Die ersten beiden erledigt der
Nutzer selbst über einen Einmal-Token, die dritte prüft ein Mensch. Der Ausweis- oder
Gewerbenachweis liegt wie die Rechtsnachweise außerhalb des Webroots.

Token existieren im Klartext genau einmal: auf dem Weg zum Nutzer. In der Datenbank steht nur der
SHA-256-Hash — bewusst kein Argon2id, denn der Token ist bereits 256 Bit Zufall, und ein langsames
Verfahren kostete bei jedem Klick auf einen Bestätigungslink Rechenzeit, ohne etwas zu schützen.

Zwei-Faktor läuft über eine eigene TOTP-Umsetzung nach RFC 6238 (geprüft gegen die Testvektoren aus
Anhang B). Eigene Umsetzung statt Fremdpaket: dreißig Zeilen Kern, seit 2011 unveränderte
Schnittstelle — und eine Abhängigkeit weniger genau im Anmeldeweg. Scharf wird die zweite Stufe erst,
wenn ein Code aus der App stimmt; abschalten geht nur mit Passwort.

## Identitätsquelle

Die Plattform läuft vollständig eigenständig. `IDENTITY_PROVIDER=local` bedient die eigene
`users`-Tabelle; `Reptilienmarkt\Domain\Identity\IdentityProvider` ist die Naht, an der eine spätere
Kopplung an das DragonReptiles-CMS ansetzt, ohne dass Anmeldung, Assistent oder Postfach etwas davon
merken. Ein nicht umgesetzter Wert führt beim Aufbau zu einem Fehler — das ist ehrlicher als eine
stillschweigend lokale Anmeldung.

## Sprache

Alle Oberflächentexte laufen über `Reptilienmarkt\Support\Translator` und stehen in
[`lang/de-DE.php`](lang/de-DE.php). Fehlt ein Schlüssel, zeigt die Seite den Schlüssel selbst — das
fällt auf, statt eine leere Stelle zu hinterlassen. Ein Test vergleicht die in den Templates
verwendeten Schlüssel gegen den Katalog.

> Der Übersetzer ist in Phase 5 eingeführt. Die Templates aus den Phasen 1 bis 4 tragen ihre Texte
> noch direkt im Markup; sie nachzuziehen ist offen und rein mechanisch.

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
              Auth, Listing, Message, Moderation, Review, Trust, User, ...
src/Infra/    PDO-Repositories, Importer
src/Http/     Controller, Middleware
src/Legal/    LegalGuard und Regelwerk
src/Support/  Env, Container
lang/         Sprachkataloge, de-DE als Basis
storage/      db/, private/ (Rechts- und Identitätsnachweise, außerhalb des Webroots), mail/
templates/    Twig
tests/
tools/        Werkzeuge für Datensätze, Messungen und Abnahme (laufen nicht im Betrieb)
```
