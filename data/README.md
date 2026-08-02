# Datensätze

Alle Datensätze liegen lokal im Repository. Zur Laufzeit erfolgt **kein** API-Aufruf.

## `postal_codes.tsv.gz` — Postleitzahlen DE/AT/CH

14 288 Postleitzahlen mit Zentroid. Spalten: `country`, `postal_code`, `place_name`, `admin1`, `lat`,
`lng`, `source`.

Einspielen: `php bin/import_postal_codes.php` (passiert auch beim `bin/seed.php`).

### Abdeckung und Genauigkeit

| Land | Einträge | `geonames` | `geonames_place` | `interpoliert` |
|---|---:|---:|---:|---:|
| DE | 8 254 | – | 7 155 | 1 099 |
| AT | 2 587 | – | 1 990 | 597 |
| CH | 3 447 | 3 447 | – | – |

Bedeutung der Spalte `source`:

- **`geonames`** — Zentroid der Postleitzahl selbst.
- **`geonames_place`** — Zentroid des zugehörigen Ortes. Für PLZ-Gebiete innerhalb einer Großstadt
  liegen mehrere Postleitzahlen damit auf demselben Punkt; der Fehler entspricht dem Stadtradius.
- **`interpoliert`** — aus den numerisch benachbarten Postleitzahlen desselben Präfixbereichs
  abgeleitet. Deutsche und österreichische Postleitzahlen sind geografisch geordnet, der Fehler
  bleibt dadurch klein, ist aber nicht garantiert.

**Für die Umkreissuche ab 25 km ist das ausreichend, für 10 km grenzwertig.** Wer exakte
PLZ-Zentroide braucht, ersetzt den Datensatz einmalig durch die Originaldateien von GeoNames:

```
# https://download.geonames.org/export/zip/ herunterladen und entpacken
php bin/import_postal_codes.php --geonames=/pfad/DE.txt
php bin/import_postal_codes.php --geonames=/pfad/AT.txt
php bin/import_postal_codes.php --geonames=/pfad/CH.txt
```

Der Import überschreibt vorhandene Einträge und setzt `source` auf `geonames`.

### Herkunft und Lizenzen

| Quelle | Verwendung | Lizenz |
|---|---|---|
| [GeoNames](https://www.geonames.org/) Ortsdaten (über das npm-Paket `cities.json`) | Koordinaten und Verwaltungseinheiten DE/AT | CC BY 4.0 |
| npm-Paket `german-zip-codes` | PLZ, Ort und Bundesland Deutschland | MIT |
| npm-Paket `plz-ort` | PLZ und Ort Österreich | MIT |
| npm-Paket `switzerland-postal-codes` (GeoNames-basiert) | PLZ, Ort, Kanton und Koordinaten Schweiz | MIT |

Attribution nach CC BY 4.0: „This product includes GeoNames data, licensed under CC BY 4.0."

Neu erzeugen lässt sich der Datensatz mit `tools/build_postal_codes.php`; die erwarteten
Quelldateien stehen im Kopfkommentar des Skripts.

## `species.json` — Artenstamm

58 im DACH-Raum gängige Terrarientiere mit Schutzstatus (CITES-Anhang, Anhang der EG-VO 338/97,
BNatSchG-Status, Melde- und Dokumentationspflicht, Gefahrtier-Kennzeichen).

> **Kein Rechtsdokument.** Der Datensatz ist ein gepflegter Ausgangspunkt, keine Rechtsberatung.
> CITES-Anhänge und die Anhänge der EG-VO 338/97 ändern sich nach jeder Vertragsstaatenkonferenz,
> Gefahrtierverordnungen sind Landes- bzw. Kantonsrecht und weichen voneinander ab. **Vor
> Produktivbetrieb ist der Datensatz fachlich zu prüfen und danach laufend zu pflegen; die Pflicht
> dazu liegt beim Betreiber.**

Die Felder `min_abgabe_alter_wochen` und `min_abgabe_gewicht_g` sind **Betreiber-Richtwerte** aus
Tierschutzsicht, keine gesetzlichen Mindestwerte — für Reptilien existiert in Deutschland keine
allgemeine gesetzliche Mindestabgabealtersgrenze. Sie steuern Regel 6 der Rechts-Engine.

Nicht enthalten sind Arten, deren Handel in der EU unabhängig vom Artenschutzrecht untersagt ist
(z. B. *Trachemys scripta* als Art der Unionsliste invasiver Arten). Eine eigene Regel dafür ist in
Phase 2 nicht vorgesehen und wäre gesondert zu beauftragen.

## `morphs.json` — Merkmalskatalog

59 Merkmale für *Pogona vitticeps*, *Python regius*, *Eublepharis macularius* und
*Correlophus ciliatus* mit Vererbungsmodus, Allelgruppe und Aliasnamen.

`is_lethal_combo = 1` markiert Merkmale, deren homozygote Form nicht lebensfähig ist
(z. B. Spider, Champagne, Hidden Gene Woma, Lilly White). Merkmale mit bekannten
tierschutzrelevanten Begleiterscheinungen (Silkback, Enigma, Lemon Frost, Desert) tragen einen
entsprechenden Hinweis in der Beschreibung.
