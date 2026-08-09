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

### E12 — Volltext: eigener FTS5-Index, fortgeschrieben bei jedem Schreibvorgang

`content_search` neben `listing_search`, mit derselben Tokenizer-Einstellung
(`unicode61 remove_diacritics 2`) — sonst verhielten sich zwei Suchfelder derselben Seite
verschieden. Wie beim Anzeigenindex bewusst **keine** external-content-Tabelle: Der Rumpftext
entsteht aus den Blöcken und wird dabei aus Markdown zu Reintext gemacht, was eine
content-Tabelle nicht abbilden kann.

Fortgeschrieben wird am Ende jeder schreibenden Aktion, nicht in einem nächtlichen Lauf: Ein
Beitrag, der erst am nächsten Morgen auffindbar ist, ist am Tag seiner Veröffentlichung
unauffindbar — also genau dann, wenn ihn jemand sucht. Indiziert wird nur Veröffentlichtes; ein
Entwurf, den die Suche findet, ist kein Entwurf mehr.

Die Umwandlung Markdown → Reintext liegt in `ContentText`, einer eigenen Klasse, weil zwei
Stellen sie brauchen (Renderer und Index). Zwei Umsetzungen wären zwei Wahrheiten über
denselben Text, und die Suche fände Wörter, die auf der Seite nicht stehen.

Nutzereingaben laufen durch `Fts5Query` — dieselbe Aufbereitung wie beim Anzeigenindex. Ein
Sternchen oder ein Anführungszeichen würde die Abfrage sonst umdeuten oder mit einem
Syntaxfehler abbrechen.

`bin/reindex.php` bekommt `--modul=anzeigen|inhalte|alle` (Vorgabe `alle`).

### E13 — Kategorien entstehen beim Zuordnen

`content_terms` trägt Kategorien und Schlagwörter in einer Tabelle mit `taxonomy`-Spalte, aus
demselben Grund wie `content_entries`. Der Unterschied ist, was daran hängt: Eine Kategorie
bekommt ein Archiv unter `/news/kategorie/{slug}/`, ein Schlagwort nicht — sonst entstünden für
jedes einmal vergebene Wort dünne Archivseiten.

Begriffe entstehen beim Speichern eines Beitrags (`ensure`), nicht in einer eigenen Verwaltung:
Eine leere Kategorienliste, die erst gepflegt werden muss, bevor der erste Beitrag eine
bekommt, hält niemanden auf — nur auf. Gezählt und angezeigt werden nur Kategorien mit
veröffentlichten Beiträgen; eine leere wäre ein Verweis ins Nichts.

Die Einzelseite eines Beitrags läuft über die Auffangroute und den `ContentController`: Sie ist
eine Inhaltsseite wie jede andere, nur mit einem Pfad, der das Jahr trägt. Eine eigene Ausgabe
wäre eine zweite Stelle, an der Blöcke gerendert werden.

### E14 — Weiterleitungen: Ketten beim Anlegen auflösen, Schleifen abweisen

Zeigt `/a/` auf `/b/` und kommt `/b/ → /c/` dazu, wird `/a/` gleich mit auf `/c/` gezogen. Sonst
schickt jede Umbenennung den Besucher einen Sprung weiter durch die Geschichte der Seite — und
Suchmaschinen geben nach wenigen Sprüngen auf.

Schleifen werden **abgewiesen, nicht abgeschnitten**: Eine Weiterleitung, die im Kreis führt,
ist ein Fehler in der Absicht des Redakteurs; ihn stillschweigend zu begradigen hieße zu raten,
was gemeint war.

Ein Sonderfall fiel erst beim Rauchtest auf: Wer einen Slug ändert und dann zurückbenennt,
erzeugt formal einen Kreis (`/eins/ → /zwei/` und `/zwei/ → /eins/`) — und bekäme deshalb gar
keine Weiterleitung. Deshalb räumt eine **automatische** Weiterleitung (also eine aus einer
Umbenennung) eine bestehende Weiterleitung *vom Ziel weg* ab: Dort steht jetzt nachweislich eine
Seite, die alte Angabe ist überholt. Für von Hand angelegte Weiterleitungen gilt das nicht — dort
weiß niemand, ob das Ziel eine Seite ist, und eine stillschweigend gelöschte Weiterleitung des
Betreibers wäre schlimmer als eine Fehlermeldung.

Auto-301 entsteht nur für **veröffentlichte** Einträge. Ein Entwurf hatte nie eine Adresse, die
jemand kennt; eine Weiterleitung darauf wäre ein Eintrag ohne Anlass, der später im Weg steht.
Scheitert das Anlegen (Schleife, unbrauchbares Ziel), scheitert nicht die Umbenennung: Die Seite
liegt bereits richtig, es fehlt nur die Weiterleitung.

### E15 — Menüs: Ziel als Typ + Wert, Pfad wird nachgeschlagen

`target_type` (`entry`/`route`/`url`) plus `target_value` statt dreier Spalten, von denen je zwei
leer wären. Bei `entry` steht dort die ID; der Pfad wird beim Rendern nachgeschlagen und wandert
damit von selbst mit, wenn der Slug sich ändert.

