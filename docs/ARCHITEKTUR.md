# Architekturplan — Reptilienmarkt

Verkaufs- und Tauschplattform für Terrarientiere (DACH). Eigenständige Anwendung, kein CMS-Plugin.

## Router: eigener Front-Controller (kein Slim 4)

**Entscheidung: schlanker eigener Front-Controller in `public/index.php` + `src/Http/Routing`.**

Begründung:

1. **Deployment-Ziel Debian/LAMP.** Slim 4 zieht eine PSR-7-Implementierung, PSR-15-Middleware und einen
   PSR-11-Container nach — ca. 15 zusätzliche Pakete für Funktionalität, die diese Anwendung zu ~20 %
   nutzt. Ein `vendor/`-Verzeichnis, das per FTP/rsync auf ein Shared-Hosting-LAMP wandert, bleibt so klein.
2. **Auth und Session sind ohnehin Eigenbau.** Session-Tabelle in der DB, Argon2id, TOTP — die
   PSR-15-Middleware-Ökosystemvorteile von Slim entfallen genau dort, wo sie normalerweise zahlen.
3. **Zwei Ausgabekanäle, ein Kern.** Web-Routen (Twig) und `/api/v1/` (JSON) teilen sich dieselben
   Domain-Services. Der Router muss lediglich Pfad + Methode auf einen Controller mappen und eine
   Middleware-Kette abarbeiten; das sind ~200 Zeilen, die wir vollständig verstehen und testen.
4. **Risiko bewusst akzeptiert:** kein Ökosystem-Middleware-Fundus, Eigenpflege bei CVEs im Routing.
   Gegenmaßnahme: Die Router-Schnittstelle bleibt bewusst PSR-15-nah (`handle(Request): Response`),
   sodass ein späterer Umstieg auf Slim die Controller nicht anfasst.

Die Request-/Response-Objekte sind eigene, unveränderliche Klassen (`src/Http/Message`) mit derselben
Semantik wie PSR-7, aber ohne Streams — Uploads laufen über `UploadedFile`, Auslieferung privater
Dokumente über `readfile()` im Controller.

## SQLite vs. PostgreSQL

**Default: SQLite** (WAL, `foreign_keys=ON`, `busy_timeout=5000`). Für eine DACH-Nischenplattform mit
lesedominiertem Verkehr ist SQLite dem Betrieb einer separaten DB-Instanz überlegen: keine Netzwerk-Latenz,
atomare Backups per `VACUUM INTO`, FTS5 eingebaut.

**Umstiegsschwelle auf PostgreSQL — bei Erreichen eines der Werte:**

| Kennzahl | Schwelle |
|---|---|
| Aktive Listings | > 250 000 |
| Schreibvorgänge (Insert/Update) | > 20 / Sekunde im Tagesmittel |
| Gleichzeitige Schreiber (Web + Worker) | > 4 Prozesse mit `SQLITE_BUSY` im Log |
| Anwendungsserver | mehr als einer (kein geteiltes Dateisystem) |
| DB-Größe | > 20 GB |

Zielkonflikt-Freiheit wird durch drei Regeln gesichert:

- **Kein SQL außerhalb von `src/Infra/Persistence`.** Die Domain kennt nur Repository-Interfaces
  (`SpeciesRepository`, `ListingRepository`, …) mit Kriterien-Objekten, keine Query-Strings.
- **Keine SQLite-Spezifika in der Domain.** Booleans wandern als `INTEGER 0|1` durch einen Mapper,
  JSON-Spalten über `JsonColumn`, Zeitstempel als UTC-`TEXT` (ISO-8601) — alles in PostgreSQL
  1:1 abbildbar.
- **Volltext hinter `SearchIndex`-Interface.** SQLite-Implementierung nutzt FTS5, die PostgreSQL-Variante
  später `tsvector`. Kein Aufrufer sieht `MATCH`.

## Klassenübersicht (Zielbild)

