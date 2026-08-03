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
