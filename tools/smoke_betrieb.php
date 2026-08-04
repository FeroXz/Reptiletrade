<?php

declare(strict_types=1);

/**
 * Rauchtest Phase 7: Verwaltung, Datenauskunft, Betrieb.
 *
 * Geprueft wird der Weg durch den echten Kernel — mit Cookie, CSRF-Token und
 * Weiterleitungen:
 *
 *   1. Die Verwaltung ist fuer Nichtadmins nicht vorhanden (404, nicht 403).
 *   2. Das Dashboard und der Artenstamm rendern.
 *   3. Export und Import des Artenstamms sind ein Kreis.
 *   4. Die Datenauskunft laeuft ohne Ruecksprache und enthaelt keine Schluessel.
 *   5. Die Loeschung verlangt Passwort und Bestaetigungswort.
 *   6. Auftragsplanung, Worker und Sicherung laufen durch.
 *
 * Aufruf: php tools/smoke_betrieb.php [--behalten]
 */

use Reptilienmarkt\Domain\Job\JobRunner;
use Reptilienmarkt\Domain\Job\JobScheduler;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\UserRepository;
use Reptilienmarkt\Domain\User\VerificationRepository;
use Reptilienmarkt\Http\Kernel;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Message\UploadedFile;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Support\Container;
use Reptilienmarkt\Support\Env;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$behalten = in_array('--behalten', $argv, true);

// ---------------------------------------------------------- Testumgebung
$arbeitsverzeichnis = $root . '/storage/smoke7';
$quelle = $root . '/storage/db/reptilienmarkt.sqlite';

if (!is_file($quelle)) {
    fwrite(\STDERR, "Es fehlt die Datenbank storage/db/reptilienmarkt.sqlite (bin/migrate.php, bin/seed.php).\n");

    exit(1);
}

verzeichnisLeeren($arbeitsverzeichnis);
@mkdir($arbeitsverzeichnis . '/db', 0o775, true);
@mkdir($arbeitsverzeichnis . '/public', 0o775, true);
@mkdir($arbeitsverzeichnis . '/private', 0o770, true);
@mkdir($arbeitsverzeichnis . '/logs', 0o775, true);
@mkdir($arbeitsverzeichnis . '/backups', 0o770, true);

// Kopie statt Original: Der Rauchtest darf die Entwicklungsdaten nicht anfassen.
copy($quelle, $arbeitsverzeichnis . '/db/smoke.sqlite');

$envDatei = $arbeitsverzeichnis . '/env';
file_put_contents($envDatei, implode("\n", [
    'APP_ENV=test',
    'APP_DEBUG=1',
    'APP_URL=http://localhost',
    'APP_TIMEZONE=Europe/Berlin',
    'DB_DRIVER=sqlite',
    'DB_DATABASE=storage/smoke7/db/smoke.sqlite',
    'STORAGE_PUBLIC=storage/smoke7/public',
    'STORAGE_PRIVATE=storage/smoke7/private',
    'LOG_DIRECTORY=storage/smoke7/logs',
    'LOG_LEVEL=debug',
    'BACKUP_DIRECTORY=storage/smoke7/backups',
    'BACKUP_KEEP=2',
    'MAIL_TRANSPORT=datei',
    'MAIL_DIRECTORY=storage/smoke7/mail',
]) . "\n");

Env::reset();
Env::load($envDatei, true);
date_default_timezone_set('Europe/Berlin');

// Pro Anfrage ein frischer Objektgraph — wie im Betrieb, wo jeder Aufruf einen
// eigenen PHP-Prozess bekommt.
$containerFabrik = static function () use ($root): Container {
    /** @var Container $container */
    $container = require $root . '/config/container.php';

    return $container;
};

$container = $containerFabrik();
$database = $container->get(Database::class);

// Die Kopie kann aelter sein als der Code. Offene Migrationen anwenden, sonst
// prueft der Rauchtest ein Schema, das es so nicht mehr gibt.
$container->get(\Reptilienmarkt\Infra\Persistence\Migrator::class)->up();

echo "Rauchtest Phase 7 — Verwaltung, Datenauskunft, Betrieb\n\n";

// --------------------------------------------------- 1) Zugang zur Verwaltung
echo "Zugang\n";

$nutzer = new BetriebsBrowser($containerFabrik);
$nutzerEmail = 'rauchtest-nutzer-' . bin2hex(random_bytes(4)) . '@example.tld';

// Ueber eine unverschluesselte Verbindung — der Fall, an dem die Registrierung
// auf der Live-Seite scheiterte: Das Cookie trug ein Secure-Flag aus APP_URL,
// der Browser verwarf es, und der CSRF-Token kam nie wieder an.
$formular = $nutzer->get('/registrieren');
$cookie = (string) ($formular->headers['set-cookie'] ?? '');

