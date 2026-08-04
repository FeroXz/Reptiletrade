# Reptilienmarkt

Verkaufs- und Tauschplattform für Terrarientiere im DACH-Raum. Eigenständige PHP-Anwendung,
kein CMS-Plugin.

**Installation auf einem Server:** [`docs/INSTALLATION.md`](docs/INSTALLATION.md) — Document Root,
Rechte, Webserver-Konfiguration, Administratorkonto, Cron, und was bei typischen Fehlern zu tun ist.

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
| 6 | Monetarisierung (vorbereitet, **nicht aktiviert**) | umgesetzt |
| 7 | Admin, DSGVO, Betrieb | umgesetzt |
| 8 | Anzeigen verwalten (bearbeiten, pausieren, löschen) | umgesetzt |

## Voraussetzungen

PHP 8.3 oder neuer mit `pdo_sqlite`, `mbstring`, `json`, `zlib`. SQLite ab 3.35 mit FTS5,
`json_valid` und den Mathematikfunktionen (`SQLITE_ENABLE_MATH_FUNCTIONS`) — letztere rechnen die
Haversine-Distanz der Umkreissuche. Kein Docker nötig, die Anwendung ist für Debian/LAMP ausgelegt.

Node wird **nur zur Entwicklungszeit** gebraucht: Das gebaute CSS liegt unter `public/assets/` im
Repository.

## Einrichtung

Für einen echten Server: [`docs/INSTALLATION.md`](docs/INSTALLATION.md). Lokal genügt:

```bash
composer install
cp .env.example .env          # APP_ENV=local für die Entwicklung
php bin/migrate.php up
php bin/seed.php
php bin/admin.php anlegen --email=admin@localhost.test
```

> **Der Document Root ist `public/`, nicht die Projektwurzel.** Zeigt die Domain eine Ebene zu hoch,
> sind `.env`, die SQLite-Datei und alle Rechtsnachweise über den Browser abrufbar.

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
| `php bin/billing.php status` | Tarife, Boosts und Schalterstellung anzeigen |
| `php bin/billing.php ablauf` | Abgelaufene Top-Platzierungen und Abos aufräumen |
| `php bin/doctor.php` | Selbsttest: Erweiterungen, `.env`, Rechte, Datenbank, Sitzungen, Aufträge |
| `php bin/admin.php anlegen --email=…` | Verwaltungskonto anlegen (Passwort wird abgefragt) |
| `php bin/admin.php ernennen --email=…` | Vorhandenes Konto zum Administrator machen |
| `php bin/admin.php passwort --email=…` | Passwort setzen, offene Sitzungen beenden |
| `php bin/admin.php liste` | Alle Verwaltungs- und Moderationskonten |
| `php bin/cron.php` | Fällige wiederkehrende Aufgaben einplanen (ein Cron-Eintrag genügt) |
| `php bin/cron.php plan` | Den Zeitplan anzeigen |
| `php bin/cron.php jetzt TYP` | Einen Auftrag von Hand einplanen |
| `php bin/worker.php [--einmal]` | Aufträge abarbeiten |
| `php bin/backup.php [--ziel=… --behalten=N]` | Datenbank sichern (`VACUUM INTO`) |
| `php tools/smoke_betrieb.php [--behalten]` | Abnahme Phase 7: Verwaltung, Auskunft, Löschung, Betrieb |

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

Das `Secure`-Flag des Sitzungs-Cookies leitet sich aus der tatsächlichen Verbindung ab, nicht aus
`APP_URL`: Ein `Secure`-Cookie auf einer HTTP-Seite wird vom Browser verworfen, und ohne Cookie gibt
es keine Sitzung, ohne Sitzung keinen CSRF-Token — jedes Formular endet dann mit „Das Formular ist
abgelaufen“. Eine falsch gesetzte Variable darf die Anmeldung nicht unmöglich machen. Hinter einem
TLS-Proxy erkennt die Anwendung die Verschlüsselung an `X-Forwarded-Proto`.

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

## Anzeige verwalten

Nach der Veröffentlichung führt der Weg nicht mehr durch den Assistenten, sondern über ein Formular
unter `/anzeige/{id}/bearbeiten`. **Art und Angebotstyp bleiben fest**: Sie zu ändern hieße,
Rechtsprüfung, Merkmalsauswahl und hochgeladene Nachweise auf eine andere Grundlage zu stellen —
dafür gibt es eine neue Anzeige. Jede Bearbeitung wird in `listing_edits` protokolliert und die
Anzeige danach neu bewertet: Blockiert die Rechts-Engine sie jetzt, geht sie zurück in die Prüfung,
statt mit der Änderung weiterzulaufen.

