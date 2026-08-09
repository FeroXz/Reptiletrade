# Offene Übersetzungen

**Bestandsaufnahme, keine Anleitung.** Dieses Dokument zählt, was noch fehlt — die Umstellung
selbst ist rein mechanische Arbeit und gehört in ein eigenes Arbeitspaket, nicht nebenbei in eines,
das andere Dinge ändert.

## Worum es geht

Alle Oberflächentexte laufen über `Reptilienmarkt\Support\Translator`, die Schlüssel stehen in
`lang/de-DE.php`, und `/admin/texte` macht sie im Browser änderbar. Das gilt für alles, was **ab
Phase 5** entstanden ist — der Übersetzer wurde mit Phase 5 eingeführt
(Commit `e9e3dd1`, „Phase 5: Nutzer, Vertrauen, Kommunikation").

Die Templates, die es davor schon gab, tragen ihre Texte noch direkt im Markup. Sie erscheinen
deshalb nicht unter `/admin/texte` und lassen sich nur über ein Deployment ändern.

## Erhebung

Gezählt wurden Textknoten zwischen Tags und die sichtbaren Attribute `placeholder`, `title`, `alt`
und `aria-label` — jeweils nachdem Twig-Ausdrücke, Twig-Kommentare sowie `<script>`- und
`<style>`-Blöcke entfernt wurden. Gewertet wird nur, was mindestens ein Wort aus drei
Kleinbuchstaben enthält; reine Zeichen, Zahlen und Abkürzungen fallen heraus.

Die Zahlen sind eine Obergrenze mit etwas Rauschen: Ein Textknoten wie `Genotyp:` gehört
zweifelsfrei dazu, `– von` ist ein Rest um einen entfernten Twig-Ausdruck herum. Für die
Größenordnung — und darum geht es hier — reicht das.

Welche Datei zu den Phasen 1 bis 4 gehört, entscheidet nicht das Gefühl, sondern die Historie:
`git ls-tree -r --name-only e9e3dd1^ -- templates/` listet genau die Templates, die es vor der
Einführung des Übersetzers gab.

## Phasen 1 bis 4 — 11 Dateien, 135 Fundstellen

| Datei | Fundstellen | Beispiele |
|---|---:|---|
| `templates/anzeige/assistent.html.twig` | 49 | „Hinweise zur Genetik"; „Schritt …" |
| `templates/markt/suche.html.twig` | 20 | „Filter"; „Alle Filter zurücksetzen" |
| `templates/layout/basis.html.twig` | 15 | „Suchbegriff"; „Meine Anzeigen" |
| `templates/art/profil.html.twig` | 12 | „Rechtlicher Rahmen"; „Anhang der EG-VO 338/97" |
| `templates/anzeige/detail.html.twig` | 11 | „Diese Anzeige ist noch nicht öffentlich sichtbar"; „Genotyp:" |
| `templates/auth/registrieren.html.twig` | 8 | „Konto anlegen"; „Anzeigename" |
| `templates/anzeige/start.html.twig` | 6 | „Neue Anzeige"; „Zwei Angaben genügen zum Start" |
| `templates/auth/anmelden.html.twig` | 6 | „Anmelden"; „E-Mail" |
| `templates/partials/paginierung.html.twig` | 5 | „Zurück"; „Weiter" |
| `templates/fehler/404.html.twig` | 2 | „Nicht gefunden"; „Zur Marktübersicht" |
| `templates/partials/kachel.html.twig` | 1 | „Kein Bild" |

`templates/anzeige/meine.html.twig` und `templates/partials/meldungen.html.twig` gehören ebenfalls
zu den frühen Templates, enthalten aber keinen Rohtext mehr.

## Ab Phase 5 — 23 Dateien, 196 Fundstellen

Auch nach der Einführung des Übersetzers ist nicht jeder Text durch ihn gelaufen. Die größten
Posten:

| Datei | Fundstellen |
|---|---:|
| `templates/moderation/liste.html.twig` | 26 |
| `templates/recht/impressum.html.twig` | 22 |
| `templates/konto/uebersicht.html.twig` | 20 |
| `templates/admin/nutzer.html.twig` | 19 |
| `templates/genetik/bericht.html.twig` | 17 |
| `templates/kontakt.html.twig` | 15 |
| `templates/profil/bearbeiten.html.twig` | 12 |

Zwei Einschränkungen zu dieser Liste:

- **Rechtstexte gehören nicht in den Katalog.** `templates/recht/*` gibt Fließtext aus, der seit
  Phase 9 aus `data/legal_texts.json` und `/admin/recht` kommt und dort gepflegt wird. Was der
  Zähler hier findet, sind Überschriften und Gerüsttexte um diesen Fließtext herum.
- **Verwaltungsoberflächen sind der geringste Nutzen.** `templates/admin/*` und
  `templates/moderation/*` sehen ausschließlich Verwaltung und Moderation. Sie über `/admin/texte`
  änderbar zu machen, hilft niemandem, der sie nicht ohnehin ändern könnte.

## Vorschlag für die Reihenfolge

Wenn das eigene Paket kommt, lohnt sich diese Reihenfolge — sie folgt der Sichtbarkeit, nicht der
Zahl:

1. `layout/basis.html.twig`, `partials/*`, `fehler/404.html.twig` — steht auf **jeder** Seite.
2. `markt/suche.html.twig`, `art/profil.html.twig`, `anzeige/detail.html.twig` — die Seiten, auf
   denen Suchmaschinen und Besucher landen.
3. `auth/*`, `anzeige/start.html.twig`, `anzeige/assistent.html.twig` — der Weg vom Besucher zum
   Anbieter.
4. Der Rest.

Der Assistent ist mit Abstand der größte Posten und zugleich der heikelste: Seine Texte erklären
Rechtspflichten. Wer sie in den Katalog holt, macht sie über `/admin/texte` änderbar — und damit
auch verschlechterbar. Das ist eine Entscheidung, keine Fleißarbeit, und sie gehört vor die
Umstellung dieses einen Templates.