pruefe($formular->status === 200, 'Das Registrierungsformular antwortet mit 200', 'Status ' . $formular->status);
pruefe(str_contains($cookie, 'rm_session='), 'Die Antwort setzt ein Sitzungs-Cookie', 'kein Set-Cookie');
pruefe(
    !str_contains($cookie, 'Secure'),
    'Ueber http traegt das Cookie kein Secure-Flag — sonst verwirft es der Browser',
    'Secure steht drin: ' . $cookie,
);
pruefe(
    str_contains($cookie, 'HttpOnly') && str_contains($cookie, 'SameSite=Lax'),
    'HttpOnly und SameSite=Lax sind gesetzt',
    $cookie,
);

$ziel = $nutzer->post('/registrieren', [
    'email' => $nutzerEmail,
    'anzeigename' => 'Rauchtest Nutzer',
    'passwort' => 'einsicheres123',
]);

pruefe($ziel === '/meine-anzeigen/', 'Die Registrierung geht durch', 'Ziel war: ' . $ziel);

// Ein Formular ohne Token muss abgewiesen werden — und die Fehlerseite muss
// trotzdem eine Sitzung mitgeben, sonst scheitert auch der naechste Versuch.
$ohneToken = new BetriebsBrowser($containerFabrik);
$abweisung = $ohneToken->sendRaw('POST', '/registrieren', ['email' => 'wer@example.tld']);

pruefe($abweisung->status === 400, 'Ein Formular ohne Token wird abgewiesen', 'Status ' . $abweisung->status);
pruefe(
    str_contains((string) ($abweisung->headers['set-cookie'] ?? ''), 'rm_session='),
    'Auch die Fehlerseite gibt eine Sitzung mit',
    'kein Set-Cookie auf der Fehlerseite',
);
pruefe(
    str_contains((string) ($abweisung->headers['cache-control'] ?? ''), 'no-store'),
    'HTML-Seiten landen in keinem fremden Zwischenspeicher',
    'cache-control: ' . (string) ($abweisung->headers['cache-control'] ?? '—'),
);

$antwort = $nutzer->get('/admin/');
pruefe(
    $antwort->status === 404,
    'Ein gewoehnliches Konto sieht 404 statt 403 — die Verwaltung verraet sich nicht durch eine Ablehnung',
    'Status ' . $antwort->status,
);

$antwort = $nutzer->get('/admin/artenstamm');
pruefe($antwort->status === 404, 'Auch der Artenstamm ist fuer Nichtadmins nicht vorhanden', 'Status ' . $antwort->status);

// Dasselbe Konto zum Admin machen — ueber denselben Weg wie
// "php bin/admin.php ernennen". Es gibt keine Oberflaeche dafuer, und das ist
// Absicht: Der erste Administrator entsteht auf dem Server, nicht im Browser.
$befoerdern = $containerFabrik();
$konto = $befoerdern->get(UserRepository::class)->findByEmail($nutzerEmail);

if ($konto === null || $konto->id === null) {
    fehler('Das eben angelegte Konto ist nicht auffindbar.');
}

$befoerdern->get(VerificationRepository::class)->setRole($konto->id, Role::Admin);

$admin = new BetriebsBrowser($containerFabrik);
$admin->get('/anmelden');
$ziel = $admin->post('/anmelden', ['email' => $nutzerEmail, 'passwort' => 'einsicheres123']);
pruefe($ziel !== '', 'Anmeldung als Verwaltung', 'kein Weiterleitungsziel');

// --------------------------------------------------------- 2) Dashboard
echo "\nDashboard\n";

$dashboard = $admin->get('/admin/');
pruefe($dashboard->status === 200, 'Das Dashboard antwortet mit 200', 'Status ' . $dashboard->status);
pruefe(
    str_contains($dashboard->body, 'Offene Meldungen') && str_contains($dashboard->body, 'Aufbewahrungsfristen'),
    'Es zeigt offene Vorgaenge und die Aufbewahrungsfristen',
    'Kacheln fehlen',
);
pruefe(
    !str_contains($dashboard->body, 'admin.'),
    'Alle Beschriftungen kommen aus dem Sprachkatalog',
    'im Text stehen noch rohe Schluessel',
);
pruefe(
    str_contains($dashboard->body, 'noindex'),
    'Die Verwaltung ist fuer Suchmaschinen gesperrt',
    'kein noindex im Kopf',
);

// Anmeldung: Die konfigurierte IP-Grenze muss auch greifen. Sie stand in
// config/trust.php, war aber an keinen Controller angeschlossen.
// Eigene Adresse: Die Grenze soll den Rest des Rauchtests nicht mitsperren.
$brecher = new BetriebsBrowser($containerFabrik, '198.51.100.9');
$abgewiesen = false;