**Pausieren** ist ein eigener Zustand (`pausiert`), kein zweites Kennzeichen neben dem Status. Ein
`is_paused`-Feld wäre eine zweite Wahrheit über dieselbe Frage, und jede Suchabfrage müsste beides
prüfen — die Teilindizes aus Phase 3 sind alle über `status IN ('aktiv','reserviert')` eingeschränkt
und wären damit unbrauchbar geworden (gemessen: 4,5 ms gegen 115 ms). Der Preis ist ein
Tabellenumbau in der Migration, weil SQLite einen `CHECK` nicht nachträglich ändert.

Wer pausiert hat, steht in `paused_by` — und daran hängt die Regel: **Eine Pause der Verwaltung hebt
der Anbieter nicht selbst auf.** Sonst wäre die Maßnahme einen Klick wert. Der Grund ist Pflicht und
wird dem Anbieter gezeigt; wer nicht erfährt, warum seine Anzeige stillsteht, kann es nicht
abstellen. Beim Fortsetzen geht es in den Zustand von vorher zurück — eine reservierte Anzeige ist
nach der Pause nicht plötzlich wieder frei.

**Löschen** entfernt die Anzeige nur, wenn nichts Fremdes daran hängt. Gibt es Gespräche oder
Bewertungen, gehören die auch der Gegenseite: Dann werden Bilder und Nachweise gelöscht und die
Anzeige abgeschaltet, aber als Bezugspunkt behalten — dieselbe Abwägung wie bei der Kontolöschung.
Bestätigt wird mit dem getippten Wort `LÖSCHEN`.

Die Verwaltung sieht unter `/admin/anzeigen` alle Anzeigen samt Zustand, offenen Meldungen und
Pausenangaben, filterbar nach Zustand, nach „nur pausierte“ und über eine Textsuche in Titel,
Anbietername und E-Mail. Abgegrenzt von der Moderation: Die entscheidet über die Prüfliste und sperrt
dauerhaft (`gesperrt`), die Pause hier ist vorläufig und wird zurückgenommen, sobald die Sache
geklärt ist.

## Verteidigungslinien

Jede Antwort trägt eine Content-Security-Policy: `default-src 'self'`, `script-src 'self'` — **ohne**
`unsafe-eval` und ohne `unsafe-inline`. Twig escapet durchgehend; die CSP ist die zweite Linie, die
darüber entscheidet, ob eingeschleuster Code auch ausgeführt wird. Damit sie das kann, wurde Alpine
entfernt: Seine Ausdrücke stehen im Markup und werden zur Laufzeit aus Zeichenketten gebaut, was
genau das `unsafe-eval` verlangt hätte, das die Regel verhindern soll. Die einzige echte Nutzung —
der Facetten-Umschalter auf schmalen Bildschirmen — sind jetzt zwölf Zeilen in `markt.js`.
`'unsafe-inline'` bleibt bei `style-src`, weil die Templates Farben in `style`-Attributen tragen;
über CSS lässt sich kein Code ausführen.

Die Rate-Limits aus `config/trust.php` sind vollständig angeschlossen — drei von ihnen waren lange
konfiguriert, aber an keinen Controller gehängt:

| Grenze | Wirkung |
|---|---|
| `registrierung.ip` (5 / Stunde) | Massenanlage von Konten. Die E-Mail-Bestätigung hilft dagegen nicht: Das Konto existiert vorher. |
| `anmeldung.ip` (30 / 15 Min.) | Passwort-Spraying über viele Konten. Die Kontosperre schützt nur ein einzelnes Konto. |
| `anzeige.konto` (20 / Tag) | Fluten mit Anzeigen. Gezählt wird das Anlegen, nicht das Veröffentlichen — sonst genügten Entwürfe. |

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

## Monetarisierung — vorbereitet, nicht aktiviert

`config/monetarisierung.php` steht auf `enabled => false`, der Zahlungsanbieter ist `keiner`. In
diesem Zustand verhält sich die Plattform wie vorher: **keine Begrenzung der Anzeigenzahl, alle
Merkmale offen, kein Kauf möglich**. Umgelegt wird ein Schalter, nicht ein Umbau — das Setting
`billing.enabled` sticht die Datei, damit der Betreiber ohne Deployment ein- und ausschalten kann.

`EntitlementService` ist die einzige Stelle, die den Schalter auswertet. Der Rest der Anwendung
fragt nur „darf dieses Konto das?" und nie nach Tarif oder Abo.

| | Kostenlos | Züchter |
|---|---|---|
| Preis | 0 € | 9,90 €/Monat, 99 €/Jahr |
| Aktive Anzeigen | 3 | unbegrenzt |
| Laufzeit je Anzeige | 60 Tage | 90 Tage |
| Bilder je Anzeige | 12 | 24 |
| Profilseite, Statistiken, Nachzucht-Ankündigungen | — | ja |

