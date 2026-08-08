<?php

declare(strict_types=1);

/**
 * Rauchtest Phase 10: Redaktionssystem.
 *
 * Geprueft wird der Weg durch den echten Kernel — mit Cookie, CSRF-Token und
 * Weiterleitungen. Anders als smoke_betrieb.php baut dieser Test seine
 * Datenbank selbst auf: Das Redaktionssystem braucht keine Seed-Daten, und ein
 * Rauchtest, der eine vorhandene Entwicklungsdatenbank voraussetzt, laeuft in
 * keiner frischen Umgebung.
 *
 *   1. Ohne Berechtigung ist die Redaktion nicht vorhanden (404, nicht 403).
 *   2. Eine Seite anlegen, Bloecke fuellen, veroeffentlichen, oeffentlich abrufen.
 *   3. Der belegte Pfad wird mit Namen abgewiesen.
 *   4. Zuruecknehmen und Archivieren wirken wie angekuendigt (Entwurf, 410).
 *
 * Die spaeteren Arbeitspakete ergaenzen hier: Slug-Aenderung mit Weiterleitung,
 * geplanter Beitrag mit Joblauf, Sitemap und Feed, Revision zuruecksetzen.
 *
 * Aufruf: php tools/smoke_cms.php [--behalten]
 */

use Reptilienmarkt\Domain\Auth\AuthenticationService;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\VerificationRepository;
use Reptilienmarkt\Http\Kernel;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Infra\Persistence\Migrator;
use Reptilienmarkt\Support\Clock;
use Reptilienmarkt\Support\Container;
use Reptilienmarkt\Support\Env;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$behalten = in_array('--behalten', $argv, true);

$arbeitsverzeichnis = $root . '/storage/smoke10';

verzeichnisLeeren($arbeitsverzeichnis);
@mkdir($arbeitsverzeichnis . '/db', 0o775, true);
@mkdir($arbeitsverzeichnis . '/public', 0o775, true);
@mkdir($arbeitsverzeichnis . '/private', 0o770, true);
@mkdir($arbeitsverzeichnis . '/logs', 0o775, true);

