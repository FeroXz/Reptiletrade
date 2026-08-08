# Redaktionssystem (Phase 10)

Eigener Inhaltsbereich der Anwendung: Seiten, Beiträge, Medien, Menüs, Weiterleitungen,
SEO-Auslieferung. Kein Fremd-CMS, keine neue Laufzeitabhängigkeit, keine Aufweichung der
Content-Security-Policy.

Dieses Dokument ist der Plan **und** das Entscheidungsprotokoll. Jede Abweichung von der
Vorgabe steht hier mit Begründung.

## Einordnung in den Bestand

| Bestandteil | Wiederverwendet | Neu |
|---|---|---|
| Router (`src/Http/Routing`) | Muster, Middleware-Kette | Platzhalter `{name*}` für die Catch-all-Route |
| Domain/Infra-Trennung | Interfaces in `src/Domain/Content/`, PDO in `src/Infra/Persistence/` | — |
| Bildverarbeitung | `ImagePipeline` (neu zeichnen → WebP) | Größenstaffel 400/800/1600, Ablage `public/media/JJJJ/MM/` |
| Audit-Trail | `AuditLog`, `AuditEntry` | Aktionen `content.*`, `media.deleted`, `redirect.created` |
| Aufträge | `JobScheduler`, `JobRunner` | Auftrag `content.publish` |
| Volltext | `SearchIndex`-Muster aus Phase 3 | FTS5-Tabelle `content_search`, `bin/reindex.php --modul=inhalte` |
| Übersetzer | `Translator`, `lang/de-DE.php` | Schlüssel unter `inhalt.*` und `admin.inhalt.*` |
| Rechtsseiten | `config/impressum.php` | **unverändert** — siehe Abgrenzung |

## Abgrenzung

- `/impressum`, `/datenschutz`, `/nutzungsbedingungen` bleiben in `config/impressum.php` und
  `src/Legal/`. Sie stehen auf der Reservierungsliste; das CMS kann diese Pfade nicht belegen.
  Ein Impressum, das jemand versehentlich in den Entwurf zieht, ist ein Rechtsverstoß — das
  gehört in eine Datei, die beim Deployment mitgeht.
- Kein Theme- oder Template-Editor im Browser. Vorlagen sind Code, die Auswahl ist eine
  Allowlist (`ContentTemplate`).
- Kein Roh-HTML-Block in v1. Begründung unter „Editor".
- Keine Mehrsprachigkeit der Inhalte. Die Spalte `locale` steht trotzdem von Anfang an in jeder
  Inhaltstabelle mit dem Wert `de-DE`, damit eine spätere Erweiterung keinen Tabellenumbau
  braucht — SQLite kann `CHECK` und Fremdschlüssel nicht nachträglich ändern.
- Keine Kommentarfunktion. Für Rückfragen gibt es `/kontakt`.

## Entscheidungen

### E1 — Rolle `redakteur` als eigene Tabelle statt als Wert in `users.role`

`migrations/0001_create_users.php` legt `role` mit
`CHECK (role IN ('buyer','seller','breeder','moderator','admin'))` an. SQLite kann einen
`CHECK`-Constraint nur über den Neubau der Tabelle ändern — das widerspricht der Vorgabe
„Migrationen sind additiv, keine bestehende Tabelle umbauen".

**Entscheidung (nach Rückfrage):** Die Berechtigung liegt in der additiven Tabelle
`content_editors(user_id, granted_at, granted_by)`. `users.role` bleibt unangetastet, das
`Role`-Enum bleibt wie es ist. Ein `ContentPermission`-Dienst beantwortet die einzige Frage,
die die Controller stellen: „Darf dieser Nutzer die Redaktion bedienen?" — wahr für `admin`
und für jeden Eintrag in `content_editors`.

Preis der Entscheidung: Die Berechtigung steht nicht in `users.role`, die Nutzerliste muss
zwei Quellen zusammenführen. Gewinn: ein `DROP TABLE` als vollständiger `down`-Pfad, kein
Datenverlust beim Zurückrollen, keine Berührung der Kontentabelle.

### E2 — Seiten und Beiträge in einer Tabelle

`content_entries` trägt beide Typen mit einer `type`-Spalte. Die Felder überlappen zu über
90 %; zwei Tabellen hießen jede Abfrage, jeden Index, jede Revision, jede Verwendungsprüfung
und jeden Menüeintrag doppelt zu bauen. Was sich unterscheidet, sichert die Datenbank ab:
`CHECK (parent_id IS NULL OR type = 'seite')` — nur Seiten haben Eltern.

