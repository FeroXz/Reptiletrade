<?php

declare(strict_types=1);

/**
 * Basissprache. Jeder Schluessel, den die Anwendung verwendet, muss hier
 * stehen — fehlt er, zeigt die Oberflaeche den Schluessel selbst an, und der
 * Test tests/Support/TranslatorTest deckt es auf.
 *
 * Ordnung nach Bereich, innerhalb alphabetisch.
 */
return [
    // -------------------------------------------------------------- Allgemein
    'allgemein.abbrechen' => 'Abbrechen',
    'allgemein.speichern' => 'Speichern',
    'allgemein.senden' => 'Senden',
    'allgemein.zurueck' => 'Zurück',
    'allgemein.weiter' => 'Weiter',
    'allgemein.keine_angabe' => 'Keine Angabe',
    'allgemein.bearbeiten' => 'Bearbeiten',
    'allgemein.loeschen' => 'Löschen',
    'allgemein.abmelden' => 'Abmelden',

    // ----------------------------------------------------------------- Konto
    'konto.titel' => 'Mein Konto',
    'konto.sicherheit' => 'Sicherheit',
    'konto.verifizierung' => 'Verifizierung',
    'konto.passwort_aendern' => 'Passwort ändern',
    'konto.passwort_geaendert' => 'Dein Passwort ist geändert.',
    'konto.email_bestaetigt' => 'Deine E-Mail-Adresse ist bestätigt.',
    'konto.email_verify_gesendet' => 'Wir haben dir einen Bestätigungslink geschickt.',
    'konto.telefon_code_gesendet' => 'Der Code ist unterwegs. Er gilt {minuten} Minuten.',
    'konto.telefon_bestaetigt' => 'Deine Telefonnummer ist bestätigt.',
    'konto.zwei_faktor_aktiv' => 'Zwei-Faktor-Anmeldung ist aktiv.',
    'konto.zwei_faktor_aus' => 'Zwei-Faktor-Anmeldung ist abgeschaltet.',
    'konto.nachweis_hochgeladen' => 'Der Nachweis liegt zur Prüfung vor.',

    // --------------------------------------------------------- Verifizierung
    'verifizierung.stufe_keine' => 'Nicht bestätigt',
    'verifizierung.stufe_email' => 'E-Mail bestätigt',
    'verifizierung.stufe_telefon' => 'Telefon bestätigt',
    'verifizierung.stufe_identitaet' => 'Identität geprüft',
    'verifizierung.hinweis' => 'Je mehr bestätigt ist, desto eher schreiben dich Käufer an.',

    // -------------------------------------------------------------- Postfach
    'postfach.titel' => 'Postfach',
    'postfach.leer' => 'Noch keine Gespräche.',
    'postfach.ungelesen.eins' => '1 ungelesene Nachricht',
    'postfach.ungelesen.viele' => '{anzahl} ungelesene Nachrichten',
    'postfach.antworten' => 'Antworten',
    'postfach.gesendet' => 'Nachricht gesendet.',
    'postfach.geschlossen' => 'Dieses Gespräch ist geschlossen.',
    'postfach.maskierung_hinweis' => 'In den ersten {anzahl} Nachrichten blenden wir Kontaktdaten aus. '
        . 'Das schützt vor automatisiertem Sammeln von Adressen.',
    'postfach.kontakt_ausgeblendet' => 'Kontaktdaten ausgeblendet',
    'postfach.handel_bestaetigen' => 'Handel bestätigen',
    'postfach.handel_bestaetigt' => 'Du hast den Handel bestätigt.',
    'postfach.handel_wartet' => 'Warte auf die Bestätigung der Gegenseite.',
    'postfach.handel_beidseitig' => 'Beide Seiten haben den Handel bestätigt.',

    // ------------------------------------------------------------ Bewertung
    'bewertung.titel' => 'Bewertung',
    'bewertung.abgeben' => 'Bewertung abgeben',
    'bewertung.gespeichert' => 'Danke für deine Bewertung.',
    'bewertung.keine' => 'Noch keine Bewertungen.',
    'bewertung.anzahl.eins' => '1 Bewertung',
    'bewertung.anzahl.viele' => '{anzahl} Bewertungen',
    'bewertung.nur_nach_handel' => 'Bewerten geht erst, wenn beide Seiten den Handel bestätigt haben.',

    // -------------------------------------------------------------- Meldung
    'meldung.titel' => 'Melden',
    'meldung.gesendet' => 'Danke. Die Moderation sieht sich das an.',
    'meldung.grund' => 'Worum geht es?',
    'meldung.beschreibung' => 'Beschreibung (optional)',

    // ------------------------------------------------------- Zuechterprofil
    'profil.titel' => 'Züchterprofil',
    'profil.bearbeiten' => 'Profil bearbeiten',
    'profil.gespeichert' => 'Dein Profil ist gespeichert.',
    'profil.zuchtjahre.eins' => 'seit 1 Jahr',
    'profil.zuchtjahre.viele' => 'seit {anzahl} Jahren',
    'profil.schwerpunkt' => 'Schwerpunkt',
    'profil.keine_anzeigen' => 'Zurzeit keine aktiven Anzeigen.',
    'profil.nicht_oeffentlich' => 'Dieses Profil ist nicht öffentlich.',

    // -------------------------------------------------------------- Schutz
    'schutz.rate_limit' => 'Zu viele Versuche. Bitte warte etwa {minuten} Minuten.',
    'schutz.nachricht_gesperrt' => 'Diese Nachricht wurde nicht gesendet. Sie enthält einen Zahlungsweg, '
        . 'der auf dieser Plattform nicht zulässig ist. Bezahlt wird bei der Übergabe.',
    'schutz.auto_moderation' => 'Die ersten {anzahl} Anzeigen eines neuen Kontos werden vor der '
        . 'Veröffentlichung geprüft.',

    // ---------------------------------------------------------------- Tarife
    'tarife.titel' => 'Tarife',
    'tarife.einleitung' => 'Inserieren ist kostenlos. Wer regelmäßig abgibt, bekommt im Züchter-Tarif mehr Platz.',
    'tarife.kostenlos' => 'Kostenlos',
    'tarife.aktueller_tarif' => 'Dein aktueller Tarif',
    'tarife.ohne_vertrag' => 'Ohne Vertrag, ohne Kündigung.',
    'tarife.buchen' => 'Buchen',
    'tarife.noch_nicht_buchbar' => 'Noch nicht buchbar.',
    'tarife.vorschau' => 'Vorschau.',
    'tarife.vorschau_text' => 'Die Tarife sind vorbereitet, aber nicht aktiv. Es lässt sich nichts kaufen, '
        . 'und es gilt keine Begrenzung der Anzeigenzahl.',
    'tarife.anzeigen.eins' => '1 aktive Anzeige',
    'tarife.anzeigen.viele' => '{anzahl} aktive Anzeigen',
    'tarife.anzeigen_unbegrenzt' => 'Unbegrenzt viele aktive Anzeigen',
    'tarife.laufzeit' => '{tage} Tage Laufzeit je Anzeige',
    'tarife.bilder' => 'Bis zu {anzahl} Bilder je Anzeige',
    'tarife.entspricht' => 'entspricht {betrag} pro Monat',
    'tarife.preise_brutto' => 'Alle Preise sind Endpreise inklusive Umsatzsteuer.',
    'tarife.kein_treuhand' => 'Wir wickeln keine Tierverkäufe ab und nehmen dafür kein Geld entgegen — '
        . 'bezahlt wird zwischen euch, bei der Übergabe.',

    // ---------------------------------------------------------------- Boost
    'boost.titel' => 'Top-Platzierung',
    'boost.erklaerung' => 'Eine Anzeige steht für die gebuchte Zeit oben in der Trefferliste. '
        . 'Sie wird dadurch nicht besser gefunden, nur früher gesehen.',
    'boost.je_tag' => '{betrag} pro Tag',
    'boost.laeuft_bis' => 'Top-Platzierung bis {datum}',

    // ----------------------------------------------------------- Abrechnung
    'abrechnung.titel' => 'Abrechnung',
    'abrechnung.tarif' => 'Tarif',
    'abrechnung.nicht_aktiv' => 'Die Abrechnung ist vorbereitet, aber nicht aktiv. '
        . 'Es wird nichts berechnet, und es gilt keine Begrenzung.',
    'abrechnung.anzeigen_von' => '{anzahl} von {grenze} aktiven Anzeigen',
    'abrechnung.anzeigen_unbegrenzt' => '{anzahl} aktive Anzeigen, unbegrenzt',
    'abrechnung.laeuft_bis' => 'läuft bis {datum}',
    'abrechnung.kuendigen' => 'Zum Laufzeitende kündigen',
    'abrechnung.kuendigung_hinweis' => 'Bezahlt ist bezahlt: Bis zum Laufzeitende bleibt alles wie gehabt.',
    'abrechnung.tarife_ansehen' => 'Tarife ansehen',
    'abrechnung.belege' => 'Belege',
    'abrechnung.keine_belege' => 'Noch keine Belege.',
    'abrechnung.datum' => 'Datum',
    'abrechnung.zweck' => 'Zweck',
    'abrechnung.betrag' => 'Betrag',
    'abrechnung.status' => 'Status',
    'abrechnung.enthaltene_steuer' => 'darin {satz} % USt: {betrag}',

    // -------------------------------------------------------------- Nachzucht
    'nachzucht.titel' => 'Nachzucht-Ankündigungen',
    'nachzucht.erklaerung' => 'Kündige an, was demnächst schlüpft. Sobald die Tiere da sind, '
        . 'wird daraus eine richtige Anzeige — angekündigt wird, was es noch nicht gibt.',
    'nachzucht.nur_zuechter' => 'Ankündigungen gehören zum Züchter-Tarif.',
    'nachzucht.neu' => 'Neue Ankündigung',
    'nachzucht.meine' => 'Meine Ankündigungen',
    'nachzucht.keine' => 'Noch keine Ankündigungen.',
    'nachzucht.art' => 'Art',
    'nachzucht.erwartet' => 'Erwartet am',
    'nachzucht.erwartet_am' => 'erwartet am {datum}',
    'nachzucht.ueberschrift' => 'Überschrift',
    'nachzucht.verpaarung' => 'Verpaarung',
    'nachzucht.beschreibung' => 'Beschreibung',
    'nachzucht.zustand' => 'Zustand',

    // -------------------------------------------------------------- Genetik
    'genetik.titel' => 'Verpaarungs-Simulator',
    'genetik.erklaerung' => 'Was fällt aus dieser Verpaarung? Wähle zwei Tiere — aus deinen Anzeigen oder '
        . 'von Hand zusammengestellt — und der Rechner zeigt die zu erwartende Nachzucht mit ihren Anteilen.',
    'genetik.art' => 'Art',
    'genetik.art_waehlen' => 'Art wählen',
    'genetik.merkmale_laden' => 'Merkmale laden',
    'genetik.tier_a' => 'Elterntier 1',
    'genetik.tier_b' => 'Elterntier 2',
    'genetik.aus_anzeige' => 'Aus einer eigenen Anzeige',
    'genetik.ohne_anzeige' => '— von Hand zusammenstellen —',
    'genetik.geschlecht' => 'Geschlecht',
    'genetik.merkmale' => 'Merkmale',
    'genetik.keine_merkmale' => 'Für diese Art ist noch kein Merkmalskatalog hinterlegt.',
    'genetik.nicht_vorhanden' => '— nicht vorhanden —',
    'genetik.berechnen' => 'Verpaarung berechnen',
    'genetik.ergebnis' => 'Erwartete Nachzucht',
    'genetik.gelege' => 'Aus einem Gelege von etwa {eier} Eiern sind rund {tiere} lebensfähige '
        . 'Schlüpflinge zu erwarten. Die Anteile beziehen sich auf diese Tiere.',
    'genetik.nach_geschlecht' => 'Nach Geschlecht',
    'genetik.soehne' => 'Söhne',
    'genetik.toechter' => 'Töchter',
    'genetik.punnett' => 'Punnett-Quadrate',
    'genetik.punnett_erklaerung' => 'Ein Feld je Genort: oben die Allele des ersten Elterntiers, '
        . 'links die des zweiten.',
    'genetik.warnungen' => 'Hinweise',
    'genetik.letal' => 'Nicht lebensfähig',
    'genetik.letal_anteil' => '{anteil} % der Nachkommen aus dieser Verpaarung sind rechnerisch nicht lebensfähig.',
    'genetik.pdf' => 'Bericht als PDF',
    'genetik.berichte' => 'Meine Genetik-Berichte',
    'genetik.keine_berichte' => 'Noch keine Berichte gespeichert.',
    'genetik.zum_rechner' => 'Zum Verpaarungs-Simulator',
    'genetik.fussnote' => 'Die Angaben sind eine Rechnung nach den Mendelschen Regeln auf Grundlage der '
        . 'angegebenen Merkmale. Sie sagen voraus, was zu erwarten ist — nicht, was in einem einzelnen '
        . 'Gelege eintritt. Anlagen, von denen niemand weiß, sind darin nicht enthalten.',

    // --------------------------------------------------------------- Anzeige
    'anzeige.meine' => 'Meine Anzeigen',
    'anzeige.neu' => 'Neue Anzeige',
    'anzeige.keine' => 'Noch keine Anzeigen angelegt.',
    'anzeige.ohne_titel' => '(Entwurf ohne Titel)',
    'anzeige.unbekannte_art' => 'Unbekannte Art',
    'anzeige.ansehen' => 'Ansehen',
    'anzeige.weiter_im_assistenten' => 'Im Assistenten weiter',
    'anzeige.zurueck_zur_liste' => 'Zurück zu meinen Anzeigen',
    'anzeige.bearbeiten' => 'Anzeige bearbeiten',
    'anzeige.bearbeiten_fest' => 'Art ({art}) und Angebotstyp ({typ}) stehen fest. Sie zu ändern hieße, '
        . 'Rechtsprüfung, Merkmale und Nachweise auf eine andere Grundlage zu stellen — dafür gibt es eine neue Anzeige.',
    'anzeige.bearbeitungen.eins' => '1 Bearbeitung',
    'anzeige.bearbeitungen.viele' => '{anzahl} Bearbeitungen',
    'anzeige.titel' => 'Titel',
    'anzeige.beschreibung' => 'Beschreibung',
    'anzeige.preis' => 'Preis',
    'anzeige.verhandelbar' => 'Preis verhandelbar',
    'anzeige.tauschwunsch' => 'Tauschwunsch',
    'anzeige.geschlecht' => 'Geschlecht',
    'anzeige.gewicht' => 'Gewicht in Gramm',
    'anzeige.anzahl' => 'Anzahl',
    'anzeige.herkunft' => 'Herkunft',
    'anzeige.uebergabe' => 'Übergabe',
    'anzeige.land' => 'Land',
    'anzeige.plz' => 'Postleitzahl',
    'anzeige.pause' => 'Pause',
    'anzeige.pause_erklaerung' => 'Eine pausierte Anzeige verschwindet aus Suche und Trefferliste und lässt sich '
        . 'jederzeit wieder fortsetzen. Ihre Laufzeit läuft weiter — die Pause verlängert sie nicht.',
    'anzeige.pausieren' => 'Pausieren',
    'anzeige.fortsetzen' => 'Fortsetzen',
    'anzeige.loeschen' => 'Anzeige löschen',
    'anzeige.loeschen_oeffnen' => 'Löschung vorbereiten',
    'anzeige.loeschen_bestaetigen' => 'Anzeige endgültig löschen',
    'anzeige.loeschen_endgueltig_hinweis' => 'An dieser Anzeige hängt nichts von anderen. Sie wird vollständig '
        . 'gelöscht, mitsamt Bildern und Nachweisen. Das lässt sich nicht rückgängig machen.',
    'anzeige.loeschen_archiviert' => 'An dieser Anzeige hängen {gespraeche} Gespräch(e) und {bewertungen} '
        . 'Bewertung(en). Die gehören auch der jeweils anderen Seite, deshalb wird die Anzeige abgeschaltet '
        . 'statt gelöscht: Bilder und Nachweise verschwinden, die Anzeige selbst bleibt als Bezugspunkt stehen.',

    // ------------------------------------------------------------ Statistik
    'statistik.titel' => 'Aufrufe und Anfragen',
    'statistik.tage' => '{tage} Tage',
    'statistik.aufrufe' => 'Aufrufe',
    'statistik.anfragen' => 'Anfragen',
    'statistik.quote' => 'Anfragen je 100 Aufrufe',
    'statistik.verlauf' => 'Aufrufe im Verlauf',
    'statistik.verlauf_hinweis' => 'Ein Balken je Tag, höchster Wert im Zeitraum: {hoechstwert} Aufrufe.',
    'statistik.je_anzeige' => 'Nach Anzeige',
    'statistik.spalte_anzeige' => 'Anzeige',
    'statistik.leer' => 'Für diesen Zeitraum gibt es noch keine Zahlen. Sobald deine Anzeigen '
        . 'aufgerufen werden, steht hier etwas.',
    'statistik.zaehlweise' => 'Gezählt wird ein Aufruf je Besucher und Anzeige, nicht jedes Neuladen. '
        . 'Eigene Aufrufe zählen nicht mit. Als Anfrage zählt ein begonnenes Gespräch.',

    // ------------------------------------------------------------ Verwaltung
    'admin.titel' => 'Verwaltung',
    'admin.artenstamm' => 'Artenstamm',
    'admin.anzeigen' => 'Anzeigen',
    'admin.alle' => 'Alle',
    'admin.suche' => 'Suche',
    'admin.suche_platzhalter' => 'Titel, Anbietername oder E-Mail',
    'admin.filtern' => 'Filtern',
    'admin.nur_pausierte' => 'Nur pausierte',
    'admin.keine_anzeigen' => 'Keine Anzeigen gefunden.',
    'admin.spalte_anzeige' => 'Anzeige',
    'admin.spalte_anbieter' => 'Anbieter',
    'admin.spalte_zustand' => 'Zustand',
    'admin.spalte_erstellt' => 'Erstellt',
    'admin.spalte_aktion' => 'Maßnahme',
    'admin.freigeben' => 'Freigeben',
    'admin.pause_grund' => 'Grund (der Anbieter sieht ihn)',
    'admin.offene_meldungen_zahl.eins' => '1 offene Meldung',
    'admin.offene_meldungen_zahl.viele' => '{anzahl} offene Meldungen',
    'admin.liste_gekuerzt' => 'Es werden höchstens {grenze} Anzeigen gezeigt. Grenze den Filter weiter ein.',
    'admin.pausierte_anzeigen' => 'Pausierte Anzeigen',
    'admin.moderation' => 'Moderation',

    // ----------------------------------------------------- Texte der Oberflaeche
    'admin.texte.titel' => 'Texte',
    'admin.texte.erklaerung' => 'Alle Beschriftungen und Meldungen der Oberfläche. Was du hier änderst, '
        . 'gilt sofort und überschreibt den ausgelieferten Text — zurücksetzen geht jederzeit.',
    'admin.texte.suche' => 'Suche',
    'admin.texte.suche_platzhalter' => 'Schlüssel oder Text',
    'admin.texte.bereich' => 'Bereich',
    'admin.texte.alle_bereiche' => 'Alle Bereiche',
    'admin.texte.filtern' => 'Filtern',
    'admin.texte.treffer.eins' => '1 Text',
    'admin.texte.treffer.viele' => '{anzahl} Texte',
    'admin.texte.geaendert.eins' => '1 geändert',
    'admin.texte.geaendert.viele' => '{anzahl} geändert',
    'admin.texte.geaendert_am' => 'geändert am {datum}',
    'admin.texte.keine_treffer' => 'Kein Text passt zur Suche.',
    'admin.texte.original' => 'Ausgeliefert',
    'admin.texte.zuruecksetzen' => 'Zurücksetzen',
    'admin.texte.zurueckgesetzt' => 'Der Text steht wieder auf der ausgelieferten Fassung.',
    'admin.texte.platzhalter' => 'Platzhalter, die erhalten bleiben müssen:',
    'admin.texte.gespeichert.eins' => '1 Text gespeichert.',
    'admin.texte.gespeichert.viele' => '{anzahl} Texte gespeichert.',
    'admin.texte.unveraendert' => 'Es gab nichts zu speichern.',
    'admin.zurueck' => 'Zurück zur Übersicht',
    'admin.offene_meldungen' => 'Offene Meldungen',
    'admin.anzeigen_pruefung' => 'Anzeigen in Prüfung',
    'admin.nachweise_offen' => 'Nachweise offen',
    'admin.markierte_nachrichten' => 'Markierte Nachrichten',
    'admin.aktive_anzeigen' => 'Aktive Anzeigen',
    'admin.aktive_konten' => 'Aktive Konten',
    'admin.neue_konten' => 'Neue Konten (7 Tage)',
    'admin.anzeigen_pro_tag' => 'Anzeigen pro Tag',
    'admin.top_arten' => 'Meistgehandelte Arten',
    'admin.keine_daten' => 'Noch keine Daten.',
    'admin.rechtstexte' => 'Rechtstexte',
    'admin.rechtstexte_aktuell' => 'Alle Rechtstexte sind innerhalb der Prüffrist.',
    'admin.rechtstexte_faellig' => '{anzahl} Texte sind zur Überprüfung fällig.',
    'admin.nie_geprueft' => 'nie geprüft',
    'admin.betrieb' => 'Betrieb',
    'admin.jobs_wartend' => '{anzahl} Aufträge warten',
    'admin.jobs_fehlgeschlagen' => '{anzahl} fehlgeschlagen',
    'admin.fristen' => 'Aufbewahrungsfristen',
    'admin.frist_aus' => 'keine Löschung',
    'admin.artenstamm_warnung' => 'Der Artenstamm ist die Grundlage der Rechtsprüfung: '
        . 'Schutzstatus, Melde- und Dokumentationspflicht kommen von hier. Ein falscher Import '
        . 'ändert nicht nur eine Liste, sondern die Hinweise auf allen betroffenen Anzeigen.',
    'admin.import_ergebnis' => 'Letzter Import',
    'admin.import_nichts_geschrieben' => 'Der Import wird erst geschrieben, wenn keine Zeile mehr beanstandet wird.',
    'admin.arten' => 'Arten',
    'admin.arten_erklaerung' => 'Wissenschaftlicher Name, Schutzstatus, Abgabegrenzen.',
    'admin.morphs' => 'Merkmale',
    'admin.morphs_erklaerung' => 'Farb- und Zeichnungsmerkmale mit Erbgang, jeweils zu einer Art.',
    'admin.export_csv' => 'Als CSV laden',
    'admin.export_json' => 'Als JSON laden',
    'admin.datei' => 'Datei',
    'admin.format_erkennung' => 'CSV oder JSON. Das Format wird an der Dateiendung erkannt.',
    'admin.probelauf' => 'Probelauf — nur prüfen, nichts schreiben.',
    'admin.import_starten' => 'Import starten',
    'admin.spalten' => 'Erwartete Spalten',
    'admin.spalten_erklaerung' => 'Zugeordnet wird über die Kopfzeile. Unbekannte Spalten werden übergangen, '
        . 'ein Export aus einer neueren Fassung bleibt also einlesbar. '
        . 'Geschrieben wird über den wissenschaftlichen Namen: Vorhandenes wird aktualisiert, Neues angelegt.',

    // ------------------------------------------------------------ Meine Daten
    'daten.titel' => 'Meine Daten',
    'daten.zurueck' => 'Zurück zum Konto',
    'daten.auskunft' => 'Datenauskunft',
    'daten.auskunft_erklaerung' => 'Du bekommst alles, was zu deinem Konto gespeichert ist, '
        . 'als JSON-Datei — ohne Rückfrage, ohne Begründung, sofort.',
    'daten.auskunft_ohne' => 'Nicht enthalten sind Passwort-Hash und Zwei-Faktor-Geheimnis: '
        . 'Das sind Schlüssel zu deinem Konto, keine Daten über dich.',
    'daten.auskunft_laden' => 'Auskunft herunterladen',
    'daten.loeschen' => 'Konto löschen',
    'daten.anzeigen' => 'Anzeigen',
    'daten.gespraeche' => 'Gespräche',
    'daten.bewertungen_erhalten' => 'Erhaltene Bewertungen',
    'daten.bewertungen_abgegeben' => 'Abgegebene Bewertungen',
    'daten.unwiderruflich' => 'Die Löschung lässt sich nicht rückgängig machen. '
        . 'Lade dir vorher deine Auskunft herunter, wenn du deine Daten behalten willst.',
    'daten.loeschen_oeffnen' => 'Löschung vorbereiten',
    'daten.passwort' => 'Passwort',
    'daten.bestaetigung' => 'Tippe LÖSCHEN zur Bestätigung',
    'daten.loeschen_endgueltig' => 'Konto endgültig löschen',

    // ----------------------------------------------------------------- Mail
    'mail.email_verify.betreff' => 'Bitte bestätige deine E-Mail-Adresse',
    'mail.email_verify.text' => "Hallo {name},\n\n"
        . "bitte bestätige deine E-Mail-Adresse über diesen Link:\n\n{link}\n\n"
        . "Der Link gilt {stunden} Stunden.\n\n"
        . 'Wenn du dich nicht angemeldet hast, ignoriere diese Nachricht einfach.',
    'mail.telefon_code.betreff' => 'Dein Bestätigungscode',
    'mail.telefon_code.text' => "Dein Code lautet: {code}\n\n"
        . "Er bestätigt die Nummer {nummer} und gilt {minuten} Minuten.",
    'mail.passwort_reset.betreff' => 'Passwort zurücksetzen',
    'mail.passwort_reset.text' => "Hallo {name},\n\n"
        . "über diesen Link vergibst du ein neues Passwort:\n\n{link}\n\n"
        . "Der Link gilt {minuten} Minuten und lässt sich nur einmal verwenden.\n\n"
        . 'Wenn du das nicht angefordert hast, passiert nichts — ignoriere die Nachricht.',
    'mail.nachricht.betreff' => 'Neue Nachricht zu deiner Anzeige',
    'mail.nachricht.text' => "Hallo {name},\n\n"
        . "zu \"{anzeige}\" ist eine neue Nachricht eingegangen.\n\n{link}",
    'mail.ablauf.betreff.eins' => 'Deine Anzeige läuft morgen ab',
    'mail.ablauf.betreff.viele' => 'Deine Anzeige läuft in {anzahl} Tagen ab',
    'mail.ablauf.text.eins' => "Hallo {name},\n\n"
        . "deine Anzeige \"{anzeige}\" läuft morgen ab.\n\n"
        . "Verlängern oder beenden kannst du sie hier:\n{link}\n",
    'mail.ablauf.text.viele' => "Hallo {name},\n\n"
        . "deine Anzeige \"{anzeige}\" läuft in {anzahl} Tagen ab.\n\n"
        . "Verlängern oder beenden kannst du sie hier:\n{link}\n",
    'mail.kontakt.betreff' => '[Kontakt] {thema}: {betreff}',
    'mail.kontakt.text' => "Anfrage #{nummer} über das Kontaktformular.\n\n"
        . "Thema:   {thema}\n"
        . "Von:     {name} <{email}>\n"
        . "Betreff: {betreff}\n\n"
        . "{nachricht}\n",
    'mail.suchtreffer.betreff' => 'Neue Treffer zu "{suche}"',
    'mail.suchtreffer.text' => "Hallo {name},\n\n"
        . "zu deiner gespeicherten Suche \"{suche}\" gibt es neue Anzeigen:\n\n{treffer}\n\n"
        . "Benachrichtigungen ändern: {link}\n",

    // ----------------------------------------------------- Redaktion (oeffentlich)
    'inhalt.alle_beitraege' => 'Alle Beiträge',
    'inhalt.anzeige' => 'Anzeige',
    'inhalt.artenprofil' => 'Artenprofil',
    'inhalt.entfernt_text' => 'Dieser Inhalt wurde zurückgezogen. Er kommt nicht wieder.',
    'inhalt.entfernt_titel' => 'Nicht mehr verfügbar',
    'inhalt.pfadleiste' => 'Sie befinden sich hier',
    'inhalt.verweis_fehlt' => 'Der Block "{typ}" verweist auf etwas, das es nicht mehr gibt. Für Besucher entfällt er.',
    'inhalt.vorschau_hinweis' => 'Vorschau — diese Fassung ist noch nicht veröffentlicht.',
    'inhalt.zur_uebersicht' => 'Zur Marktübersicht',

    // ------------------------------------------------------ Redaktion (Verwaltung)
    'admin.inhalt.abschnitt_bloecke' => 'Inhalt',
    'admin.inhalt.abschnitt_kopf' => 'Grunddaten',
    'admin.inhalt.abschnitt_status' => 'Veröffentlichung',
    'admin.inhalt.abschnitt_suchmaschine' => 'Suchmaschine',
    'admin.inhalt.alle' => 'Alle',
    'admin.inhalt.angelegt' => 'Der Inhalt ist angelegt. Jetzt kannst du ihn füllen.',
    'admin.inhalt.anlegen' => 'Anlegen',
    'admin.inhalt.archiviert' => 'Der Inhalt ist archiviert. Die Adresse antwortet ab jetzt mit 410.',
    'admin.inhalt.archivieren' => 'Archivieren',
    'admin.inhalt.block_alt' => 'Bildbeschreibung (Pflicht)',
    'admin.inhalt.block_anzeige' => 'Nummer der Anzeige',
    'admin.inhalt.block_art' => 'Slug der Art',
    'admin.inhalt.block_bildunterschrift' => 'Bildunterschrift',
    'admin.inhalt.block_hinzufuegen' => 'Block hinzufügen',
    'admin.inhalt.block_hoch' => 'Hoch',
    'admin.inhalt.block_label' => 'Beschriftung des Knopfs',
    'admin.inhalt.block_medien' => 'Nummern der Bilder, mit Komma getrennt',
    'admin.inhalt.block_medium' => 'Nummer des Bilds',
    'admin.inhalt.block_ohne_felder' => 'Dieser Block hat nichts einzustellen.',
    'admin.inhalt.block_quelle' => 'Quelle',
    'admin.inhalt.block_runter' => 'Runter',
    'admin.inhalt.block_text' => 'Text',
    'admin.inhalt.block_ueberschrift' => 'Überschrift',
    'admin.inhalt.block_zitat' => 'Zitat',
    'admin.inhalt.block_ziel' => 'Ziel (interner Pfad oder https://…)',
    'admin.inhalt.eltern_hilfe' => 'Bestimmt den Pfad: Unter „Haltung“ wird aus „Terrarium“ die Adresse /haltung/terrarium/.',
    'admin.inhalt.erklaerung' => 'Seiten und Beiträge. Die Rechtsseiten liegen bewusst nicht hier — sie stehen in config/impressum.php.',
    'admin.inhalt.feld_anriss' => 'Anriss',
    'admin.inhalt.feld_eltern' => 'Elternseite',
    'admin.inhalt.feld_meta_beschreibung' => 'Beschreibung für Suchmaschinen',
    'admin.inhalt.feld_meta_titel' => 'Titel für Suchmaschinen',
    'admin.inhalt.feld_noindex' => 'Von Suchmaschinen fernhalten (noindex)',
    'admin.inhalt.feld_slug' => 'Slug',
    'admin.inhalt.feld_titel' => 'Titel',
    'admin.inhalt.feld_vorlage' => 'Vorlage',
    'admin.inhalt.filtern' => 'Filtern',
    'admin.inhalt.geloescht' => 'Der Inhalt ist gelöscht.',
    'admin.inhalt.geplant' => 'Der Termin steht. Freigeschaltet wird viertelstündlich vom Auftrag content.publish.',
    'admin.inhalt.gespeichert' => 'Gespeichert.',
    'admin.inhalt.hinzufuegen' => 'Hinzufügen',
    'admin.inhalt.keine_bloecke' => 'Noch kein Inhalt. Füge unten einen Block hinzu.',
    'admin.inhalt.keine_eltern' => 'Keine — direkt unter der Wurzel',
    'admin.inhalt.leer' => 'Zu diesem Filter gibt es nichts.',
    'admin.inhalt.loeschen' => 'Löschen',
    'admin.inhalt.loeschen_bestaetigen' => 'Diesen Inhalt endgültig löschen?',
    'admin.inhalt.markdown_hilfe' => 'Textblöcke verstehen Markdown: ## Überschrift, **fett**, *kursiv*, - Liste, > Zitat, [Text](/ziel/). Alles andere erscheint als Text.',
    'admin.inhalt.neu' => 'Neuer Inhalt',
    'admin.inhalt.slug_aenderung' => 'Änderst du den Slug einer veröffentlichten Seite, entsteht automatisch eine Weiterleitung vom alten Pfad.',
    'admin.inhalt.slug_hilfe' => 'Leer lassen — dann wird er aus dem Titel gebildet.',
    'admin.inhalt.spalte_geaendert' => 'Geändert',
    'admin.inhalt.spalte_pfad' => 'Pfad',
    'admin.inhalt.spalte_status' => 'Status',
    'admin.inhalt.spalte_titel' => 'Titel',
    'admin.inhalt.spalte_typ' => 'Art',
    'admin.inhalt.status' => 'Status',
    'admin.inhalt.suche' => 'Suche',
    'admin.inhalt.suche_platzhalter' => 'Titel oder Pfad',
    'admin.inhalt.termin' => 'Termin',
    'admin.inhalt.termin_hilfe' => 'Leer lassen für „sofort“.',
    'admin.inhalt.titel' => 'Inhalte',
    'admin.inhalt.typ' => 'Art',
    'admin.inhalt.veroeffentlichen' => 'Veröffentlichen',
    'admin.inhalt.veroeffentlicht' => 'Der Inhalt ist online.',
    'admin.inhalt.zurueckgenommen' => 'Der Inhalt ist wieder ein Entwurf. Eine Weiterleitung wurde nicht angelegt — er soll ja zurückkommen.',
    'admin.inhalt.abschnitt_vorschau' => 'Vorschau',
    'admin.inhalt.aktuelle_fassung' => 'Aktueller Stand',
    'admin.inhalt.keine_versionen' => 'Zu diesem Inhalt gibt es noch keine Fassungen.',
    'admin.inhalt.spalte_aktion' => 'Maßnahme',
    'admin.inhalt.spalte_anlass' => 'Anlass',
    'admin.inhalt.spalte_fassung' => 'Nr.',
    'admin.inhalt.spalte_zeitpunkt' => 'Zeitpunkt',
    'admin.inhalt.versionen' => 'Fassungen',
    'admin.inhalt.versionen_aufbewahrung' => 'Aufbewahrt werden zurzeit {anzahl} Fassungen. Wie viele es sein sollen, steht in config/aufbewahrung.php.',
    'admin.inhalt.versionen_erklaerung' => 'Jedes Speichern und jede Veröffentlichung legt eine Fassung an. Zurücksetzen ändert Titel, Anriss und Blöcke — Pfad und Status bleiben, wie sie sind.',
    'admin.inhalt.vorschau_erklaerung' => 'Erzeugt einen Link, der diesen Stand zeigt, ohne dass sich der Betrachter anmelden muss. Er gilt 24 Stunden.',
    'admin.inhalt.vorschau_erzeugen' => 'Vorschaulink erzeugen',
    'admin.inhalt.vorschau_link' => 'Der Vorschaulink gilt {stunden} Stunden: {link} — er erscheint nur dieses eine Mal.',
    'admin.inhalt.zurueckgesetzt' => 'Der Inhalt steht wieder auf der gewählten Fassung. Der vorherige Stand liegt als neue Fassung daneben.',
    'admin.inhalt.zuruecksetzen' => 'Zurücksetzen',
    'admin.inhalt.zuruecknehmen' => 'Zurücknehmen',
    'admin.inhalt.zuruecknehmen_hinweis' => 'Zurücknehmen macht aus dem Inhalt wieder einen Entwurf. Archivieren behält ihn und beantwortet die Adresse mit 410 — für etwas, das bewusst nicht mehr kommt.',
];