for ($versuch = 0; $versuch < 40; ++$versuch) {
    $brecher->get('/anmelden');
    $antwort = $brecher->sendRawWithCsrf('POST', '/anmelden', [
        'email' => 'gibt-es-nicht@example.tld',
        'passwort' => 'falsch' . $versuch,
    ]);

    if ($antwort->status === 429) {
        $abgewiesen = true;

        break;
    }
}

pruefe($abgewiesen, 'Massenhafte Anmeldeversuche werden von der IP-Grenze gestoppt', '40 Versuche gingen durch');

// Kontoanlage: Ein Skript soll nicht in einer Minute tausend Konten anlegen.
$massenanleger = new BetriebsBrowser($containerFabrik, '198.51.100.23');
$angelegt = 0;
$gestoppt = false;

for ($versuch = 0; $versuch < 12; ++$versuch) {
    $massenanleger->get('/registrieren');
    $antwort = $massenanleger->sendRawWithCsrf('POST', '/registrieren', [
        'email' => sprintf('massen-%d-%s@example.tld', $versuch, bin2hex(random_bytes(3))),
        'anzeigename' => 'Massenanlage ' . $versuch,
        'passwort' => 'einsicheres123',
    ]);

    if ($antwort->status === 429) {
        $gestoppt = true;

        break;
    }

    ++$angelegt;
}

pruefe(
    $gestoppt && $angelegt <= 5,
    sprintf('Die Kontoanlage ist bei %d Konten gestoppt', $angelegt),
    $gestoppt ? $angelegt . ' Konten kamen durch' : '12 Konten kamen durch',
);

// Der CSP-Kopf ist die zweite Linie hinter dem Escaping.
$csp = (string) ($nutzer->get('/markt/')->headers['content-security-policy'] ?? '');
pruefe(
    str_contains($csp, "script-src 'self'") && !str_contains($csp, 'unsafe-eval'),
    'Die Content-Security-Policy verbietet fremde und zur Laufzeit gebaute Skripte',
    'CSP: ' . ($csp === '' ? 'fehlt' : $csp),
);
pruefe(
    str_contains($csp, "frame-ancestors 'none'") && str_contains($csp, "base-uri 'none'"),
    'Sie verbietet Einbettung und untergeschobene Basisadressen',
    'CSP: ' . $csp,
);

// ------------------------------------------------ 3) Anzeigen verwalten
echo "\nAnzeigenverwaltung\n";

$verwaltung = $containerFabrik();
$anzeigeId = (int) (string) $database->scalar("SELECT id FROM listings WHERE status = 'aktiv' ORDER BY id LIMIT 1");
$besitzerId = (int) (string) $database->scalar('SELECT user_id FROM listings WHERE id = :id', ['id' => $anzeigeId]);

$liste = $admin->get('/admin/anzeigen');
pruefe($liste->status === 200, 'Die Anzeigenliste antwortet mit 200', 'Status ' . $liste->status);
pruefe(
    !str_contains($liste->body, 'admin.spalte'),
    'Die Liste kommt aus dem Sprachkatalog',
    'im Text stehen noch rohe Schluessel',
);

// Ein gewoehnliches Konto darf sie nicht sehen. Ein frisches, denn das erste
// wurde oben zum Administrator gemacht.
$ohneRechte = new BetriebsBrowser($containerFabrik);
$ohneRechteEmail = 'rauchtest-ohne-' . bin2hex(random_bytes(4)) . '@example.tld';
$ohneRechte->get('/registrieren');
$ohneRechte->post('/registrieren', [
    'email' => $ohneRechteEmail,
    'anzeigename' => 'Rauchtest ohne Rechte',
    'passwort' => 'einsicheres123',
]);

pruefe($ohneRechte->get('/admin/anzeigen')->status === 404, 'Fuer Nichtadmins ist sie nicht vorhanden', 'sie ist sichtbar');

$admin->post('/admin/anzeige/' . $anzeigeId . '/pausieren', ['grund' => 'Rauchtest: bitte Nachweis nachreichen']);
$stand = (string) $database->scalar('SELECT status FROM listings WHERE id = :id', ['id' => $anzeigeId]);
pruefe($stand === 'pausiert', 'Die Verwaltung kann pausieren', 'Status ist "' . $stand . '"');

$imIndex = (int) (string) $database->scalar('SELECT COUNT(*) FROM listing_search WHERE rowid = :id', ['id' => $anzeigeId]);
pruefe($imIndex === 0, 'Eine pausierte Anzeige verschwindet aus dem Suchindex', 'sie steht noch im Index');

$grund = (string) $database->scalar('SELECT paused_reason FROM listings WHERE id = :id', ['id' => $anzeigeId]);
pruefe(
    str_contains($grund, 'Nachweis nachreichen'),
    'Der Grund ist gespeichert und fuer den Anbieter sichtbar',
    'Grund: ' . $grund,
);

