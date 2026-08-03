#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Selbsttest der Installation.
 *
 * Prueft die Dinge, die im Browser nur als "irgendwas geht nicht" ankommen:
 * fehlende Erweiterungen, nicht beschreibbare Verzeichnisse, eine .env, die
 * noch auf example.tld zeigt, ein stehengebliebener Worker.
 *
 * Der Anlass war ein Fehler auf einer echten Installation: Die Registrierung
 * endete immer mit "Das Formular ist abgelaufen", weil das Sitzungs-Cookie
 * nicht ankam. Solche Fehler soll ein Aufruf beantworten, nicht eine Suche.
 *
 *   php bin/doctor.php
 *
 * Rueckgabewert 0 = alles in Ordnung, 1 = mindestens ein Fehler.
 */

use Reptilienmarkt\Domain\Auth\Session;
use Reptilienmarkt\Domain\Auth\SessionRepository;
use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Support\Container;
use Reptilienmarkt\Support\Env;

if (\PHP_SAPI !== 'cli') {
    exit("bin/doctor.php laeuft nur auf der Kommandozeile.\n");
}

$root = dirname(__DIR__);

/** @var Container $container */
$container = require $root . '/config/bootstrap.php';

/**
 * Sammelt die Befunde und zaehlt mit. Ein Objekt statt globaler Zaehler, damit
 * der Rueckgabewert am Ende nachweislich zum Ausgegebenen passt.
 */
final class Befund
{
    public int $fehler = 0;

    public int $warnungen = 0;

    public function ok(string $text, string $hinweis = ''): void
    {
        printf("  %s      %s%s\n", $this->farbe('32', '[ok]'), $text, $hinweis === '' ? '' : ' — ' . $hinweis);
    }

    public function warnung(string $text, string $hinweis): void
    {
        ++$this->warnungen;
        printf("  %s %s\n            %s\n", $this->farbe('33', '[Hinweis]'), $text, $hinweis);
    }

    public function problem(string $text, string $hinweis): void
    {
        ++$this->fehler;
        printf("  %s  %s\n            %s\n", $this->farbe('31', '[FEHLER]'), $text, $hinweis);
    }

    public function abschnitt(string $titel): void
    {
        printf("\n%s\n", $titel);
    }

    /**
     * Farbe nur am Terminal. Wer die Ausgabe in eine Datei schreibt oder an ein
     * Ticket haengt, will keine Steuerzeichen darin.
     */
    private function farbe(string $code, string $text): string
    {
        return stream_isatty(\STDOUT) ? "\033[" . $code . 'm' . $text . "\033[0m" : $text;
    }
}

$befund = new Befund();

echo "Selbsttest der Installation\n";

// ------------------------------------------------------------------ PHP
$befund->abschnitt('PHP');

version_compare(\PHP_VERSION, '8.3', '>=')
    ? $befund->ok('PHP ' . \PHP_VERSION . ' (' . \PHP_SAPI . ')')
    : $befund->problem('PHP ' . \PHP_VERSION . ' ist zu alt', 'Gebraucht wird 8.3 oder neuer.');

foreach (['pdo_sqlite', 'mbstring', 'json', 'zlib', 'gd'] as $erweiterung) {
    if (extension_loaded($erweiterung)) {
        $befund->ok('Erweiterung ' . $erweiterung);

        continue;
    }

    $befund->problem(
        'Erweiterung ' . $erweiterung . ' fehlt',
        $erweiterung === 'gd'
            ? 'Ohne GD schlaegt jeder Bild-Upload fehl: sudo apt install php8.3-gd'
            : 'sudo apt install php8.3-' . str_replace('pdo_', '', $erweiterung),
    );
}

$upload = (int) ini_parse_quantity((string) ini_get('upload_max_filesize'));
$post = (int) ini_parse_quantity((string) ini_get('post_max_size'));

$post > $upload
    ? $befund->ok(sprintf('Upload-Grenzen (%s / %s)', ini_get('upload_max_filesize'), ini_get('post_max_size')))
    : $befund->problem(
        sprintf('post_max_size (%s) ist nicht groesser als upload_max_filesize (%s)', ini_get('post_max_size'), ini_get('upload_max_filesize')),
        'PHP schneidet den Upload dann stillschweigend ab — das Formular kommt leer an.',
    );

// --------------------------------------------------------------- SQLite
$befund->abschnitt('SQLite');

try {
    $probe = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $version = $probe->query('SELECT sqlite_version()');
    $befund->ok('SQLite ' . ($version === false ? 'unbekannt' : (string) $version->fetchColumn()));

    foreach ([
        'FTS5 (Volltextsuche)' => 'CREATE VIRTUAL TABLE t USING fts5(a)',
        'JSON1 (Pruefregeln)' => "SELECT json_valid('{}')",
        'Mathematik (Umkreissuche)' => 'SELECT cos(1)',
    ] as $name => $sql) {
        try {
            $probe->exec($sql);
            $befund->ok($name);
        } catch (Throwable) {
            $befund->problem($name . ' fehlt', 'Ein anderes PHP-SQLite-Paket noetig; nachruesten geht zur Laufzeit nicht.');
        }
    }
} catch (Throwable $exception) {
    $befund->problem('SQLite nicht ansprechbar', $exception->getMessage());
}