```
src/Domain/
  Species/        Species, Morph, Inheritance(enum), CitesAppendix(enum), EuAnnex(enum),
                  BnatschgStatus(enum), CareLevel(enum), SpeciesRepository, MorphRepository
  Listing/        Listing, ListingMorph, Zygosity(enum), ListingType(enum), ListingStatus(enum),
                  Sex(enum), CbStatus(enum), Handover(enum), ListingRepository, MorphStringGenerator
  Geo/            PostalCode, Country(enum), Coordinates, BoundingBox, Distance, PostalCodeRepository
  User/           User, Role(enum), VerificationLevel(enum), UserRepository, IdentityProvider
  Trust/          Review, Report, ReportReason(enum), ReviewRepository, ReportRepository
  Messaging/      Conversation, Message, ConversationRepository, ContactMasker
  Audit/          AuditEntry, AuditAction(enum), AuditLog
  Job/            Job, JobRepository, JobHandler
src/Legal/        LegalGuard, LegalDecision, LegalRule (+ Regelimplementierungen), LegalTextRepository
src/Infra/
  Persistence/    Database, Pdo*Repository, Mapper, Migrator, Migration
  Search/         SearchIndex, Fts5SearchIndex
  Storage/        FileStorage, PrivateStorage, ImagePipeline (EXIF-Strip, WebP, Thumbs)
  Mail/           Mailer, Translator-gestützte Templates
src/Http/         Kernel, Router, Route, Middleware/*, Controller/*, Message/*
src/Support/      Env, Clock, Translator, Slugger, Json
```

Die Domain-Schicht ist framework- und PDO-frei; `src/Infra` kennt die Domain, nicht umgekehrt.

## Migrationsstrategie

- Eine Migration = eine Datei unter `migrations/`, Namensschema `NNNN_snake_case.php`, Rückgabe einer
  anonymen Klasse mit `up(PDO)` / `down(PDO)`. Keine Auto-Synchronisierung, kein Schema-Diff.
- `bin/migrate.php up [--step=N] [--pretend]`, `down [--step=N]`, `status`, `fresh` (nur wenn
  `APP_ENV != production`).
- Tabelle `migrations(version, name, batch, applied_at, checksum)`. Der Checksum erkennt nachträglich
  veränderte, bereits angewandte Migrationen und bricht mit Fehler ab.
- SQLite kann keine Spalten mit `CHECK`/FK nachträglich ändern; solche Änderungen laufen künftig über das
  dokumentierte 12-Schritte-Verfahren (neue Tabelle, kopieren, umbenennen) — deshalb liegt jede
  Tabellendefinition vollständig in genau einer Migration und wird nicht über viele `ALTER`-Dateien
  verstreut.
- Jede Migration läuft in einer Transaktion; `PRAGMA foreign_keys` wird für die Dauer der Migration
  deaktiviert und danach mit `PRAGMA foreign_key_check` verifiziert.
- Domänen-Constraints stehen als `CHECK`-Constraints in der DB (Enum-Werte, Wertebereiche) — die DB ist
  die letzte Verteidigungslinie, die Domain-Enums die erste.

## Datenherkunft PLZ

PLZ-Koordinaten sind lokal eingebettet (`data/postal_codes.tsv.gz`), kein Laufzeit-API-Call.
Herkunft und Lizenzen: `data/README.md`. Ein Teil der DE/AT-Zentroide ist aus benachbarten PLZ
interpoliert (Spalte `source`) und lässt sich per `bin/import_postal_codes.php --geonames` durch die
exakten GeoNames-Zentroide ersetzen.

## Optionale CMS-Kopplung

`IdentityProvider` (Interface) kapselt die Nutzeridentität. Standardimplementierung `LocalIdentityProvider`
arbeitet gegen die eigene `users`-Tabelle; eine spätere `DragonReptilesIdentityProvider` (OAuth2 oder
geteilte Tabelle) wird per `.env` aktiviert. Die Plattform läuft ohne diese Kopplung vollständig.