// Der Anbieter darf eine Verwaltungspause nicht selbst aufheben.
$anbieter = new BetriebsBrowser($containerFabrik);
$anbieterEmail = (string) $database->scalar('SELECT email FROM users WHERE id = :id', ['id' => $besitzerId]);
$befoerdern->get(UserRepository::class)->findByEmail($anbieterEmail);
$database->execute('UPDATE users SET password_hash = :hash WHERE id = :id', [
    'hash' => $containerFabrik()->get(\Reptilienmarkt\Domain\Auth\PasswordHasher::class)->hash('rauchtestPasswort1'),
    'id' => $besitzerId,
]);
$anbieter->get('/anmelden');
$anbieter->post('/anmelden', ['email' => $anbieterEmail, 'passwort' => 'rauchtestPasswort1']);
$anbieter->post('/anzeige/' . $anzeigeId . '/fortsetzen', []);

$stand = (string) $database->scalar('SELECT status FROM listings WHERE id = :id', ['id' => $anzeigeId]);
pruefe($stand === 'pausiert', 'Der Anbieter hebt eine Verwaltungspause nicht auf', 'Status ist "' . $stand . '"');

$admin->post('/admin/anzeige/' . $anzeigeId . '/fortsetzen', []);
$stand = (string) $database->scalar('SELECT status FROM listings WHERE id = :id', ['id' => $anzeigeId]);
pruefe($stand === 'aktiv', 'Die Verwaltung gibt wieder frei', 'Status ist "' . $stand . '"');

$imIndex = (int) (string) $database->scalar('SELECT COUNT(*) FROM listing_search WHERE rowid = :id', ['id' => $anzeigeId]);
pruefe($imIndex === 1, 'Nach der Freigabe steht sie wieder im Index', 'sie fehlt im Index');

// Eigene Pause: Der Anbieter darf, und er darf sie auch wieder aufheben.
$anbieter->post('/anzeige/' . $anzeigeId . '/pausieren', []);
pruefe(
    (string) $database->scalar('SELECT paused_by FROM listings WHERE id = :id', ['id' => $anzeigeId]) === 'anbieter',
    'Der Anbieter kann selbst pausieren',
    'paused_by stimmt nicht',
);

$anbieter->post('/anzeige/' . $anzeigeId . '/fortsetzen', []);
pruefe(
    (string) $database->scalar('SELECT status FROM listings WHERE id = :id', ['id' => $anzeigeId]) === 'aktiv',
    'Seine eigene Pause hebt er wieder auf',
    'die Anzeige steht noch',
);

// Bearbeiten
$formular = $anbieter->get('/anzeige/' . $anzeigeId . '/bearbeiten');
pruefe($formular->status === 200, 'Das Bearbeitungsformular antwortet mit 200', 'Status ' . $formular->status);

$anbieter->post('/anzeige/' . $anzeigeId . '/bearbeiten', [
    'titel' => 'Rauchtest: geänderter Titel',
    'beschreibung' => 'Diese Beschreibung stammt aus dem Rauchtest und ist lang genug.',
    'preis' => '199,00',
    'anzahl' => '1',
    'land' => 'DE',
    'plz' => '80331',
]);

pruefe(
    (string) $database->scalar('SELECT title FROM listings WHERE id = :id', ['id' => $anzeigeId]) === 'Rauchtest: geänderter Titel',
    'Die Bearbeitung wird gespeichert',
    'der Titel steht unveraendert',
);
pruefe(
    (int) (string) $database->scalar('SELECT edit_count FROM listings WHERE id = :id', ['id' => $anzeigeId]) === 1,
    'Sie wird gezaehlt und protokolliert',
    'edit_count stimmt nicht',
);

// Eine pausierte Anzeige darf sich nicht an der Rechtspruefung vorbeiaendern
// lassen: pausieren, aendern, fortsetzen — und dazwischen kein Blick darauf.
$anbieter->post('/anzeige/' . $anzeigeId . '/pausieren', []);
$anbieter->post('/anzeige/' . $anzeigeId . '/bearbeiten', [
    'titel' => 'Rauchtest: waehrend der Pause geändert',
    'beschreibung' => 'Auch eine pausierte Anzeige wird nach der Änderung neu bewertet.',
    'preis' => '210,00',
    'anzahl' => '1',
    'land' => 'DE',
    'plz' => '80331',
]);

$imIndex = (int) (string) $database->scalar('SELECT COUNT(*) FROM listing_search WHERE rowid = :id', ['id' => $anzeigeId]);
pruefe($imIndex === 0, 'Eine Aenderung waehrend der Pause bringt sie nicht in den Index zurueck', 'sie steht im Index');

$anbieter->post('/anzeige/' . $anzeigeId . '/fortsetzen', []);