### E3 — `path` als eigene Spalte

Der vollständig aufgelöste Pfad steht in `content_entries.path` mit `UNIQUE`. Die Alternative
wäre ein rekursives CTE bei jedem Seitenaufruf. Die Kollisionsprüfung und die Catch-all-Route
brauchen einen Index, keine Rekursion. Preis: Wird eine Elternseite umbenannt, müssen die
Pfade der Kinder mitgeschrieben werden — das passiert in einer Transaktion und legt für jeden
betroffenen veröffentlichten Pfad eine 301 an.

`UNIQUE (type, parent_id, slug)` steht zusätzlich im Schema, greift aber bei
`parent_id IS NULL` nicht: SQLite behandelt NULL-Werte in eindeutigen Indizes als
verschieden. Zwei Wurzelseiten mit demselben Slug fängt deshalb das `UNIQUE` auf `path` ab —
darauf ist Verlass, weil der Pfad einer Wurzelseite genau `/slug/` ist.

### E4 — Rumpf als geordnete Blockliste, nicht als HTML-Feld

`content_blocks` statt einer `body_html`-Spalte. Ein HTML-Feld zwingt entweder zu einem
Sanitizer, dem man auf ewig hinterherpflegt, oder zu `unsafe-inline`. Beides ist teurer als
ein festes Blockvokabular. `data_json` trägt `CHECK (json_valid(data_json))`.

Die Position wird bei jedem Schreibvorgang lückenlos von 0 an neu vergeben
(`ContentBlockRepository::replaceAll`), statt sie über einen `UNIQUE`-Index abzusichern. Das
ist die stärkere Zusicherung — sie schließt auch Lücken — und macht das Verschieben eines
Blocks zu einem einzigen Schreibvorgang statt zu einem Tanz um Zwischenwerte.

### E5 — `og_image_id` kommt erst mit der Medienmigration

`content_entries` bekommt die Spalte nicht bei der Anlage, sondern in der Migration, die auch
`media` anlegt (Paket 10.5), per
`ALTER TABLE content_entries ADD COLUMN og_image_id INTEGER REFERENCES media(id)`.

Grund: Bei eingeschaltetem `PRAGMA foreign_keys` beantwortet SQLite **jeden** Schreibvorgang
auf eine Tabelle mit „no such table", solange die Elterntabelle eines Fremdschlüssels fehlt —
auch dann, wenn der Wert NULL ist. Eine Vorwegnahme des Fremdschlüssels hätte die Tabelle
zwischen Paket 10.1 und 10.5 unbeschreibbar gemacht. `ADD COLUMN` mit `REFERENCES` ist
erlaubt, solange der Vorgabewert NULL ist; der `down`-Pfad nutzt `DROP COLUMN`
(SQLite ≥ 3.35, im Projekt ohnehin Voraussetzung für `RETURNING`-freie Migrationen).

### E6 — Catch-all über einen Platzhalter im Router