Top-Platzierung: 7 Tage 4,90 €, 14 Tage 7,90 €, 30 Tage 14,90 €. Der Boost setzt
`listings.is_featured`, wonach die Trefferliste sortiert — bewusst denormalisiert, weil ein Join auf
die Boost-Tabelle den in Phase 3 gemessenen Abfrageplan zerstören würde. Der Preis dieser
Entscheidung ist, dass abgelaufene Boosts aktiv abgeräumt werden müssen: `bin/billing.php ablauf`,
später ein Job.

**Zahlungsablauf.** Erst entsteht ein offener Beleg, dann führt die Rückmeldung des Anbieters ihn auf
„bezahlt". Freigeschaltet wird ausschließlich über diese Rückmeldung, **nie** über die Rückkehr des
Browsers von der Bezahlseite — die lässt sich aufrufen, ohne bezahlt zu haben. Mehrfachzustellung
eines Webhooks ist der Normalfall und nicht die Ausnahme, deshalb ist die Verarbeitung idempotent.

`PaymentProvider` ist bewusst schmal: Bezahlseite eröffnen, Rückmeldung entgegennehmen, Abo beenden.
`NullPaymentProvider` ist die Voreinstellung und lehnt jeden Vorgang mit klarer Meldung ab — eine
versehentlich freigeschaltete Kaufseite führt damit zu einem sichtbaren Fehler statt zu einer halb
angelegten Bestellung. `StripePaymentProvider` liegt als Referenz bei, ohne SDK und ohne Aktivierung;
die Webhook-Signatur wird nach Stripes Schema geprüft, alte Zeitstempel werden abgewiesen.

**Kein Treuhandservice in v1.** Die Plattform nimmt kein Geld für Tierverkäufe entgegen, sondern nur
für eigene Leistungen. Damit gibt es keine Zahlung zwischen Nutzern, die abgesichert werden müsste —
und keine der Pflichten, die daran hängen.

> Vor dem Umlegen des Schalters gehören AGB, Widerrufsbelehrung und Preisangaben nach PAngV geprüft.
> Das ist keine Codefrage, und der Code prüft es auch nicht.

## Betrieb

Wiederkehrende Arbeit läuft über eine Auftragstabelle und `bin/worker.php`, nicht über einen
Message-Broker: Ein Broker wäre ein zweiter Dienst, den jemand betreiben, überwachen und sichern
müsste — für eine Handvoll Aufgaben pro Stunde ist das kein guter Tausch.

Der Zeitplan steht in `Reptilienmarkt\Domain\Job\JobScheduler` und damit im Code, nicht in der
Crontab. Auf dem Server genügen zwei Einträge:

```cron
0  * * * *  php /pfad/bin/cron.php          # fällige Aufgaben einplanen
*/5 * * * *  php /pfad/bin/worker.php --einmal
30 2 * * *  php /pfad/bin/backup.php
```

| Auftrag | Wann | Zweck |
|---|---|---|
| `listing.archive` | stündlich | Abgelaufene Anzeigen abschalten |
| `billing.expire` | stündlich | Abgelaufene Top-Platzierungen und Abos beenden |
| `retention.enforce` | 03:00 UTC | Aufbewahrungsfristen umsetzen |
| `media.cleanup` | 04:00 UTC | Verwaiste Bilddateien entfernen |
| `log.rotate` | 04:00 UTC | Alte Protokolldateien entfernen |
| `listing.expiry_notice` | 06:00 UTC | Erinnerung an ablaufende Anzeigen (T-7, T-1) |
| `saved_search.alert` | 07:00 UTC | Treffer zu gespeicherten Suchen melden |
| `search.reindex` | nur von Hand | Volltextindex neu aufbauen |

Ein fehlgeschlagener Auftrag hält den Worker nicht an: Er wird mit wachsendem Abstand
(1, 5, 15, 60 Minuten) wiederholt und gilt erst nach aufgebrauchten Versuchen als gescheitert.
Gescheiterte Aufträge werden **nicht** aufgeräumt — sie stehen im Dashboard und warten auf einen
Menschen. Bricht ein Worker mitten im Lauf ab, gibt der nächste Lauf den Auftrag nach 30 Minuten
wieder frei.

Gesichert wird über `VACUUM INTO`, nicht über `cp`: Eine Dateikopie im laufenden Betrieb erwischt
die Datenbank mitten in einer Transaktion und liefert im WAL-Modus eine Datei ohne das zugehörige
Write-Ahead-Log. Die Sicherung ist dann still unbrauchbar und fällt erst beim Zurückspielen auf.

Protokolle sind JSON-Zeilen unter `LOG_DIRECTORY`, eine Datei je Tag. Bekannte Geheimnisfelder
(Passwort, Token, Secret, Cookie, Authorization) werden vor dem Schreiben ersetzt. Der Audit-Trail
in der Datenbank ist etwas anderes: Er ist per Trigger append-only, dokumentiert
Rechtsentscheidungen und Moderationsvorgänge und wird **nie** rotiert.