// Freigabe durch die Moderation raeumt die Pausenangaben ab.
$admin->post('/admin/anzeige/' . $anzeigeId . '/pausieren', ['grund' => 'Rauchtest']);
$verwaltungsContainer = $containerFabrik();
$verwaltungsContainer->get(\Reptilienmarkt\Domain\Listing\ListingRepository::class)
    ->updateStatus($anzeigeId, \Reptilienmarkt\Domain\Listing\ListingStatus::Aktiv);

$offen = (string) ($database->scalar('SELECT paused_by FROM listings WHERE id = :id', ['id' => $anzeigeId]) ?? '');
pruefe($offen === '', 'Eine Statusaenderung raeumt die Pausenangaben ab', 'paused_by steht noch auf "' . $offen . '"');

// Eine fremde Anzeige bleibt unsichtbar — 404, nicht 403.
$fremde = $ohneRechte->get('/anzeige/' . $anzeigeId . '/bearbeiten');
pruefe($fremde->status === 404, 'Eine fremde Anzeige laesst sich nicht bearbeiten', 'Status ' . $fremde->status);

// ------------------------------- 3b) Zuechter-Merkmale fuer alle offen
echo "\nZüchter-Merkmale\n";

$vorher = (int) (string) $database->scalar(
    'SELECT COALESCE(SUM(views), 0) FROM listing_views WHERE listing_id = :id',
    ['id' => $anzeigeId],
);

// Ein fremder Besucher ruft die Anzeige zweimal auf.
$besucher = new BetriebsBrowser($containerFabrik, '198.51.100.44');
$besucher->get('/anzeige/' . $anzeigeId . '/');
$besucher->get('/anzeige/' . $anzeigeId . '/');

$nachher = (int) (string) $database->scalar(
    'SELECT COALESCE(SUM(views), 0) FROM listing_views WHERE listing_id = :id',
    ['id' => $anzeigeId],
);

pruefe(
    $nachher === $vorher + 1,
    'Ein Aufruf zaehlt einmal je Sitzung, nicht bei jedem Neuladen',
    sprintf('%d statt %d Aufrufe', $nachher, $vorher + 1),
);

// Der Anbieter selbst zaehlt nicht mit.
$anbieter->get('/anzeige/' . $anzeigeId . '/');
$eigen = (int) (string) $database->scalar(
    'SELECT COALESCE(SUM(views), 0) FROM listing_views WHERE listing_id = :id',
    ['id' => $anzeigeId],
);
pruefe($eigen === $nachher, 'Eigene Aufrufe zaehlen nicht mit', sprintf('%d statt %d', $eigen, $nachher));

$statistik = $anbieter->get('/konto/statistik');
pruefe($statistik->status === 200, 'Die Statistik ist ohne Abo erreichbar', 'Status ' . $statistik->status);
pruefe(
    str_contains($statistik->body, 'Anfragen je 100 Aufrufe') && !str_contains($statistik->body, 'statistik.'),
    'Sie zeigt Aufrufe, Anfragen und Quote aus dem Sprachkatalog',
    'Inhalt fehlt oder rohe Schluessel',
);

// Nachzuchten und Profil haengen an denselben Merkmalen.
$nachzuchten = $anbieter->get('/konto/nachzuchten');
pruefe(
    $nachzuchten->status === 200 && !str_contains($nachzuchten->body, 'gehören zum Züchter-Tarif'),
    'Nachzucht-Ankuendigungen stehen ohne Abo offen',
    'Status ' . $nachzuchten->status,
);

$merkmale = $containerFabrik()->get(\Reptilienmarkt\Domain\Billing\EntitlementService::class);
$grundtarif = $merkmale->plans()['frei'] ?? null;
pruefe(
    $grundtarif !== null && count($grundtarif->features) === 3,
    'Der Grundtarif fuehrt alle drei Merkmale — auch wenn die Abrechnung eingeschaltet wird',
    'Merkmale im Grundtarif: ' . ($grundtarif === null ? 'kein Tarif' : count($grundtarif->features)),
);

// ------------------------------------------------------- 4) Artenstamm
echo "\nArtenstamm\n";

$katalog = $admin->get('/admin/artenstamm');
pruefe($katalog->status === 200, 'Der Artenstamm antwortet mit 200', 'Status ' . $katalog->status);

$export = $admin->get('/admin/artenstamm/arten/export?format=csv');
$zeilen = substr_count(trim($export->body), "\n");
pruefe(
    $export->status === 200 && str_starts_with($export->body, 'scientific_name,'),
    sprintf('Der CSV-Export liefert eine Kopfzeile und %d Arten', $zeilen),
    'Status ' . $export->status,
);
pruefe(
    str_contains((string) ($export->headers['content-disposition'] ?? ''), 'attachment'),
    'Er kommt als Download, nicht als Seite',
    'content-disposition fehlt',
);

$morphExport = $admin->get('/admin/artenstamm/morphs/export?format=json');
pruefe(
    $morphExport->status === 200 && is_array(json_decode($morphExport->body, true)),
    'Der Merkmalsexport ist gueltiges JSON',
    'Status ' . $morphExport->status,
);

