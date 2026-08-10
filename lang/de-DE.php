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
    'admin.mails_aufgegeben' => 'Nicht zugestellte Mails',
    'admin.mails_aufgegeben_hinweis' => 'Diese Mails wurden nach mehreren Versuchen aufgegeben. '
        . 'Sie bleiben im Postausgang stehen, damit nachvollziehbar bleibt, wer seine Nachricht nicht bekommen hat.',
    'admin.mail_versuche' => '{anzahl} Versuche',
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

    // ------------------------------------------------------------ Sitzungen
    'sitzungen.titel' => 'Angemeldete Geräte',
    'sitzungen.erklaerung' => 'Hier siehst du, wo dein Konto gerade angemeldet ist. '
        . 'Von der Herkunft wird nur das Netz gespeichert, von der Browserkennung nur der Anfang — '
        . 'zum Wiedererkennen reicht das, für mehr gibt es keinen Grund.',
    'sitzungen.diese' => 'Dieses Gerät',
    'sitzungen.zuletzt' => 'Zuletzt aktiv: {zeitpunkt}',
    'sitzungen.angemeldet_seit' => 'Angemeldet seit {zeitpunkt}',
    'sitzungen.beenden' => 'Beenden',
    'sitzungen.beendet' => 'Die Sitzung ist beendet.',
    'sitzungen.eigene_nicht' => 'Das ist deine aktuelle Sitzung — dafür gibt es den Abmeldeknopf.',
    'sitzungen.alle_titel' => 'Alle anderen Geräte abmelden',
    'sitzungen.alle_erklaerung' => 'Wenn du den Verdacht hast, dass jemand anderes Zugriff auf dein Konto hat: '
        . 'Das beendet alle Sitzungen außer dieser. Danach solltest du auch dein Passwort ändern.',
    'sitzungen.passwort' => 'Zur Bestätigung dein Passwort',
    'sitzungen.alle_beenden' => 'Alle anderen beenden',
    'sitzungen.alle_beendet' => '{anzahl} Sitzungen wurden beendet.',
    'sitzungen.passwort_falsch' => 'Das Passwort stimmt nicht.',

    // ------------------------------------------------------------ Merkliste
    'merkliste.titel' => 'Merkliste',
    'merkliste.merken' => 'Merken',
    'merkliste.entmerken' => 'Nicht mehr merken',
    'merkliste.gemerkt' => 'Die Anzeige liegt auf deiner Merkliste.',
    'merkliste.entfernt' => 'Die Anzeige ist von deiner Merkliste entfernt.',
    'merkliste.leer' => 'Deine Merkliste ist leer. Auf jeder Anzeige findest du den Knopf „Merken".',
    'merkliste.zustand' => 'Derzeit nicht öffentlich: {zustand}',
    'statistik.merkungen' => 'Merkungen',

    // ------------------------------------------------------ Gemerkte Suchen
    'suchen.titel' => 'Gemerkte Suchen',
    'suchen.merken' => 'Suche merken',
    'suchen.merken_knopf' => 'Diese Suche merken',
    'suchen.name' => 'Name der Suche',
    'suchen.nur_angemeldet' => 'Zum Merken einer Suche brauchst du ein Konto. '
        . 'Nach der Anmeldung landest du wieder bei diesen Treffern.',
    'suchen.anmelden' => 'Anmelden und merken',
    'suchen.gemerkt' => 'Die Suche ist gemerkt. Du findest sie unter „Gemerkte Suchen".',
    'suchen.geloescht' => 'Die gespeicherte Suche ist gelöscht.',
    'suchen.gespeichert' => 'Die Benachrichtigung ist geändert.',
    'suchen.frequenz_unbekannt' => 'Diesen Takt gibt es nicht.',
    'suchen.leer' => 'Du hast noch keine Suche gemerkt. Stelle im Markt deine Filter ein '
        . 'und merke dir die Suche — dann meldet sie sich, wenn etwas Passendes dazukommt.',
    'suchen.grenze' => '{anzahl} von {grenze} gemerkten Suchen.',
    'suchen.benachrichtigung' => 'Benachrichtigung',
    'suchen.uebernehmen' => 'Übernehmen',
    'suchen.loeschen' => 'Löschen',
    'suchen.zuletzt_gemeldet' => 'Zuletzt gemeldet am {zeitpunkt}.',
    'suchen.frequenz.aus' => 'Keine',
    'suchen.frequenz.sofort' => 'Sofort',
    'suchen.frequenz.taeglich' => 'Täglich',
    'suchen.frequenz.woechentlich' => 'Wöchentlich',

    // ------------------------------------------------------ Benachrichtigungen
    'benachrichtigung.titel' => 'Benachrichtigungen',
    'benachrichtigung.erklaerung' => 'Hier stellst du ein, worüber dich der Reptilienmarkt per E-Mail '
        . 'informiert. Was du abschaltest, bekommst du nicht mehr — im Postfach auf der Seite steht es weiterhin.',
    'benachrichtigung.speichern' => 'Einstellungen speichern',
    'benachrichtigung.gespeichert' => 'Deine Benachrichtigungen sind gespeichert.',
    'benachrichtigung.immer_an' => 'Lässt sich nicht abschalten.',

    'benachrichtigung.kanal.nachricht.neu' => 'Neue Nachrichten',
    'benachrichtigung.kanal.nachricht.neu.beschreibung' => 'Wenn dir jemand zu einer Anzeige schreibt.',
    'benachrichtigung.kanal.suche.treffer' => 'Treffer zu gespeicherten Suchen',
    'benachrichtigung.kanal.suche.treffer.beschreibung' => 'Einmal täglich, wenn es zu einer gespeicherten '
        . 'Suche neue Anzeigen gibt. Standardmäßig aus.',
    'benachrichtigung.kanal.anzeige.ablauf' => 'Ablaufende Anzeigen',
    'benachrichtigung.kanal.anzeige.ablauf.beschreibung' => 'Erinnerung, bevor eine deiner Anzeigen ausläuft.',
    'benachrichtigung.kanal.handel.bestaetigung' => 'Handelsbestätigungen',
    'benachrichtigung.kanal.handel.bestaetigung.beschreibung' => 'Wenn ein Handel bestätigt wurde und eine '
        . 'Bewertung möglich ist.',
    'benachrichtigung.kanal.system.wichtig' => 'Wichtige Hinweise zum Konto',
    'benachrichtigung.kanal.system.wichtig.beschreibung' => 'Kontosperren, Sicherheitshinweise und Änderungen '
        . 'an den Rechtstexten. Ohne diese Nachrichten könntest du auf nichts davon reagieren.',

    'benachrichtigung.abmeldelinks.titel' => 'Abmeldelinks',
    'benachrichtigung.abmeldelinks.erklaerung' => 'Jede Benachrichtigung enthält denselben Abmeldelink zu '
        . 'deinem Konto. Wenn eine alte E-Mail in fremde Hände geraten ist, machst du hier alle bisherigen '
        . 'Links auf einmal ungültig — künftige E-Mails tragen dann einen neuen. Deine Einstellungen und '
        . 'deine Anmeldung bleiben davon unberührt.',
    'benachrichtigung.abmeldelinks.knopf' => 'Abmeldelinks erneuern',
    'benachrichtigung.abmeldelinks.erneuert' => 'Die bisherigen Abmeldelinks sind ungültig. '
        . 'Neue E-Mails tragen einen neuen Link.',

    'benachrichtigung.abmelden.frage.titel' => 'Benachrichtigung abbestellen',
    'benachrichtigung.abmelden.frage' => 'Möchtest du keine E-Mails mehr zu „{kanal}“ bekommen?',
    'benachrichtigung.abmelden.frage.hinweis' => 'Erst mit dem Knopf wird abbestellt. Alle anderen '
        . 'Benachrichtigungen bleiben unverändert, und deine Anmeldung bleibt bestehen.',
    'benachrichtigung.abmelden.frage.knopf' => 'Ja, abbestellen',

    'benachrichtigung.abmelden.titel' => 'Benachrichtigung abbestellt',
    'benachrichtigung.abmelden.erfolg' => 'Du bekommst keine E-Mails mehr zu: {kanal}.',
    'benachrichtigung.abmelden.rest_laeuft_weiter' => 'Alle anderen Benachrichtigungen bleiben unverändert, '
        . 'und deine Sitzung ist weiterhin angemeldet.',
    'benachrichtigung.abmelden.unbekannt' => 'Dieser Abmeldelink nennt keine Benachrichtigungsart, '
        . 'die es gibt.',
    'benachrichtigung.abmelden.einstellungen' => 'Alle Benachrichtigungen einstellen',

    'mail.abmelden.hinweis' => "Diese E-Mail bekommst du, weil die passende Benachrichtigung "
        . "in deinem Konto eingeschaltet ist.\nHier abbestellen: {link}",

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
    // Bewusst ohne Nachrichtentext: Die Kontaktmaskierung greift beim Anzeigen,
    // nicht beim Speichern — eine Mail mit dem Text würde sie umgehen.
    'mail.nachricht.betreff' => 'Neue Nachricht zu deiner Anzeige',
    'mail.nachricht.text' => "Hallo {name},\n\n"
        . "{absender} hat dir zu \"{anzeige}\" geschrieben.\n\n"
        . "Die Nachricht steht in deinem Postfach:\n{link}",
    'mail.gespraech.betreff' => 'Neue Anfrage zu deiner Anzeige',
    'mail.gespraech.text' => "Hallo {name},\n\n"
        . "{absender} hat ein Gespräch zu \"{anzeige}\" begonnen.\n\n"
        . "Hier geht es weiter:\n{link}",
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
    'inhalt.blaettern' => 'Seiten',
    'inhalt.feed' => 'Beiträge als RSS-Feed abonnieren',
    'inhalt.hauptmenu' => 'Hauptmenü',
    'inhalt.kategorien' => 'Kategorien',
    'inhalt.keine_beitraege' => 'Hier steht noch nichts.',
    'inhalt.keine_treffer' => 'Dazu haben wir nichts gefunden.',
    'inhalt.news_beschreibung' => 'Nachrichten, Hinweise und Wissenswertes rund um die Terraristik.',
    'inhalt.news_suche' => 'In den Beiträgen suchen',
    'inhalt.news_titel' => 'Beiträge',
    'inhalt.suchen' => 'Suchen',
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
    'admin.inhalt.feld_kategorien' => 'Kategorien',
    'admin.inhalt.feld_schlagwoerter' => 'Schlagwörter',
    'admin.inhalt.kategorien_hilfe' => 'Mit Komma trennen. Neue Kategorien entstehen beim Speichern; jede bekommt ein Archiv unter /news/kategorie/.',
    'admin.inhalt.schlagwoerter_hilfe' => 'Mit Komma trennen. Schlagwörter ordnen nur — sie bekommen kein eigenes Archiv.',
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

    // ------------------------------------------------------------- Mediathek
    'admin.medien.alt_vorschlag' => 'Vorschlag für die Bildbeschreibung',
    'admin.medien.anzahl.eins' => '1 Bild',
    'admin.medien.anzahl.viele' => '{anzahl} Bilder',
    'admin.medien.beschrieben' => 'Gespeichert.',
    'admin.medien.blaettern' => 'Seiten der Mediathek',
    'admin.medien.endgueltig_loeschen' => 'Endgültig löschen',
    'admin.medien.erklaerung' => 'Bilder für Seiten und Beiträge. Sie werden neu gezeichnet und als WebP abgelegt — Aufnahmeort und Kameradaten überleben das nicht.',
    'admin.medien.geloescht' => 'Das Bild ist gelöscht.',
    'admin.medien.hochgeladen' => 'Das Bild liegt in der Mediathek (#{nummer}).',
    'admin.medien.hochladen' => 'Bild hochladen',
    'admin.medien.keine_datei' => 'Es wurde keine Datei ausgewählt.',
    'admin.medien.keine_verwendung' => 'Dieses Bild steht zurzeit nirgends.',
    'admin.medien.leer' => 'Die Mediathek ist leer.',
    'admin.medien.loeschen_titel' => 'Bild löschen',
    'admin.medien.senden' => 'Hochladen',
    'admin.medien.steht_noch.eins' => 'Dieses Bild steht noch an 1 Stelle:',
    'admin.medien.steht_noch.viele' => 'Dieses Bild steht noch an {anzahl} Stellen:',
    'admin.medien.suche' => 'Suche',
    'admin.medien.titel' => 'Mediathek',
    'admin.medien.upload_hilfe' => 'JPEG, PNG oder WebP, höchstens 12 MB. Die Bildbeschreibung wird erst beim Einbinden verlangt — dort weiß man, wofür das Bild steht.',
    'admin.medien.verwendungen.eins' => 'An 1 Stelle verwendet',
    'admin.medien.verwendungen.viele' => 'An {anzahl} Stellen verwendet',
    'admin.medien.zuerst_herausnehmen' => 'Nimm es dort zuerst heraus. Danach lässt es sich löschen.',

    // ----------------------------------------------- Menues und Weiterleitungen
    'admin.menue.erklaerung' => 'Was in Kopf- und Fußbereich steht. Impressum und Datenschutz stehen fest verdrahtet dort — sie dürfen nicht davon abhängen, dass jemand ein Menü pflegt.',
    'admin.menue.geloescht' => 'Der Menüeintrag ist entfernt.',
    'admin.menue.gespeichert' => 'Der Menüeintrag ist gespeichert.',
    'admin.menue.hinzufuegen' => 'Eintrag hinzufügen',
    'admin.menue.leer' => 'Dieses Menü ist leer.',
    'admin.menue.neu' => 'Neuer Eintrag',
    'admin.menue.spalte_beschriftung' => 'Beschriftung',
    'admin.menue.spalte_reihenfolge' => 'Reihenfolge',
    'admin.menue.spalte_sichtbar' => 'Sichtbar für',
    'admin.menue.spalte_ziel' => 'Ziel',
    'admin.menue.titel' => 'Menüs',
    'admin.menue.unbekannt' => 'Dieses Menü gibt es nicht.',
    'admin.menue.unvollstaendig' => 'Beschriftung und Ziel sind Pflicht.',
    'admin.menue.vorhandene_inhalte' => 'Vorhandene Inhalte und ihre Nummern',
    'admin.menue.zielwert' => 'Ziel',
    'admin.menue.zielwert_hilfe' => 'Bei „Inhalt“ die Nummer (z. B. 12), bei „Seite der Anwendung“ der Pfad (/markt/), bei „Fremde Adresse“ die vollständige URL.',
    'admin.menue.zieltyp' => 'Art des Ziels',
    'admin.weiterleitung.angelegt' => 'Die Weiterleitung steht.',
    'admin.weiterleitung.anlegen' => 'Anlegen',
    'admin.weiterleitung.automatisch' => 'automatisch',
    'admin.weiterleitung.code' => 'Code',
    'admin.weiterleitung.dauerhaft' => 'dauerhaft',
    'admin.weiterleitung.erklaerung' => 'Ändert sich der Slug einer veröffentlichten Seite, entsteht die 301 von selbst. Hier lassen sich weitere anlegen und alte entfernen.',
    'admin.weiterleitung.geloescht' => 'Die Weiterleitung ist entfernt.',
    'admin.weiterleitung.herkunft' => 'Herkunft',
    'admin.weiterleitung.leer' => 'Es gibt noch keine Weiterleitungen.',
    'admin.weiterleitung.nach' => 'Nach',
    'admin.weiterleitung.schleifen' => 'Diese Weiterleitungen führen im Kreis und müssen von Hand aufgelöst werden:',
    'admin.weiterleitung.titel' => 'Weiterleitungen',
    'admin.weiterleitung.treffer' => 'Treffer',
    'admin.weiterleitung.von' => 'Von',
    'admin.weiterleitung.von_hand' => 'von Hand',
    'admin.weiterleitung.voruebergehend' => 'vorübergehend',

    // ------------------------------------------------------------ Rechtsseiten
    'admin.recht.abschnitte' => 'Abschnitte der Rechtsseiten',
    'admin.recht.abschnitte_erklaerung' => 'Der Fließtext von Impressum, Datenschutzerklärung und Nutzungsbedingungen. Die strukturierten Angaben stehen unter „Rechtliche Angaben".',
    'admin.recht.abschnitt_geprueft' => 'Der Abschnitt gilt als geprüft. Das Datum steht jetzt daneben.',
    'admin.recht.abschnitt_gespeichert' => 'Der Abschnitt ist gespeichert und gilt als geprüft.',
    'admin.recht.abschnitt_zurueckgesetzt' => 'Der Abschnitt steht wieder auf dem ausgelieferten Stand.',
    'admin.recht.als_geprueft' => 'Als geprüft markieren',
    'admin.recht.ausgeliefert' => 'Ausgeliefert:',
    'admin.recht.erklaerung' => 'Anbieter, Kontakt und Hosting — die Angaben, die auf Impressum und Datenschutzerklärung erscheinen.',
    'admin.recht.feld_fundstelle' => 'Fundstelle (optional)',
    'admin.recht.feld_text' => 'Text (Markdown)',
    'admin.recht.feld_ueberschrift' => 'Überschrift',
    'admin.recht.fehlende_angaben' => 'Diese Pflichtangaben fehlen noch:',
    'admin.recht.geaendert' => 'vom ausgelieferten Stand abweichend',
    'admin.recht.geaenderte_felder.eins' => '1 Feld weicht vom ausgelieferten Stand ab',
    'admin.recht.geaenderte_felder.viele' => '{anzahl} Felder weichen vom ausgelieferten Stand ab',
    'admin.recht.geprueft_am' => 'geprüft am {datum}',
    'admin.recht.gespeichert' => 'Gespeichert — {anzahl} Angabe(n) geändert.',
    'admin.recht.gruppe_schalter' => 'Schalter',
    'admin.recht.hinweis_datei' => 'Geändert wird eine Überschreibung — der ausgelieferte Stand bleibt in config/impressum.php stehen und ist je Feld wiederherstellbar. Was hier steht, ist keine Rechtsberatung; ob die Angaben genügen, gehört vor dem Start anwaltlich geprüft.',
    'admin.recht.keine_abschnitte' => 'Zu dieser Seite gibt es keine gepflegten Abschnitte. Lege sie über bin/seed.php an.',
    'admin.recht.nie_geprueft' => 'nie geprüft',
    'admin.recht.platzhalter_hilfe' => 'Markdown ist erlaubt: ## Überschrift, **fett**, - Liste, [Text](/ziel/). Platzhalter wie {hoster} oder {datenschutz_aufsicht} setzen die Angaben aus den Stammdaten ein — sie dürfen nicht entfernt werden.',
    'admin.recht.titel' => 'Rechtliche Angaben',
    'admin.recht.unveraendert' => 'Es hat sich nichts geändert.',
    'admin.recht.vollstaendig' => 'Alle Pflichtangaben stehen. Der Warnhinweis auf den Rechtsseiten ist abgeschaltet.',
    'admin.recht.zurueckgesetzt' => 'Das Feld steht wieder auf dem ausgelieferten Stand.',
    'admin.recht.zuruecksetzen' => 'Zurücksetzen',
];