// ----------------------------------------------------------- .env-Datei
$befund->abschnitt('Konfiguration');

is_file($root . '/.env')
    ? $befund->ok('.env vorhanden')
    : $befund->problem('.env fehlt', 'cp .env.example .env und die Werte anpassen.');

$appUrl = Env::string('APP_URL', '');
$umgebung = Env::string('APP_ENV', 'production');

if ($appUrl === '' || str_contains($appUrl, 'example.tld')) {
    $befund->problem(
        'APP_URL steht noch auf dem Beispielwert',
        'Jeder Bestaetigungs- und Ruecksetzlink wird daraus gebaut. So laufen sie alle ins Leere.',
    );
} elseif (str_ends_with($appUrl, '/')) {
    $befund->warnung('APP_URL endet auf einen Schraegstrich', 'Links bekommen dadurch einen doppelten: ' . $appUrl . '/markt/');
} else {
    $befund->ok('APP_URL: ' . $appUrl);
}

if (str_starts_with($appUrl, 'http://') && $umgebung === 'production') {
    $befund->warnung(
        'APP_URL zeigt auf http, nicht https',
        'Die Anwendung laeuft auch ueber http — das Sitzungs-Cookie bekommt dann aber kein '
        . 'Secure-Flag, weil es sonst vom Browser verworfen wuerde. Fuer eine oeffentliche '
        . 'Seite gehoert ein Zertifikat davor.',
    );
}

if (Env::bool('APP_DEBUG') && $umgebung === 'production') {
    $befund->problem(
        'APP_DEBUG=1 bei APP_ENV=production',
        'Jeder Fehler zeigt Dateipfade und Programmzeilen im Browser. APP_DEBUG=0 setzen.',
    );
} else {
    $befund->ok(sprintf('APP_ENV=%s, APP_DEBUG=%s', $umgebung, Env::bool('APP_DEBUG') ? '1' : '0'));
}

$befund->ok('Zeitzone: ' . date_default_timezone_get());

$mail = Env::string('MAIL_TRANSPORT', 'datei');

if ($mail === 'datei' && $umgebung === 'production') {
    $befund->problem(
        'MAIL_TRANSPORT=datei verschickt nichts',
        'Bestaetigungen und Passwort-Ruecksetzungen landen in ' . Env::string('MAIL_DIRECTORY', 'storage/mail')
        . '. Auf einem Server MAIL_TRANSPORT=sendmail setzen.',
    );
} else {
    $befund->ok('Mailversand: ' . $mail);
}

// -------------------------------------------------------- Verzeichnisse
$befund->abschnitt('Verzeichnisse und Rechte');

$privat = $root . '/' . ltrim(Env::string('STORAGE_PRIVATE', 'storage/private'), '/');
$oeffentlich = $root . '/' . ltrim(Env::string('STORAGE_PUBLIC', 'public/uploads'), '/');

foreach ([
    'storage/db' => dirname($root . '/' . ltrim(Env::string('DB_DATABASE', 'storage/db/reptilienmarkt.sqlite'), '/')),
    'Rechtsnachweise' => $privat,
    'Bilder' => $oeffentlich,
    'Protokolle' => $root . '/' . ltrim(Env::string('LOG_DIRECTORY', 'storage/logs'), '/'),
    'Sicherungen' => $root . '/' . ltrim(Env::string('BACKUP_DIRECTORY', 'storage/backups'), '/'),
] as $name => $pfad) {
    if (!is_dir($pfad)) {
        $befund->warnung($name . ' fehlt: ' . kurz($root, $pfad), 'Wird beim ersten Schreiben angelegt — sofern der Elternordner beschreibbar ist.');

        continue;
    }

    is_writable($pfad)
        ? $befund->ok($name . ': ' . kurz($root, $pfad))
        : $befund->problem(
            $name . ' ist nicht beschreibbar: ' . kurz($root, $pfad),
            'sudo chown -R $USER:www-data ' . kurz($root, $pfad) . ' && sudo chmod 770 ' . kurz($root, $pfad),
        );
}

// Der eine Fehler, der alles kostet.
$publicPfad = realpath($root . '/public');
$privatPfad = realpath($privat);

if ($publicPfad !== false && $privatPfad !== false && str_starts_with($privatPfad, $publicPfad)) {
    $befund->problem(
        'STORAGE_PRIVATE liegt im oeffentlichen Verzeichnis',
        'Rechtsnachweise waeren dann direkt ueber die Adresszeile abrufbar. Ausserhalb von public/ ablegen.',
    );
} else {
    $befund->ok('Rechtsnachweise liegen ausserhalb von public/');
}

is_file($root . '/public/index.php')
    ? $befund->ok('Front-Controller: public/index.php', 'Der Document Root muss auf public/ zeigen, nicht auf ' . basename($root))
    : $befund->problem('public/index.php fehlt', 'Unvollstaendige Installation.');