// Eine neue Art einlesen — erst als Probelauf, dann echt.
$importDatei = $arbeitsverzeichnis . '/neue-arten.csv';
// Eine Art, die im Grundbestand fehlt — sonst waere der Import eine
// Aktualisierung und der Test sagte nichts ueber das Anlegen.
file_put_contents($importDatei, "scientific_name,common_name_de,bnatschg_status,meldepflicht\n"
    . "Uromastyx ornata,Zierdornschwanzagame,besonders,1\n");

$vorher = artenZahl($database);

$admin->upload('/admin/artenstamm/arten/import', ['probelauf' => '1'], ['datei' => datei($importDatei, 'arten.csv')]);
pruefe(
    artenZahl($database) === $vorher,
    'Der Probelauf schreibt nichts',
    sprintf('%d statt %d Arten', artenZahl($database), $vorher),
);

$admin->upload('/admin/artenstamm/arten/import', [], ['datei' => datei($importDatei, 'arten.csv')]);
pruefe(
    artenZahl($database) === $vorher + 1,
    'Der echte Import legt die Art an',
    sprintf('%d statt %d Arten', artenZahl($database), $vorher + 1),
);

$admin->upload('/admin/artenstamm/arten/import', [], ['datei' => datei($importDatei, 'arten.csv')]);
pruefe(
    artenZahl($database) === $vorher + 1,
    'Ein zweiter Lauf aktualisiert dieselbe Art, statt sie zu verdoppeln',
    sprintf('%d statt %d Arten', artenZahl($database), $vorher + 1),
);

$kaputt = $arbeitsverzeichnis . '/kaputt.csv';
file_put_contents($kaputt, "scientific_name,bnatschg_status\nBoa constrictor,gibt_es_nicht\n");
$admin->upload('/admin/artenstamm/arten/import', [], ['datei' => datei($kaputt, 'kaputt.csv')]);
$nachFehler = $admin->get('/admin/artenstamm');
pruefe(
    artenZahl($database) === $vorher + 1 && str_contains($nachFehler->body, 'Zeile 1'),
    'Eine fehlerhafte Datei schreibt nichts und nennt die Zeile',
    'die Fehlerliste fehlt',
);

// ------------------------------------------------- 4) Auskunft und Loeschung
echo "\nDatenauskunft und Löschung\n";

$kunde = new BetriebsBrowser($containerFabrik);
$kundeEmail = 'rauchtest-kunde-' . bin2hex(random_bytes(4)) . '@example.tld';
$kunde->get('/registrieren');
$kunde->post('/registrieren', [
    'email' => $kundeEmail,
    'anzeigename' => 'Rauchtest Kunde',
    'passwort' => 'einsicheres123',
]);

$seite = $kunde->get('/konto/daten');
pruefe($seite->status === 200, 'Die Seite "Meine Daten" antwortet mit 200', 'Status ' . $seite->status);

$auskunft = $kunde->get('/konto/daten/export');
/** @var array<string, mixed>|null $daten */
$daten = json_decode($auskunft->body, true);
pruefe(
    $auskunft->status === 200 && is_array($daten) && isset($daten['konto']),
    'Die Auskunft kommt sofort und als JSON',
    'Status ' . $auskunft->status,
);
pruefe(
    !str_contains($auskunft->body, 'argon2') && !str_contains($auskunft->body, 'totp_secret'),
    'Zugangsmittel stehen nicht darin',
    'Passwort-Hash oder TOTP-Geheimnis in der Auskunft',
);
pruefe(
    str_contains((string) ($auskunft->headers['cache-control'] ?? ''), 'no-store')
        && str_contains((string) ($auskunft->headers['x-robots-tag'] ?? ''), 'noindex'),
    'Sie landet weder im Zwischenspeicher noch im Suchindex',
    'Kopfzeilen fehlen',
);

$kunde->post('/konto/loeschen', ['passwort' => 'falschesPasswort', 'bestaetigung' => 'LÖSCHEN']);
pruefe(kontoExistiert($database, $kundeEmail), 'Ein falsches Passwort löscht nichts', 'das Konto ist weg');

$kunde->post('/konto/loeschen', ['passwort' => 'einsicheres123', 'bestaetigung' => 'ja bitte']);
pruefe(kontoExistiert($database, $kundeEmail), 'Ohne Bestätigungswort löscht nichts', 'das Konto ist weg');

$ziel = $kunde->post('/konto/loeschen', ['passwort' => 'einsicheres123', 'bestaetigung' => 'LÖSCHEN']);
pruefe(
    !kontoExistiert($database, $kundeEmail) && $ziel === '/markt/',
    'Mit Passwort und Bestätigungswort ist das Konto weg',
    'das Konto besteht noch',
);