Ein Fremdschlüssel auf `content_entries` entfällt bewusst: Er wäre nur in einem der drei Fälle
sinnvoll, und SQLite kennt keine bedingten Fremdschlüssel. Ein Eintrag, dessen Inhalt es nicht
mehr gibt, verschwindet still aus dem Menü — ein Verweis ins Leere wäre für den Besucher
schlechter — und `bin/doctor.php` meldet ihn.

`visibility` ist Darstellung, nicht Zugriffsschutz: Wer den Pfad kennt, ruft ihn auch ohne
Menüeintrag auf. Der Schutz sitzt in den Controllern; hier geht es darum, dass „Registrieren" für
Angemeldete verschwindet.

Impressum, Datenschutz und Nutzungsbedingungen stehen **fest verdrahtet** im Fußbereich. Sie
müssen mit höchstens zwei Klicks erreichbar sein, und das darf nicht davon abhängen, dass jemand
ein Menü pflegt.

### E16 — SEO: ein Kontextobjekt, JSON-LD von `json_encode`

`SeoContext` stellt zusammen, was in den Kopf gehört — Titel, Beschreibung, Canonical (absolut,
nicht relativ), OpenGraph, Twitter-Card, JSON-LD, ETag. Das Template gibt nur aus.

Das JSON-LD entsteht in PHP und nicht im Template: Es ist JSON in einem `script`-Tag, und JSON
gehört von `json_encode` gebaut. `JSON_HEX_TAG` schließt `</script>` im Titel aus — der einzige
Weg, aus einem `ld+json`-Block auszubrechen, und einer, den `script-src 'self'` nicht auffängt,
weil ein eigener Block ja erlaubt ist.

Öffentliche Inhaltsseiten tragen ein ETag aus Pfad, Änderungszeitpunkt und Status und beantworten
`If-None-Match` mit 304. `Cache-Control` bleibt `private`: Die Kopfzeile zeigt den angemeldeten
Namen, ein vorgelagerter Zwischenspeicher dürfte die Seite nicht weiterreichen.

`sitemap.xml` wird erzeugt, nicht gespeichert — eine Datei auf der Platte müsste nach jeder
Änderung neu geschrieben werden, und der Moment, in dem das ausfällt, fällt niemandem auf. Ab
5 000 Adressen ein Sitemap-Index. Was `noindex` trägt, steht nicht darin: Die Sitemap ist eine
Einladung, und beides zugleich zu sagen ist ein Widerspruch.

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

## Abnahme: was tatsächlich geprüft wird

`tools/smoke_cms.php` baut seine Datenbank selbst auf und läuft durch den echten Kernel — mit
Cookie, CSRF-Token und Weiterleitungen. In dieser Reihenfolge:

1. Ohne Berechtigung ist die Redaktion nicht vorhanden (404, nicht 403).
2. Seite anlegen, Blöcke füllen, veröffentlichen, öffentlich abrufen (Markdown, Canonical, OG).
3. `/impressum` bleibt bei `config/impressum.php` — die Meldung nennt den Konflikt beim Namen.
4. Ein geplanter Beitrag erscheint erst nach dem Lauf von `content.publish`, nicht vorher.
5. Ein Vorschaulink zeigt den Entwurf ohne Anmeldung, mit `noindex`.
6. Medien: drei Größen, Metadaten nachweislich weg, `srcset` im Markup, Löschsperre bei Verwendung.
7. Beiträge, Kategoriearchiv, Volltextsuche, Feed gegen einen XML-Parser.
8. Eine Fassung zurücksetzen.
9. Slug ändern → 301; zweimal ändern → keine Kette.
10. Sitemap (XML, ETag), `robots.txt`, Menüeintrag in der Kopfzeile.
11. Zurücknehmen (Entwurf), Archivieren (410), Löschen — und die Spuren im Audit-Trail.

`bin/doctor.php` prüft auf einer laufenden Installation: Schreibrechte auf `public/media/`, dort
gesperrte PHP-Ausführung, vorhandene Menüs, Menüeinträge ins Leere, Weiterleitungsschleifen,
Slug-Kollisionen mit registrierten Routen, erzeugbare Sitemap.

## Stand

| Paket | Inhalt | Stand |
|---|---|---|
| 10.1 | Plan, `content_entries` + `content_blocks`, Domain, PDO-Umsetzung | erledigt |
| 10.2 | Markdown-/Blockrenderer, Templates, Reservierungsliste, Catch-all | erledigt |
| 10.3 | Verwaltungsoberfläche, Redaktionsberechtigung, Audit | erledigt |
| 10.4 | Revisionen, Vorschau-Token, Planung, Auftrag `content.publish` | erledigt |
| 10.5 | Medienverwaltung, `media_usages`, `srcset` | erledigt |
| 10.6 | Beiträge, Kategorien, `/news/`, `/feed.xml`, FTS5 | erledigt |
| 10.7 | Menüs, Weiterleitungen, SEO, `sitemap.xml`, `robots.txt` | erledigt |