$envDatei = $arbeitsverzeichnis . '/env';
file_put_contents($envDatei, implode("\n", [
    'APP_ENV=test',
    'APP_DEBUG=1',
    'APP_URL=http://localhost',
    'APP_TIMEZONE=Europe/Berlin',
    'DB_DRIVER=sqlite',
    'DB_DATABASE=storage/smoke10/db/smoke.sqlite',
    'STORAGE_PUBLIC=storage/smoke10/public',
    'STORAGE_PRIVATE=storage/smoke10/private',
    'LOG_DIRECTORY=storage/smoke10/logs',
    'LOG_LEVEL=debug',
    'MAIL_TRANSPORT=datei',
    'MAIL_DIRECTORY=storage/smoke10/mail',
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
$container->get(Migrator::class)->up();

echo "Rauchtest Phase 10 — Redaktionssystem\n\n";

// ------------------------------------------------------------- Konten
$auth = $container->get(AuthenticationService::class);
$verification = $container->get(VerificationRepository::class);
$clock = $container->get(Clock::class);

$adminEmail = 'redaktion-admin-' . bin2hex(random_bytes(4)) . '@example.tld';
$fremdEmail = 'redaktion-fremd-' . bin2hex(random_bytes(4)) . '@example.tld';
$passwort = 'einsicheres123';

$admin = $auth->register($adminEmail, 'Redaktionsleitung', $passwort, Role::Admin);
$verification->markEmailVerified($admin->id ?? 0, $clock->now());

$fremd = $auth->register($fremdEmail, 'Gewöhnliches Konto', $passwort);
$verification->markEmailVerified($fremd->id ?? 0, $clock->now());

// -------------------------------------------------------- 1) Zugriffsschutz
echo "Zugriffsschutz\n";

$fremder = new RedaktionsBrowser($containerFabrik);
$fremder->anmelden($fremdEmail, $passwort);

$abweisung = $fremder->get('/admin/inhalte');
pruefe(
    $abweisung->status === 404,
    'Ohne Berechtigung ist die Redaktion nicht vorhanden (404, nicht 403)',
    'Status ' . $abweisung->status,
);

$redaktion = new RedaktionsBrowser($containerFabrik);
$redaktion->anmelden($adminEmail, $passwort);

pruefe($redaktion->get('/admin/inhalte')->status === 200, 'Die Verwaltung sieht die Inhaltsliste', 'kein 200');

// ------------------------------------------------------------ 2) Anlegen
echo "\nAnlegen und Fuellen\n";

$redaktion->get('/admin/inhalte/neu');
$ziel = $redaktion->post('/admin/inhalte/neu', [
    'titel' => 'Haltung im Terrarium',
    'typ' => 'seite',
]);

pruefe(
    preg_match('#^/admin/inhalte/(\d+)/bearbeiten$#', $ziel, $treffer) === 1,
    'Nach dem Anlegen geht es direkt in den Editor',
    'Ziel war: ' . $ziel,
);

$seiteId = (int) ($treffer[1] ?? 0);

$editor = $redaktion->get('/admin/inhalte/' . $seiteId . '/bearbeiten');
pruefe($editor->status === 200, 'Der Editor rendert', 'Status ' . $editor->status);
pruefe(
    str_contains($editor->body, 'name="aktion" value="block-hoch:0"') === false,
    'Ein frischer Eintrag hat noch keine Bloecke',
    'es sind schon Bloecke da',
);

// Bloecke fuellen — genau so, wie das Formular ohne Javascript absendet.
$redaktion->post('/admin/inhalte/' . $seiteId . '/bearbeiten', [
    'titel' => 'Haltung im Terrarium',
    'slug' => 'haltung-im-terrarium',
    'anriss' => 'Was ein Terrarium können muss.',
    'vorlage' => 'standard',
    'block' => [
        ['typ' => 'text', 'text' => "## Grundlagen\n\nEin **wichtiger** Satz mit [Markt](/markt/)."],
        ['typ' => 'hinweis', 'titel' => 'Achtung', 'text' => 'Prüfe die Vorschriften.'],
    ],
    'aktion' => 'speichern',
]);

$editor = $redaktion->get('/admin/inhalte/' . $seiteId . '/bearbeiten');
pruefe(str_contains($editor->body, 'Grundlagen'), 'Der Textblock ist gespeichert', 'Text fehlt im Editor');
pruefe(str_contains($editor->body, 'Achtung'), 'Der Hinweisblock ist gespeichert', 'Hinweis fehlt im Editor');

// --------------------------------------------------- 3) Vor der Freigabe
echo "\nVeroeffentlichen\n";

$gast = new RedaktionsBrowser($containerFabrik);
pruefe(
    $gast->get('/haltung-im-terrarium/')->status === 404,
    'Ein Entwurf ist oeffentlich nicht erreichbar',
    'der Entwurf war abrufbar',
);

$redaktion->post('/admin/inhalte/' . $seiteId . '/veroeffentlichen', ['termin' => '']);

$oeffentlich = $gast->get('/haltung-im-terrarium/');
pruefe($oeffentlich->status === 200, 'Die veroeffentlichte Seite wird ausgeliefert', 'Status ' . $oeffentlich->status);
pruefe(str_contains($oeffentlich->body, '<h2>Grundlagen</h2>'), 'Markdown wird gerendert', 'keine h2 im Rumpf');
pruefe(
    str_contains($oeffentlich->body, '<strong>wichtiger</strong>'),
    'Fetter Text wird ausgezeichnet',
    'kein strong im Rumpf',
);
pruefe(
    str_contains($oeffentlich->body, '<link rel="canonical" href="/haltung-im-terrarium/">'),
    'Die Seite nennt ihre kanonische Adresse',
    'kein canonical',
);

// ----------------------------------------------------- 4) Kollisionsschutz
echo "\nKollisionsschutz\n";

$redaktion->post('/admin/inhalte/neu', ['titel' => 'Impressum', 'typ' => 'seite']);

// In der Datenbank nachsehen, nicht im Seitenrumpf: Die Fehlermeldung nennt den
// Konflikt ja beim Namen und enthaelt deshalb selbst "/impressum/".
$angelegt = $container->get(Database::class)->scalar(
    "SELECT COUNT(*) FROM content_entries WHERE path = '/impressum/'",
);

pruefe(
    (int) $angelegt === 0,
    'Ein belegter Pfad wird abgewiesen — /impressum/ bleibt bei config/impressum.php',
    'die Seite wurde angelegt',
);

$liste = $redaktion->get('/admin/inhalte');
pruefe(
    str_contains($liste->body, 'ist belegt'),
    'Die Meldung nennt den Konflikt beim Namen',
    'keine erklaerende Meldung in der Liste',
);
pruefe(
    $gast->get('/impressum')->status === 200,
    'Die Rechtsseite antwortet unveraendert',
    'das Impressum ist nicht mehr erreichbar',
);

// ------------------------------------------------------ 5) Statuswechsel
echo "\nStatuswechsel\n";

$redaktion->post('/admin/inhalte/' . $seiteId . '/zuruecknehmen', []);
pruefe(
    $gast->get('/haltung-im-terrarium/')->status === 404,
    'Zurueckgenommen ist wieder ein Entwurf',
    'die Seite war noch erreichbar',
);

$redaktion->post('/admin/inhalte/' . $seiteId . '/veroeffentlichen', ['termin' => '']);
$redaktion->post('/admin/inhalte/' . $seiteId . '/archivieren', []);

$archiviert = $gast->get('/haltung-im-terrarium/');
pruefe(
    $archiviert->status === 410,
    'Archiviert antwortet mit 410 — ein entfernter Inhalt ist kein Tippfehler in der URL',
    'Status ' . $archiviert->status,
);

// ------------------------------------------------------------ 6) Loeschen
echo "\nAufraeumen\n";

$redaktion->post('/admin/inhalte/' . $seiteId . '/loeschen', []);
pruefe(
    $gast->get('/haltung-im-terrarium/')->status === 404,
    'Die geloeschte Seite ist fort',
    'die Seite war noch da',
);

$spuren = $container->get(Database::class)->select(
    "SELECT action FROM audit_log WHERE entity_type = 'content_entry' ORDER BY id",
);
$aktionen = array_map(static fn(array $zeile): string => (string) $zeile['action'], $spuren);

foreach (['content.created', 'content.published', 'content.unpublished', 'content.deleted'] as $erwartet) {
    pruefe(
        in_array($erwartet, $aktionen, true),
        'Der Audit-Trail traegt ' . $erwartet,
        'gefunden wurde: ' . implode(', ', array_unique($aktionen)),
    );
}

if (!$behalten) {
    verzeichnisLeeren($arbeitsverzeichnis);
}

echo "\nAlles in Ordnung.\n";
echo $behalten
    ? "Die Arbeitsdaten liegen in storage/smoke10/.\n"
    : "Die Arbeitsdaten wurden entfernt (--behalten laesst sie liegen).\n";

exit(0);

/**
 * Ein Browser gegen den echten Kernel: haelt Cookie und CSRF-Token, folgt
 * keinen Weiterleitungen — das macht die Pruefung sichtbar.
 */
final class RedaktionsBrowser
{
    private ?string $cookie = null;

    private string $csrf = '';

    /**
     * @param callable(): Container $container Liefert pro Anfrage einen frischen Objektgraphen
     */
    public function __construct(private $container, private readonly string $ip = '203.0.113.9') {}

    public function anmelden(string $email, string $passwort): void
    {
        $this->get('/anmelden');
        $this->post('/anmelden', ['email' => $email, 'passwort' => $passwort]);
    }

    public function get(string $path): Response
    {
        $response = $this->send(new Request('GET', $path, [], [], $this->headers(), [], $this->cookies(), $this->ip));

        if (preg_match('/name="_csrf"\s+value="([^"]+)"/', $response->body, $treffer) === 1) {
            $this->csrf = $treffer[1];
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

    private function send(Request $request): Response
    {
        $response = ($this->container)()->get(Kernel::class)->handle($request);

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