## Verwaltung

Es gibt **kein voreingestelltes Administratorkonto** und keinen Einrichtungsassistenten im Browser:
Ein mitgeliefertes Standardpasswort wird vergessen, und eine offene Einrichtungsseite ist so lange
eine offene Tür, wie sie niemand schließt. Der erste Administrator entsteht auf der Kommandozeile
mit `php bin/admin.php anlegen --email=…`; wer den Zugang verliert, setzt ihn dort neu. Eine
Hintertür gibt es nicht.

`/admin/` verlangt die Rolle `admin`; Moderation reicht nicht. Wer keine hat, bekommt 404 statt 403 —
die Verwaltung muss sich nicht dadurch verraten, dass sie einen Zugriff ablehnt.

Das Dashboard zeigt zuerst, was auf eine Entscheidung wartet (Meldungen, Anzeigen in Prüfung,
Nachweise, markierte Nachrichten), dann Bestandszahlen, Anzeigen pro Tag, die meistgehandelten Arten
und schließlich den Betriebsstand samt Aufbewahrungsfristen und gescheiterten Aufträgen.

Unter `/admin/artenstamm` lassen sich Arten und Merkmale als CSV oder JSON exportieren und wieder
einlesen. Der Import ist zweistufig: erst die ganze Datei prüfen, dann schreiben. Eine einzige
beanstandete Zeile verhindert den Import vollständig — beim Schutzstatus ist ein halber Datensatz
gefährlicher als gar keiner. Zugeordnet wird über den wissenschaftlichen Namen, nicht über die
Kennung; unbekannte Spalten werden übergangen, damit ein Export aus einer neueren Fassung einlesbar
bleibt. Ein Probelauf prüft, ohne zu schreiben.

## Datenschutz

`/konto/daten` bündelt beide Betroffenenrechte, ohne Rückfrage und ohne Begründungspflicht:

**Auskunft (Art. 15).** Ein JSON-Download mit allem, was zum Konto gespeichert ist. Nicht enthalten
sind Passwort-Hash und TOTP-Geheimnis: Das sind Zugangsmittel, keine Daten über die Person, und ihre
Ausgabe nützte nur jemandem mit übernommener Sitzung.

**Löschung (Art. 17).** Verlangt Passwort **und** das getippte Wort `LÖSCHEN` — eine übernommene
Sitzung soll kein Konto auslöschen können, und ein Fehlklick wäre unwiderruflich. Gibt es zum Konto
keine Bewertungen, wird vollständig gelöscht. Gibt es welche, wird **anonymisiert statt gelöscht**:
Eine Bewertung ist die Aussage über einen Handel mit zwei Beteiligten, und sie mitzulöschen würde die
Bewertungshistorie der Gegenseite verfälschen. Übrig bleibt ein Konto ohne Namen, Adresse und
Kontaktdaten; Anzeigen werden abgeschaltet, Bilder und Nachweise gelöscht. Ein laufendes Abo
blockiert die Löschung, weil es danach nicht mehr kündbar wäre.

Die Fristen stehen in [`config/aufbewahrung.php`](config/aufbewahrung.php) — je Datenart eine, weil
die Gründe verschieden sind: Rechtsnachweise belegen im Streitfall die Rechtmäßigkeit einer Abgabe
und liegen zehn Jahre, Identitätsnachweise haben ihren Zweck nach der Prüfung erfüllt und gehen nach
30 Tagen. Eine Frist von `0` schaltet die jeweilige Löschung ab; das ist eine Betreiberentscheidung,
kein Fehler.

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
bin/          CLI: migrate, seed, import_postal_codes, admin, worker, cron, backup
config/       .env-Laden, Container
data/         Artenstamm, Merkmalskatalog, Postleitzahlen
docs/         Architekturplan
migrations/   Versionierte Migrationen, eine Datei je Migration
public/       Front-Controller und Assets
src/Domain/   Entitäten, Value Objects, Repository-Interfaces (framework- und PDO-frei)
              Auth, Listing, Message, Moderation, Review, Trust, User, ...
src/Infra/    PDO-Repositories, Importer
src/Http/     Controller, Middleware
src/Infra/Payment/  Zahlungsanbieter (Null und Stripe als Referenz)
src/Legal/    LegalGuard und Regelwerk
src/Support/  Env, Container
lang/         Sprachkataloge, de-DE als Basis
storage/      db/, private/ (Rechts- und Identitätsnachweise, außerhalb des Webroots), mail/
templates/    Twig
tests/
tools/        Werkzeuge für Datensätze, Messungen und Abnahme (laufen nicht im Betrieb)
```