`Route` kannte nur `{name}` und passte damit auf ein Segment ohne Schrägstrich. Die
Seitenroute `/{pfad*}` braucht mehrere Segmente. Statt einer Sonderbehandlung im Kernel
bekommt `Route` den Platzhalter `{name*}` („gieriges" Segment, `.+`). Das ändert das
Verhalten bestehender Routen nicht und hält die Route dort, wo alle anderen auch stehen: in
`config/routes.php`, als letzte.

Dazu gehört ein zweiter Handgriff, der beim Bauen aufgefallen ist: Die Auffangroute wird über
`Router::fallback()` registriert, nicht über `get()`, und `Router::pathExists()` überspringt
Auffangrouten. Ohne das beantwortet ein POST an eine beliebige Adresse ein 405 („Methode
nicht erlaubt") statt eines ehrlichen 404 — denn seit der Auffangroute „existiert" jeder
Pfad. Der Unterschied ist keine Kosmetik: 405 sagt einem Client, er solle es mit einer
anderen Methode versuchen.

### E7 — Reservierungsliste **und** Abgleichstest

`ReservedPaths` führt die belegten ersten Pfadsegmente. Beim Speichern eines Eintrags wird
geprüft, die Fehlermeldung nennt den Konflikt beim Namen. Damit die Liste nicht ausläuft,
sobald jemand eine Route ergänzt, vergleicht `tests/Http/ReservedPathsTest` sie gegen die
tatsächlich in `config/routes.php` registrierten Muster: Jedes erste Segment einer
registrierten Route muss in der Liste stehen.

### E8 — Markdown-Teilmenge statt HTML

Der `text`-Block speichert Markdown. Der Renderer (`MarkdownRenderer`) beherrscht bewusst
wenig: `h2`–`h4`, Absatz, Liste (geordnet/ungeordnet), Link, fett, kursiv, Zitat,
Inline-Code. Alles andere wird **escapet ausgegeben, nicht verworfen** — verschwindender Text
ist schlimmer als sichtbar falscher. Externe Links bekommen `rel="noopener noreferrer"`,
`javascript:`- und `data:`-Ziele werden zu Text. Die Angriffsfälle stehen in
`tests/Domain/Content/MarkdownRendererTest`.

### E9 — Veröffentlichen über einen Auftrag, nicht über einen Request-Hook

Status `geplant` mit `published_at` in der Zukunft wird vom Auftrag `content.publish`
freigeschaltet, viertelstündlich über den `JobScheduler`. Ein Request-Hook würde bedeuten:
Eine Seite erscheint, wenn zufällig jemand vorbeikommt — auf einer leisen Website also
womöglich gar nicht.

`JobScheduler::SCHEDULE` kannte nur „bei jedem Lauf" (`null`) und „zu einer festen Stunde".
Das ging, solange die Crontab genau stündlich lief — dann waren „jeder Lauf" und „stündlich"
dasselbe. Für `content.publish` muss die Crontab öfter aufrufen (`*/15`), und ohne echten
Abstand würden die stündlichen Aufgaben dann viermal pro Stunde eingeplant.

Deshalb trägt der Zeitplan jetzt entweder ein `JobInterval` (15 oder 60 Minuten) oder eine
Stunde, und `JobRepository::lastEnqueuedAt()` beantwortet, ob der Abstand verstrichen ist —
`hasPending()` allein weiß nichts mehr von einem Auftrag, den der Worker bereits abgearbeitet
hat. Eine Minute Nachsicht, weil eine Crontab nie auf die Sekunde läuft: Ohne sie fiele bei
einem Aufruf um 13:00:59 der nächste um 14:00:03 durch, und die Aufgabe liefe faktisch nur
alle zwei Stunden. `docs/INSTALLATION.md` ist entsprechend angepasst.

### E9a — Revisionen als Abbild, nicht als zweites Schema

`content_revisions.snapshot_json` trägt Kopf **und** Blöcke in einem JSON-Abbild. Eine Revision
ist kein Arbeitsobjekt: Sie wird geschrieben, gelesen und im Ganzen zurückgespielt, nie einzeln
abgefragt. Ein typisiertes Nebenschema wäre normalisierter, aber ein Abbild, das sich nur mit
der heutigen Klasse entpacken lässt, wäre genau dann wertlos, wenn man es braucht.

Zurücksetzen ändert Titel, Anriss, Meta-Felder und Blöcke — **nicht** Pfad und Status. Eine alte
Fassung zurückzuspielen ist eine Aussage über den Inhalt, nicht darüber, wo er liegt oder ob er
online ist; wer beides zugleich änderte, könnte mit einem Klick eine veröffentlichte Seite unter
eine alte Adresse schieben. Der Stand vor dem Zurücksetzen wird vorher als Fassung gesichert,
sonst wäre das Zurücksetzen der einzige Schritt ohne Rückweg.

Aufbewahrt werden die letzten 30 Fassungen je Eintrag (`config/aufbewahrung.php`,
`inhalt_fassungen_je_eintrag`). Anders als alles andere dort eine **Anzahl**, keine Frist — und
deshalb eine eigene Methode auf `RetentionPolicy`: Wer „30" als Tage läse, würfe die
Vorgeschichte eines Textes weg, an dem einen Monat lang niemand gearbeitet hat. Eine 0 wird laut
abgewiesen; sie wäre kein Abschalten, sondern der Verlust jeder Rückkehrmöglichkeit.

### E9b — Vorschaulinks: nur der Hash liegt in der Datenbank

Der Link geht an jemanden, der sich nicht anmelden kann. 32 Zufallsbytes, 24 Stunden gültig,
gespeichert wird nur `sha256` — dieselbe Regel wie bei den Einmal-Token in `user_tokens`. Die
Antwort trägt `X-Robots-Tag: noindex, nofollow` und `no-store`: Ein Vorschaulink, der in einem
Suchindex landet, verrät den Entwurf allen. Unbekanntes und abgelaufenes Token ergeben dieselbe
Antwort — wer probiert, soll nicht erfahren, ob er einen echten Link erwischt hat, der nur zu
spät kam. Abgelaufene Links räumt derselbe Auftrag ab, der veröffentlicht.

### E11 — Mediathek getrennt von `listing_media`

Zwei Tabellen, weil es zwei verschiedene Dinge sind: Ein Anzeigenbild gehört einer Anzeige,
verschwindet mit ihr und wird nie wiederverwendet. Ein Redaktionsbild steht auf mehreren
Seiten, überlebt jede einzelne davon und trägt eine Beschreibung, die zum Zusammenhang passt.
Eine gemeinsame Tabelle hätte für beide Fälle die falschen Löschregeln.

Dedupliziert wird über den `sha256` des **verarbeiteten** WebP, nicht der hochgeladenen Datei:
Zwei JPEGs desselben Motivs mit unterschiedlicher Kompression ergeben dasselbe WebP, und genau
das soll einmal in der Mediathek stehen.

Der `alt_text` steht am Medium nur als **Vorschlag**; verbindlich ist der am Bildblock.
Dasselbe Foto heißt auf der Artenseite „Ausgewachsenes Weibchen der Bartagame" und im Beitrag
über Beleuchtung „Terrarium mit UV-Lampe über dem Sonnenplatz" — ein Alternativtext am Medium
wäre für einen der beiden Fälle falsch.

`MediaService` liegt in `src/Infra/Storage/`, nicht in `src/Domain/Content/`: Er hängt an GD
und am Dateisystem. Er kennt die Domain (Entitäten, Repository-Interfaces), nicht umgekehrt —
dieselbe Richtung wie bei den Anzeigenbildern.

`ImagePipeline` bekommt dafür `processVariants()` — dieselbe Verarbeitung wie `process()`, nur
in mehrere Kantenlängen (400/800/1600). Die Größen werden absteigend aus der jeweils
vorherigen, größeren Leinwand gezogen; das ist schneller als jedes Mal aus dem Original und bei
diesen Faktoren nicht sichtbar schlechter. `srcset` bietet keine Größe an, die über der
Originalbreite liegt — ein hochskaliertes Bild kostet Bandbreite ohne Gewinn.

### E10 — Zurücknehmen legt keine Weiterleitung an

`veroeffentlicht` → `entwurf` entfernt die Seite aus dem öffentlichen Bestand, ohne eine 301
zu hinterlassen: Die Seite soll zurückkommen, und eine Weiterleitung, die den alten Pfad auf
irgendetwas anderes zeigen lässt, stünde dem im Weg. `archiviert` liefert dagegen **410**,
wenn keine Weiterleitung existiert — ein bewusst entfernter Inhalt ist etwas anderes als ein
Tippfehler in der URL.

## Datenmodell

| Migration | Paket | Tabellen |
|---|---|---|
| `0023_create_content_entries` | 10.1 | `content_entries`, `content_blocks` |
| `0024_create_content_editors` | 10.3 | `content_editors` |
| `0025_create_content_revisions` | 10.4 | `content_revisions`, `content_preview_tokens` |
| `0026_create_media` | 10.5 | `media`, `media_usages`, `content_entries.og_image_id` |
| `0027_create_content_terms` | 10.6 | `content_terms`, `content_entry_terms`, `content_search` (FTS5) |
| `0028_create_menus_and_redirects` | 10.7 | `menus`, `menu_items`, `content_redirects` |

Jede Migration hat einen `down`-Pfad, der genau das zurücknimmt, was sie angelegt hat.

## Routen

### Öffentlich

| Pfad | Zweck |
|---|---|
| `/news/` | Beitragsübersicht, paginiert |
| `/news/kategorie/{slug}/` | Kategoriearchiv |
| `/news/{jahr}/{slug}/` | Einzelbeitrag |
| `/feed.xml` | RSS 2.0, letzte 20 Beiträge |
| `/sitemap.xml` | Seiten, Beiträge, Artenprofile, Marktlandingpages |
| `/robots.txt` | erzeugt, mit Verweis auf die Sitemap |
| `/vorschau/{token}` | signierte Vorschau eines Entwurfs, 24 h gültig |
| `/{pfad*}` | Catch-all für Seiten — **letzte Route**, nach allen bestehenden |

### Verwaltung

`/admin/inhalte`, `/admin/inhalte/neu`, `/admin/inhalte/{id}/bearbeiten`,
`/admin/inhalte/{id}/versionen`, `/admin/inhalte/{id}/versionen/{nr}/zuruecksetzen`,
`/admin/medien`, `/admin/menues`, `/admin/weiterleitungen`.

Fehlende Berechtigung ergibt **404, nicht 403** — dieselbe Linie wie `/admin/`: Wer nicht
hingehört, soll nicht erfahren, dass es die Seite gibt.

## Editor

Serverseitig gerendertes Blockformular. Blöcke werden über echte Formular-Buttons
hinzugefügt, verschoben und gelöscht; jede Aktion ist ein POST mit CSRF-Token. Ohne
JavaScript ist der Editor vollständig bedienbar — `public/assets/inhalt.js` verbessert ihn
nachträglich (Sortieren per Tastatur und Zeiger, Autosave), ohne `eval`, ohne Inline-Handler,
ohne aus Zeichenketten gebautes Markup. Dieselbe Linie wie `markt.js` und `anzeige.js`.

Blocktypen v1: `text`, `bild`, `galerie`, `zitat`, `trenner`, `hinweis`, `cta`,
`anzeigen-teaser`, `arten-teaser`.

Kopf und Blöcke stehen in **einem** Formular. Jeder Knopf trägt seine Aktion im `name`/`value`-Paar
(`aktion=block-hoch:2`), sodass Hinzufügen, Verschieben und Löschen gewöhnliche Absendungen sind —
und dabei kein ungespeicherter Text verlorengeht. Übernommen wird pro Blocktyp nur, was er kennt
(`blockData`); alles andere aus dem Formular fällt weg, statt in `data_json` und von dort in die
Ausgabe zu wandern.

Ein Roh-HTML-Block würde entweder `unsafe-inline` verlangen oder einen Sanitizer, der jedem
neuen Browser-Trick hinterherläuft. Wenn er später kommt, dann als eigener Blocktyp, nur für
`admin`, mit Vermerk im Audit-Trail.

## Medien

Dieselbe Pipeline wie bei den Anzeigenbildern: dekodieren, EXIF-Ausrichtung einrechnen, auf
frische Leinwand kopieren, als WebP schreiben. Damit überlebt kein Metadatenblock — es gibt
schlicht nichts zu übertragen. Größen 400/800/1600 px als `srcset`, Originalseitenverhältnis,
`width`/`height` im Markup gegen Layout-Sprünge.

Ablage unter `public/media/JJJJ/MM/`, PHP-Ausführung dort per Webserver-Regel unterbunden
(Beispiele in `docs/INSTALLATION.md`). Deduplizierung über `sha256` (UNIQUE).

`alt_text` ist Pflicht beim **Einbinden** in einen Bildblock, nicht beim Hochladen: Beim
Hochladen weiß noch niemand, wofür das Bild steht.

Löschen zeigt vorher die Verwendungen aus `media_usages` und verlangt Bestätigung; verwaiste
Dateien räumt der bestehende Auftrag `media.cleanup` ab.

## Audit-Trail

Jede schreibende Aktion der Redaktion wird protokolliert: `content.created`,
`content.published`, `content.unpublished`, `content.deleted`, `content.restored`,
`media.deleted`, `redirect.created`.

## Abnahme

`tools/smoke_cms.php [--behalten]` in der Machart der bestehenden Rauchtests: Seite anlegen,
Blöcke füllen, Bild einbinden, veröffentlichen, öffentlich abrufen, Slug ändern,
Weiterleitung prüfen, Beitrag planen, Auftrag laufen lassen, Sitemap und Feed abrufen,
Revision zurückrollen, aufräumen.

`bin/doctor.php` prüft zusätzlich: Schreibrechte auf `public/media/`, dort geblockte
PHP-Ausführung, vorhandene Menüs, erzeugbare Sitemap, keine Weiterleitungsschleifen, keine
Slug-Kollision mit einer registrierten Route.

## Stand

| Paket | Inhalt | Stand |
|---|---|---|
| 10.1 | Plan, `content_entries` + `content_blocks`, Domain, PDO-Umsetzung | erledigt |
| 10.2 | Markdown-/Blockrenderer, Templates, Reservierungsliste, Catch-all | erledigt |
| 10.3 | Verwaltungsoberfläche, Redaktionsberechtigung, Audit | erledigt |
| 10.4 | Revisionen, Vorschau-Token, Planung, Auftrag `content.publish` | erledigt |
| 10.5 | Medienverwaltung, `media_usages`, `srcset` | erledigt |
| 10.6 | Beiträge, Kategorien, `/news/`, `/feed.xml`, FTS5 | offen |
| 10.7 | Menüs, Weiterleitungen, SEO, `sitemap.xml`, `robots.txt` | offen |
