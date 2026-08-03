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
];
