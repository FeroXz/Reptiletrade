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
use Reptilienmarkt\Domain\Content\MenuRepository;
use Reptilienmarkt\Domain\Content\RedirectService;
use Reptilienmarkt\Domain\Content\ReservedPaths;
use Reptilienmarkt\Domain\Site\SiteIdentity;
use Reptilienmarkt\Domain\Site\SiteIdentityService;
use Reptilienmarkt\Http\Controller\SitemapController;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Legal\LegalPageService;
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

// ------------------------------------------------------ Pflichtangaben
$befund->abschnitt('Rechtliche Pflichtangaben');

if (!is_file($root . '/config/impressum.php')) {
    $befund->problem(
        'config/impressum.php fehlt',
        'Ohne Anbieterkennzeichnung nach § 5 DDG gehoert die Seite nicht ins Netz.',
    );
} else {
    try {
        $identity = $container->get(SiteIdentity::class);
        $fehlt = $identity->missing();

        if ($fehlt !== []) {
            $befund->problem(
                'Im Impressum fehlen ' . count($fehlt) . ' Pflichtangaben',
                'Nachtragen unter /admin/recht oder in config/impressum.php: ' . implode('; ', $fehlt),
            );
        } elseif (!$identity->isComplete()) {
            // Angaben stehen, der Schalter nicht: Dann zeigt jede Rechtsseite
            // weiter den Warnhinweis — und der gehoert nicht auf eine
            // oeffentliche Seite.
            $befund->problem(
                "Der Schalter 'unvollstaendig' steht noch",
                'Die Angaben sind vollstaendig. Unter /admin/recht den Haken entfernen '
                . "(oder in config/impressum.php auf false setzen), damit der Warnhinweis verschwindet.",
            );
        } else {
            $befund->ok('Impressum vollstaendig: ' . implode(', ', $identity->addressLines()));
        }

        $identity->contact()['telefon'] === '' && !$identity->readyForDisputeResolution()
            ? $befund->warnung(
                'Kein Telefon im Impressum',
                'Neben der E-Mail verlangt § 5 DDG einen zweiten Weg fuer unmittelbare '
                . 'Kommunikation. Das Kontaktformular unter /kontakt zaehlt nur, wenn darauf '
                . 'zuegig geantwortet wird.',
            )
            : $befund->ok('Zweiter Kontaktweg vorhanden');

        ($identity->hosting()['avv_geschlossen'] ?? '') === '1'
            ? $befund->ok('Auftragsverarbeitungsvertrag mit dem Hoster vermerkt')
            : $befund->warnung(
                'Kein Auftragsverarbeitungsvertrag vermerkt',
                'Art. 28 DSGVO verlangt ihn mit jedem Hoster. Nach Abschluss unter '
                . '/admin/recht anhaken.',
            );
    } catch (Throwable $exception) {
        $befund->problem('config/impressum.php ist nicht lesbar', $exception->getMessage());
    }
}

// Woher die Angaben stammen, ist im Betrieb die wichtigere Frage als ob sie
// stehen. Gemeldet wird nur der Fall, in dem es weh tut: Die Datei allein
// ergaebe kein vollstaendiges Impressum, erst die Aenderungen der Verwaltung
// machen es vollstaendig. Nach dem Wiedereinspielen einer Sicherung ohne diese
// Tabelle stuende die Seite wieder mit Luecken im Netz — und niemand merkt es,
// weil sie ja aussieht wie immer.
try {
    $geaendert = $container->get(SiteIdentityService::class)->changedCount();

    /** @var array<string, mixed> $ausgeliefert */
    $ausgeliefert = $container->get('impressum.config');
    $nurInDerDatenbank = (new SiteIdentity($ausgeliefert))->missing();

    if ($geaendert === 0) {
        $befund->ok('Die Impressumsangaben stehen so in config/impressum.php');
    } elseif ($nurInDerDatenbank === []) {
        $befund->ok($geaendert . ' Impressumsangaben stammen aus der Verwaltung');
    } else {
        $befund->warnung(
            'Das Impressum ist nur mit den Angaben aus der Verwaltung vollstaendig',
            'In config/impressum.php fehlen: ' . implode('; ', $nurInDerDatenbank) . '. Eine '
            . 'Sicherung ohne die Tabelle site_identity_overrides brachte die Luecken zurueck — '
            . 'die Angaben deshalb auch in die Datei uebernehmen.',
        );
    }
} catch (Throwable $exception) {
    $befund->problem('Die Impressumsueberschreibungen sind nicht lesbar', $exception->getMessage());
}

// Eine Rechtsseite ohne Abschnitte ist eine leere Seite mit Ueberschrift.
try {
    $seiten = $container->get(LegalPageService::class);
    $leer = [];

    foreach (array_keys(LegalPageService::PAGES) as $seite) {
        if ($seiten->sections($seite) === []) {
            $leer[] = $seite;
        }
    }

    $leer === []
        ? $befund->ok('Alle Rechtsseiten haben Fliesstext')
        : $befund->problem(
            'Ohne Fliesstext: ' . implode(', ', $leer),
            'Die Abschnitte kommen aus data/legal_texts.json und werden von '
            . '"php bin/seed.php" eingespielt.',
        );
} catch (Throwable $exception) {
    $befund->problem('Die Rechtsseiten sind nicht lesbar', $exception->getMessage());
}

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

