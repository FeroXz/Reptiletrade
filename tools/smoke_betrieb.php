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

echo "Rauchtest Phase 7 — Verwaltung, Datenauskunft, Betrieb\n\n";

// --------------------------------------------------- 1) Zugang zur Verwaltung
echo "Zugang\n";

$nutzer = new SmokeBrowser($containerFabrik);
$nutzerEmail = 'rauchtest-nutzer-' . bin2hex(random_bytes(4)) . '@example.tld';
$nutzer->get('/registrieren');
$nutzer->post('/registrieren', [
    'email' => $nutzerEmail,
    'anzeigename' => 'Rauchtest Nutzer',
    'passwort' => 'einsicheres123',
]);

$antwort = $nutzer->get('/admin/');
pruefe(
    $antwort->status === 404,
    'Ein gewoehnliches Konto sieht 404 statt 403 — die Verwaltung verraet sich nicht durch eine Ablehnung',
    'Status ' . $antwort->status,
);

$antwort = $nutzer->get('/admin/artenstamm');
pruefe($antwort->status === 404, 'Auch der Artenstamm ist fuer Nichtadmins nicht vorhanden', 'Status ' . $antwort->status);

// Dasselbe Konto zum Admin machen — der Weg dorthin fuehrt ueber die Datenbank,
// nicht ueber eine Oberflaeche. Genau so soll es sein.
$database->execute("UPDATE users SET role = 'admin' WHERE email_canonical = :email", ['email' => strtolower($nutzerEmail)]);

$admin = new SmokeBrowser($containerFabrik);
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

// ------------------------------------------------------- 3) Artenstamm
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

$kunde = new SmokeBrowser($containerFabrik);
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

final class SmokeBrowser
{
    private ?string $cookie = null;

    private string $csrf = '';

    /**
     * @param callable(): Container $container Liefert pro Anfrage einen frischen Objektgraphen
     */
    public function __construct(private $container) {}

    public function get(string $path): Response
    {
        [$pfad, $query] = $this->split($path);

        $response = $this->send(new Request('GET', $pfad, $query, [], $this->headers(), [], $this->cookies(), '203.0.113.7'));

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
            '203.0.113.7',
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