// -------------------------------------------------------------- Datenbank
$befund->abschnitt('Datenbank');

try {
    $database = $container->get(Database::class);
    $arten = (int) (string) $database->scalar('SELECT COUNT(*) FROM species');
    $migrationen = (int) (string) $database->scalar('SELECT COUNT(*) FROM migrations');

    $befund->ok(sprintf('%d Migrationen angewandt', $migrationen));

    $offen = count(glob($root . '/migrations/*.php') ?: []) - $migrationen;
    $offen > 0
        ? $befund->problem(sprintf('%d Migrationen stehen aus', $offen), 'php bin/migrate.php up')
        : $befund->ok('Keine offenen Migrationen');

    $arten > 0
        ? $befund->ok(sprintf('Artenstamm: %d Arten', $arten))
        : $befund->problem('Der Artenstamm ist leer', 'php bin/seed.php');

    $admins = (int) (string) $database->scalar("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    $admins > 0
        ? $befund->ok(sprintf('%d Verwaltungskonto(en)', $admins))
        : $befund->warnung('Es gibt kein Administratorkonto', 'php bin/admin.php anlegen --email=du@example.tld');
} catch (Throwable $exception) {
    $befund->problem('Die Datenbank ist nicht ansprechbar', $exception->getMessage());
}

// --------------------------------------------------------------- Sitzung
$befund->abschnitt('Sitzungen und Formulare');

try {
    $sessions = $container->get(SessionRepository::class);
    $jetzt = new DateTimeImmutable();
    $probeId = 'doctor-' . bin2hex(random_bytes(8));

    $sessions->save(new Session(
        $probeId,
        null,
        ['_csrf' => 'probe'],
        $jetzt,
        $jetzt,
        $jetzt->modify('+5 minutes'),
    ));

    $gelesen = $sessions->find($probeId);
    $sessions->delete($probeId);

    $gelesen !== null && ($gelesen->payload['_csrf'] ?? null) === 'probe'
        ? $befund->ok('Sitzungen lassen sich schreiben und wieder lesen')
        : $befund->problem(
            'Eine geschriebene Sitzung kam nicht zurueck',
            'Ohne Sitzung gibt es keinen CSRF-Token — jedes Formular endet mit "Das Formular ist abgelaufen".',
        );
} catch (Throwable $exception) {
    $befund->problem('Sitzungen lassen sich nicht speichern', $exception->getMessage());
}

echo "\n  Zum Cookie: Das Secure-Flag setzt die Anwendung genau dann, wenn die Anfrage\n";
echo "  tatsaechlich ueber TLS hereinkommt. Hinter einem TLS-Proxy braucht es dafuer\n";
echo "  den Kopf X-Forwarded-Proto: https — sonst fehlt das Flag, die Seite laeuft\n";
echo "  aber weiter.\n";

// ---------------------------------------------------------------- Betrieb
$befund->abschnitt('Betrieb');

try {
    $database = $container->get(Database::class);

    $letzter = $database->scalar("SELECT MAX(completed_at) FROM jobs WHERE status = 'erledigt'");
    $wartend = (int) (string) $database->scalar("SELECT COUNT(*) FROM jobs WHERE status = 'wartend'");
    $gescheitert = (int) (string) $database->scalar("SELECT COUNT(*) FROM jobs WHERE status = 'fehlgeschlagen'");

    if (!is_string($letzter)) {
        $befund->warnung(
            'Es wurde noch nie ein Auftrag abgearbeitet',
            'Laeuft der Cron? php bin/cron.php und php bin/worker.php --einmal von Hand probieren.',
        );
    } else {
        $alter = time() - (int) strtotime($letzter);

        $alter < 7200
            ? $befund->ok('Letzter Auftrag: ' . $letzter)
            : $befund->warnung(
                'Der letzte Auftrag lief vor ' . round($alter / 3600) . ' Stunden (' . $letzter . ')',
                'Anzeigen laufen dann nicht ab und Erinnerungen gehen nicht hinaus. Cron pruefen.',
            );
    }

    $wartend > 50
        ? $befund->warnung($wartend . ' Auftraege warten', 'Der Worker kommt nicht hinterher oder laeuft nicht.')
        : $befund->ok($wartend . ' Auftraege warten');

    $gescheitert > 0
        ? $befund->warnung($gescheitert . ' Auftraege sind gescheitert', 'Die Fehlertexte stehen im Dashboard unter /admin/.')
        : $befund->ok('Keine gescheiterten Auftraege');
} catch (Throwable $exception) {
    $befund->problem('Der Auftragsstand ist nicht lesbar', $exception->getMessage());
}

// ---------------------------------------------------------------- Fazit
echo "\n";

if ($befund->fehler === 0 && $befund->warnungen === 0) {
    echo "Alles in Ordnung.\n";

    exit(0);
}

printf("%d Fehler, %d Hinweise.\n", $befund->fehler, $befund->warnungen);

exit($befund->fehler > 0 ? 1 : 0);

function kurz(string $root, string $pfad): string
{
    return str_starts_with($pfad, $root . '/') ? substr($pfad, strlen($root) + 1) : $pfad;
}