// ------------------------------------------------------- Redaktionssystem
$befund->abschnitt('Redaktionssystem');

$medienVerzeichnis = $root . '/' . ltrim(Env::string('STORAGE_MEDIA', 'public/media'), '/');

if (!is_dir($medienVerzeichnis)) {
    $befund->problem(
        'Das Medienverzeichnis fehlt: ' . kurz($root, $medienVerzeichnis),
        'Anlegen mit: sudo install -d -o www-data -g www-data -m 775 ' . $medienVerzeichnis,
    );
} elseif (!is_writable($medienVerzeichnis)) {
    $befund->problem(
        'Das Medienverzeichnis ist nicht beschreibbar: ' . kurz($root, $medienVerzeichnis),
        'Ohne Schreibrecht scheitert jeder Upload der Redaktion.',
    );
} else {
    $befund->ok('Das Medienverzeichnis ist beschreibbar', kurz($root, $medienVerzeichnis));
}

// Die zweite Linie gegen ausgefuehrte Uploads. Geprueft wird die Regel, nicht
// der Webserver — den kann dieses Skript nicht befragen.
$medienHtaccess = $medienVerzeichnis . '/.htaccess';

if (!is_file($medienHtaccess)) {
    $befund->warnung(
        'In ' . kurz($root, $medienVerzeichnis) . ' fehlt die .htaccess',
        'Bei Apache waere PHP dort dann ausfuehrbar. Bei nginx greift stattdessen die '
        . 'location-Regel aus docs/INSTALLATION.md — dann ist dieser Hinweis gegenstandslos.',
    );
} else {
    $regeln = (string) file_get_contents($medienHtaccess);

    str_contains($regeln, 'RemoveHandler') && str_contains($regeln, 'php')
        ? $befund->ok('PHP-Ausfuehrung in der Mediathek ist per .htaccess unterbunden')
        : $befund->problem(
            'Die .htaccess der Mediathek sperrt keine Skriptendungen',
            'Erwartet werden RemoveHandler/RemoveType fuer .php und Verwandte.',
        );
}

try {
    $menues = $container->get(MenuRepository::class);
    $vorhanden = array_keys($menues->menus());

    $fehlende = array_diff(['hauptmenu', 'fussbereich'], $vorhanden);

    $fehlende === []
        ? $befund->ok('Die Menues hauptmenu und fussbereich sind angelegt')
        : $befund->problem(
            'Es fehlen Menues: ' . implode(', ', $fehlende),
            'Sie entstehen mit Migration 0028 — laeuft "php bin/migrate.php status" sauber durch?',
        );

    $verwaist = $menues->danglingItems();

    $verwaist === []
        ? $befund->ok('Kein Menueeintrag zeigt auf einen geloeschten Inhalt')
        : $befund->warnung(
            count($verwaist) . ' Menueeintraege zeigen ins Leere',
            'Sie werden im Menue uebersprungen. Aufraeumen unter /admin/menues: '
            . implode(', ', array_map(static fn(array $e): string => $e['label'], $verwaist)),
        );
} catch (Throwable $exception) {
    $befund->problem('Die Menues sind nicht lesbar', $exception->getMessage());
}

try {
    $schleifen = $container->get(RedirectService::class)->loops();

    $schleifen === []
        ? $befund->ok('Keine Weiterleitungsschleifen')
        : $befund->problem(
            count($schleifen) . ' Weiterleitungen fuehren im Kreis',
            'Ein Besucher landet dort in einer Endlosschleife: '
            . implode(', ', array_map(
                static fn(array $s): string => $s['from'] . ' ⇄ ' . $s['to'],
                $schleifen,
            )),
        );
} catch (Throwable $exception) {
    $befund->problem('Die Weiterleitungen sind nicht lesbar', $exception->getMessage());
}

// Die Reservierungsliste gegen die tatsaechlich registrierten Routen — und
// gegen das, was bereits in der Datenbank steht. Der Test tut dasselbe fuer
// die Liste; hier geht es um den Datenbestand einer laufenden Installation.
try {
    $kollisionen = [];

    foreach ($container->get(Database::class)->select(
        "SELECT path FROM content_entries WHERE type = 'seite'",
    ) as $zeile) {
        $pfad = (string) $zeile['path'];

        if (ReservedPaths::isReserved($pfad)) {
            $kollisionen[] = $pfad;
        }
    }

    $kollisionen === []
        ? $befund->ok('Kein Inhaltspfad kollidiert mit einer registrierten Route')
        : $befund->problem(
            count($kollisionen) . ' Inhaltspfade sind von der Anwendung belegt',
            'Sie werden nie ausgeliefert, weil die feste Route vorher greift: ' . implode(', ', $kollisionen),
        );
} catch (Throwable $exception) {
    $befund->problem('Die Inhaltspfade sind nicht lesbar', $exception->getMessage());
}

try {
    $sitemap = $container->get(SitemapController::class)->sitemap(new Request('GET', '/sitemap.xml'));

    $sitemap->status === 200 && str_contains($sitemap->body, '<urlset')
        ? $befund->ok('Die Sitemap laesst sich erzeugen')
        : $befund->problem('Die Sitemap ist nicht erzeugbar', 'Status ' . $sitemap->status);
} catch (Throwable $exception) {
    $befund->problem('Die Sitemap ist nicht erzeugbar', $exception->getMessage());
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