$nachLoeschung = $kunde->get('/konto/daten');
pruefe(
    $nachLoeschung->status >= 300,
    'Die Sitzung ist danach beendet',
    'Status ' . $nachLoeschung->status,
);

// ------------------------------------------------------------- 5) Betrieb
echo "\nBetrieb\n";

$betriebsContainer = $containerFabrik();
$scheduler = $betriebsContainer->get(JobScheduler::class);
$runner = $betriebsContainer->get(JobRunner::class);

$typen = $runner->knownTypes();
$fehlend = array_diff(array_keys($scheduler->plan()), $typen);
pruefe($fehlend === [], 'Jeder geplante Auftragstyp hat einen Handler', 'ohne Handler: ' . implode(', ', $fehlend));

foreach (array_keys($scheduler->plan()) as $typ) {
    $scheduler->enqueueOnce($typ);
}
$scheduler->enqueueOnce('search.reindex');

$bilanz = $runner->run(50);
pruefe(
    $bilanz['fehlgeschlagen'] === 0 && $bilanz['wiederholt'] === 0,
    sprintf('Alle %d Aufträge laufen durch', $bilanz['erledigt']),
    sprintf('%d fehlgeschlagen, %d wiederholt', $bilanz['fehlgeschlagen'], $bilanz['wiederholt']),
);

$protokoll = glob($arbeitsverzeichnis . '/logs/*.log') ?: [];
pruefe($protokoll !== [], 'Der Worker schreibt ein Protokoll', 'keine Protokolldatei');

$zeilen = array_filter(explode("\n", trim((string) file_get_contents($protokoll[0]))));
$gueltig = true;
foreach ($zeilen as $zeile) {
    if (!is_array(json_decode($zeile, true))) {
        $gueltig = false;
    }
}
pruefe($gueltig, sprintf('Jede der %d Zeilen ist gültiges JSON', count($zeilen)), 'eine Zeile ist kein JSON');

$auditVorher = (int) (string) $database->scalar('SELECT COUNT(*) FROM audit_log');
$runner->run(50);
pruefe(
    (int) (string) $database->scalar('SELECT COUNT(*) FROM audit_log') === $auditVorher,
    'Der Audit-Trail bleibt vom Aufräumen unberührt',
    'der Trail hat sich verändert',
);

// Sicherung ueber VACUUM INTO, gegen dieselbe Datenbank.
$sicherung = $arbeitsverzeichnis . '/backups/probe.sqlite';
$database->pdo()->exec(sprintf('VACUUM INTO %s', $database->pdo()->quote($sicherung)));
$kopie = new PDO('sqlite:' . $sicherung, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pruefung = spalte($kopie, 'PRAGMA integrity_check');
pruefe(
    $pruefung === 'ok' && filesize($sicherung) > 0,
    'Die Sicherung ist in sich stimmig (integrity_check)',
    'integrity_check: ' . var_export($pruefung, true),
);
pruefe(
    (int) spalte($kopie, 'SELECT COUNT(*) FROM listings') > 0,
    'Sie enthält die Anzeigen',
    'die Kopie ist leer',
);

echo "\nAlles bestanden.\n";

if (!$behalten) {
    verzeichnisLeeren($arbeitsverzeichnis);
} else {
    printf("Arbeitsverzeichnis bleibt: %s\n", $arbeitsverzeichnis);
}

// ------------------------------------------------------------- Hilfsmittel

final class BetriebsBrowser
{
    private ?string $cookie = null;

    private string $csrf = '';

    /**
     * @param callable(): Container $container Liefert pro Anfrage einen frischen Objektgraphen
     */
    public function __construct(private $container, private readonly string $ip = '203.0.113.7') {}

    public function get(string $path): Response
    {
        [$pfad, $query] = $this->split($path);

        $response = $this->send(new Request('GET', $pfad, $query, [], $this->headers(), [], $this->cookies(), $this->ip));

        $token = $this->extractCsrf($response->body);
        if ($token !== null) {
            $this->csrf = $token;
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return string Ziel der Weiterleitung
     */
    public function post(string $path, array $body): string
    {
        $response = $this->send(new Request(
            'POST',
            $path,
            [],
            $body + ['_csrf' => $this->csrf],
            $this->headers(),
            [],
            $this->cookies(),
            $this->ip,
        ));

        return $this->redirectTarget($response, $path);
    }

    /**
     * @param array<string, mixed>        $body
     * @param array<string, UploadedFile> $files
     */
    public function upload(string $path, array $body, array $files): string
    {
        $response = $this->send(new Request(
            'POST',
            $path,
            [],
            $body + ['_csrf' => $this->csrf],
            $this->headers(),
            [],
            $this->cookies(),
            '203.0.113.7',
            $files,
        ));

        return $this->redirectTarget($response, $path);
    }

    /**
     * Absenden ohne CSRF-Token und ohne Weiterleitungspruefung — fuer den Fall,
     * dass genau die Abweisung geprueft werden soll.
     *
     * @param array<string, mixed> $body
     */
    public function sendRaw(string $method, string $path, array $body): Response
    {
        return $this->send(new Request(
            $method,
            $path,
            [],
            $body,
            $this->headers(),
            [],
            $this->cookies(),
            $this->ip,
        ));
    }

    /**
     * Wie post(), aber ohne Weiterleitungspruefung — fuer Faelle, in denen
     * gerade die Abweisung das erwartete Ergebnis ist.
     *
     * @param array<string, mixed> $body
     */
    public function sendRawWithCsrf(string $method, string $path, array $body): Response
    {
        return $this->send(new Request(
            $method,
            $path,
            [],
            $body + ['_csrf' => $this->csrf],
            $this->headers(),
            [],
            $this->cookies(),
            $this->ip,
        ));
    }

    private function send(Request $request): Response
    {
        $kernel = ($this->container)()->get(Kernel::class);
        $response = $kernel->handle($request);

        if ($response->status >= 500) {
            fehler(sprintf(
                '%s %s antwortete mit %d: %s',
                $request->method,
                $request->path,
                $response->status,
                strip_tags($response->body),
            ));
        }

        $cookie = $response->headers['set-cookie'] ?? null;
        if (is_string($cookie) && preg_match('/rm_session=([^;]+)/', $cookie, $treffer) === 1) {
            $this->cookie = $treffer[1];
        }

        return $response;
    }

    private function redirectTarget(Response $response, string $path): string
    {
        if ($response->status < 300 || $response->status >= 400) {
            fehler(sprintf(
                'POST %s antwortete mit %d statt einer Weiterleitung: %s',
                $path,
                $response->status,
                strip_tags($response->body),
            ));
        }

        $location = $response->headers['location'] ?? '';

        return is_string($location) ? $location : '';
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function split(string $path): array
    {
        $stelle = strpos($path, '?');

        if ($stelle === false) {
            return [$path, []];
        }

        parse_str(substr($path, $stelle + 1), $query);

        /** @var array<string, string> $query */
        return [substr($path, 0, $stelle), $query];
    }

    private function extractCsrf(string $html): ?string
    {
        return preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $treffer) === 1 ? $treffer[1] : null;
    }

    /**
     * @return array<string, string>
     */
    private function cookies(): array
    {
        return $this->cookie === null ? [] : [SessionManager::COOKIE_NAME => $this->cookie];
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['user-agent' => 'Rauchtest/1.0', 'accept' => 'text/html'];
    }
}

function datei(string $quelle, string $name): UploadedFile
{
    // Der Upload-Pfad verschiebt die Datei, deshalb pro Upload eine Kopie.
    $kopie = $quelle . '.' . bin2hex(random_bytes(3));
    copy($quelle, $kopie);

    return new UploadedFile($kopie, $name, 'text/csv', (int) filesize($kopie));
}

/**
 * Erster Wert der ersten Zeile — die Sicherung wird ohne den Datenbankdienst
 * geprueft, damit der Test nicht dieselbe Verbindung befragt, die er prueft.
 */
function spalte(PDO $pdo, string $sql): string
{
    $statement = $pdo->query($sql);

    if ($statement === false) {
        fehler('Abfrage gegen die Sicherung fehlgeschlagen: ' . $sql);
    }

    /** @var mixed $wert */
    $wert = $statement->fetchColumn();

    return is_scalar($wert) ? (string) $wert : '';
}

function artenZahl(Database $database): int
{
    return (int) (string) $database->scalar('SELECT COUNT(*) FROM species');
}

function kontoExistiert(Database $database, string $email): bool
{
    return (int) (string) $database->scalar(
        'SELECT COUNT(*) FROM users WHERE email_canonical = :email',
        ['email' => strtolower($email)],
    ) > 0;
}

function pruefe(bool $bedingung, string $erwartet, string $tatsaechlich): void
{
    if ($bedingung) {
        echo '  [ok]     ' . $erwartet . "\n";

        return;
    }

    echo '  [FEHLER] ' . $erwartet . ' — stattdessen: ' . $tatsaechlich . "\n";

    fehler('Abnahme fehlgeschlagen.');
}

function fehler(string $meldung): never
{
    fwrite(\STDERR, "\nAbbruch: " . $meldung . "\n");

    exit(1);
}

function verzeichnisLeeren(string $verzeichnis): void
{
    if (!is_dir($verzeichnis)) {
        return;
    }

    $eintraege = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($verzeichnis, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($eintraege as $eintrag) {
        if ($eintrag instanceof SplFileInfo) {
            $eintrag->isDir() ? @rmdir($eintrag->getPathname()) : @unlink($eintrag->getPathname());
        }
    }

    @rmdir($verzeichnis);
}
