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
 *   4. Ein geplanter Beitrag erscheint erst, wenn der Auftrag gelaufen ist.
 *   5. Ein Vorschaulink zeigt einen Entwurf, ohne Anmeldung und ohne noindex.
 *   6. Medien: Metadaten weg, srcset im Markup, Loeschsperre bei Verwendung.
 *   7. Beitraege, Kategoriearchiv, Volltextsuche und Feed.
 *   8. Eine Fassung laesst sich zuruecksetzen.
 *   9. Eine Slug-Aenderung legt die 301 selbst an, ohne Kette.
 *  10. Sitemap, robots.txt und Menues.
 *  11. Zuruecknehmen und Archivieren wirken wie angekuendigt (Entwurf, 410).
 *
 * Aufruf: php tools/smoke_cms.php [--behalten]
 */

use Reptilienmarkt\Domain\Auth\AuthenticationService;
use Reptilienmarkt\Domain\Job\JobRunner;
use Reptilienmarkt\Domain\Job\JobScheduler;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\VerificationRepository;
use Reptilienmarkt\Http\Kernel;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Message\UploadedFile;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Infra\Persistence\Migrator;
use Reptilienmarkt\Support\Clock;
use Reptilienmarkt\Support\Container;
use Reptilienmarkt\Support\Env;
use Reptilienmarkt\Tests\Support\JpegWithGps;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

// JpegWithGps liegt bei den Testhelfern: Das Fixture soll es genau einmal
// geben, sonst laufen zwei Fassungen desselben EXIF-Blocks auseinander.
require $root . '/tests/Support/JpegWithGps.php';

$behalten = in_array('--behalten', $argv, true);

$arbeitsverzeichnis = $root . '/storage/smoke10';

verzeichnisLeeren($arbeitsverzeichnis);
@mkdir($arbeitsverzeichnis . '/db', 0o775, true);
@mkdir($arbeitsverzeichnis . '/public', 0o775, true);
@mkdir($arbeitsverzeichnis . '/private', 0o770, true);
@mkdir($arbeitsverzeichnis . '/logs', 0o775, true);
@mkdir($arbeitsverzeichnis . '/media', 0o775, true);

$envDatei = $arbeitsverzeichnis . '/env';
file_put_contents($envDatei, implode("\n", [
    'APP_ENV=test',
    'APP_DEBUG=1',
    'APP_URL=http://localhost',
    'APP_TIMEZONE=Europe/Berlin',
    'DB_DRIVER=sqlite',
    'DB_DATABASE=storage/smoke10/db/smoke.sqlite',
    'STORAGE_PUBLIC=storage/smoke10/public',
    'STORAGE_MEDIA=storage/smoke10/media',
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
    str_contains($oeffentlich->body, 'rel="canonical" href="http://localhost/haltung-im-terrarium/"'),
    'Die Seite nennt ihre kanonische Adresse absolut',
    'kein canonical',
);
pruefe(
    str_contains($oeffentlich->body, '<meta property="og:title" content="Haltung im Terrarium">'),
    'Die Seite traegt OpenGraph-Angaben',
    'kein og:title',
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

// ---------------------------------------------------------- 5) Planung
echo "\nPlanung\n";

$ziel = $redaktion->post('/admin/inhalte/neu', ['titel' => 'Nachzuchtsaison 2026', 'typ' => 'beitrag']);
preg_match('#/admin/inhalte/(\d+)/bearbeiten#', $ziel, $treffer);
$beitragId = (int) ($treffer[1] ?? 0);

$termin = (new DateTimeImmutable('+30 minutes'))->format('Y-m-d\TH:i:s');
$redaktion->post('/admin/inhalte/' . $beitragId . '/veroeffentlichen', ['termin' => $termin]);

$beitragsPfad = (string) $container->get(Database::class)->scalar(
    'SELECT path FROM content_entries WHERE id = :id',
    ['id' => $beitragId],
);

pruefe(
    $gast->get($beitragsPfad)->status === 404,
    'Ein geplanter Beitrag ist vor dem Termin nicht erreichbar',
    'der Beitrag war schon abrufbar',
);

// Den Termin in die Vergangenheit ruecken — kuerzer, als eine halbe Stunde zu
// warten, und es prueft dasselbe: dass erst der Joblauf freischaltet.
$container->get(Database::class)->execute(
    "UPDATE content_entries SET published_at = :wann WHERE id = :id",
    ['wann' => gmdate('Y-m-d\TH:i:s\Z', time() - 60), 'id' => $beitragId],
);

pruefe(
    $gast->get($beitragsPfad)->status === 404,
    'Auch nach dem Termin bleibt er liegen, solange kein Auftrag lief',
    'der Beitrag erschien ohne Joblauf',
);

$scheduler = $container->get(JobScheduler::class);
$eingeplant = $scheduler->schedule();

pruefe(
    in_array('content.publish', $eingeplant, true),
    'Der Zeitplan kennt content.publish',
    'eingeplant wurde: ' . implode(', ', $eingeplant),
);

$container->get(JobRunner::class)->run();

pruefe(
    $gast->get($beitragsPfad)->status === 200,
    'Nach dem Joblauf ist der Beitrag online',
    'der Beitrag blieb unsichtbar',
);

// ---------------------------------------------------------- 6) Vorschau
echo "\nVorschau\n";

$ziel = $redaktion->post('/admin/inhalte/neu', ['titel' => 'Noch nicht fertig', 'typ' => 'seite']);
preg_match('#/admin/inhalte/(\d+)/bearbeiten#', $ziel, $treffer);
$entwurfId = (int) ($treffer[1] ?? 0);

$redaktion->get('/admin/inhalte/' . $entwurfId . '/bearbeiten');
$redaktion->post('/admin/inhalte/' . $entwurfId . '/vorschau', []);

$editor = $redaktion->get('/admin/inhalte/' . $entwurfId . '/bearbeiten');
pruefe(
    preg_match('#(/vorschau/[0-9a-f]{64})#', $editor->body, $treffer) === 1,
    'Der Vorschaulink erscheint einmal als Meldung',
    'kein Link in der Meldung',
);

$vorschau = $gast->get($treffer[1] ?? '/vorschau/x');
pruefe($vorschau->status === 200, 'Der Vorschaulink zeigt den Entwurf ohne Anmeldung', 'Status ' . $vorschau->status);
pruefe(
    str_contains((string) ($vorschau->headers['x-robots-tag'] ?? ''), 'noindex'),
    'Die Vorschau traegt noindex — sonst verraet ein Suchindex den Entwurf',
    'kein X-Robots-Tag',
);
pruefe(
    $gast->get('/noch-nicht-fertig/')->status === 404,
    'Der Entwurf selbst bleibt unter seiner Adresse unsichtbar',
    'der Entwurf war oeffentlich',
);

// ------------------------------------------------------------ 7) Medien
echo "\nMediathek\n";

pruefe($redaktion->get('/admin/medien')->status === 200, 'Die Mediathek rendert', 'kein 200');

$quelle = $arbeitsverzeichnis . '/quelle.jpg';
JpegWithGps::create($quelle);
pruefe(is_array(@exif_read_data($quelle)), 'Das Quellbild traegt wirklich GPS-Daten', 'kein EXIF im Quellbild');

$redaktion->upload('/admin/medien', [], ['bild' => datei($quelle, 'urlaubsfoto.jpg')]);

$medium = $container->get(Database::class)->selectOne('SELECT id, path FROM media ORDER BY id DESC LIMIT 1');
pruefe($medium !== null, 'Das Bild liegt in der Mediathek', 'kein Eintrag in media');

$mediumId = (int) ($medium['id'] ?? 0);
$mediumPfad = (string) ($medium['path'] ?? '');
$ablage = $root . '/storage/smoke10/media/';

foreach ([400, 800, 1600] as $kante) {
    $variante = $kante === 1600 ? $mediumPfad : preg_replace('/\.webp$/', '-' . $kante . '.webp', $mediumPfad);
    pruefe(is_file($ablage . $variante), 'Die Fassung ' . $kante . ' px entsteht', 'fehlt: ' . $variante);
}

pruefe(
    @exif_read_data($ablage . $mediumPfad) === false,
    'Das abgelegte Bild traegt keine Metadaten mehr',
    'EXIF ueberlebte die Verarbeitung',
);

// Das Bild einbinden — danach muss die Loeschsperre greifen.
$redaktion->post('/admin/inhalte/' . $seiteId . '/bearbeiten', [
    'titel' => 'Haltung im Terrarium',
    'slug' => 'haltung-im-terrarium',
    'vorlage' => 'standard',
    'block' => [
        ['typ' => 'text', 'text' => "## Grundlagen\n\nEin **wichtiger** Satz mit [Markt](/markt/)."],
        ['typ' => 'bild', 'media_id' => (string) $mediumId, 'alt_text' => 'Eine Bartagame auf Sand'],
    ],
    'aktion' => 'speichern',
]);

$verwendungen = (int) $container->get(Database::class)->scalar(
    'SELECT COUNT(*) FROM media_usages WHERE media_id = :id',
    ['id' => $mediumId],
);
pruefe($verwendungen === 1, 'Die Verwendung wird beim Speichern mitgeschrieben', $verwendungen . ' Verwendungen');

$fragen = $redaktion->get('/admin/medien/' . $mediumId . '/loeschen');
pruefe(
    str_contains($fragen->body, 'Haltung im Terrarium'),
    'Vor dem Loeschen wird gezeigt, wo das Bild steht',
    'die Verwendung fehlt in der Rueckfrage',
);

$redaktion->sendPost('/admin/medien/' . $mediumId . '/loeschen', []);
pruefe(
    $container->get(Database::class)->selectOne('SELECT id FROM media WHERE id = :id', ['id' => $mediumId]) !== null,
    'Ein verwendetes Bild laesst sich nicht loeschen',
    'das Bild wurde trotz Verwendung geloescht',
);

$oeffentlich = $gast->get('/haltung-im-terrarium/');
pruefe(
    str_contains($oeffentlich->body, 'srcset='),
    'Das eingebundene Bild wird mit srcset ausgeliefert',
    'kein srcset im Markup',
);
pruefe(
    str_contains($oeffentlich->body, 'alt="Eine Bartagame auf Sand"'),
    'Die Bildbeschreibung steht im Markup',
    'kein alt-Attribut',
);

// -------------------------------------------------- 8) Beitraege und Feed
echo "\nBeitraege, Kategorien und Feed\n";

// Dem geplanten Beitrag eine Kategorie geben und ihn fuellen.
$redaktion->post('/admin/inhalte/' . $beitragId . '/bearbeiten', [
    'titel' => 'Nachzuchtsaison 2026',
    'slug' => 'nachzuchtsaison-2026',
    'anriss' => 'Was in dieser Saison ansteht.',
    'vorlage' => 'beitrag',
    'kategorien' => 'Zucht, Haltung',
    'schlagwoerter' => 'saison',
    'block' => [
        ['typ' => 'text', 'text' => 'Die **Winterruhe** endet, die Zuchtsaison beginnt.'],
    ],
    'aktion' => 'speichern',
]);

$uebersicht = $gast->get('/news/');
pruefe($uebersicht->status === 200, 'Die Beitragsuebersicht rendert', 'Status ' . $uebersicht->status);
pruefe(
    str_contains($uebersicht->body, 'Nachzuchtsaison 2026'),
    'Der veroeffentlichte Beitrag steht in der Uebersicht',
    'der Beitrag fehlt',
);
pruefe(str_contains($uebersicht->body, 'Zucht'), 'Die Kategorien stehen in der Navigation', 'keine Kategorie sichtbar');

$archiv = $gast->get('/news/kategorie/zucht/');
pruefe($archiv->status === 200, 'Das Kategoriearchiv rendert', 'Status ' . $archiv->status);
pruefe(
    str_contains($archiv->body, 'Nachzuchtsaison 2026'),
    'Das Archiv zeigt den Beitrag der Kategorie',
    'der Beitrag fehlt im Archiv',
);

$treffer = $gast->get('/news/?q=Winterruhe');
pruefe(
    str_contains($treffer->body, 'Nachzuchtsaison 2026'),
    'Die Volltextsuche findet ein Wort aus dem Rumpf',
    'kein Treffer zu "Winterruhe"',
);

$feed = $gast->get('/feed.xml');
pruefe($feed->status === 200, 'Der Feed antwortet', 'Status ' . $feed->status);
pruefe(
    str_contains((string) ($feed->headers['content-type'] ?? ''), 'application/rss+xml'),
    'Der Feed nennt den richtigen Inhaltstyp',
    (string) ($feed->headers['content-type'] ?? ''),
);

$xml = @simplexml_load_string($feed->body);
pruefe($xml !== false, 'Der Feed ist gueltiges XML', 'XML-Fehler im Feed');
pruefe(
    $xml !== false && (string) $xml->channel->item[0]->title === 'Nachzuchtsaison 2026',
    'Der Feed traegt den Beitrag',
    'der Beitrag fehlt im Feed',
);

// ---------------------------------------------------------- 9) Fassungen
echo "\nFassungen\n";

$versionen = $redaktion->get('/admin/inhalte/' . $seiteId . '/versionen');
pruefe($versionen->status === 200, 'Die Fassungsliste rendert', 'Status ' . $versionen->status);

$fassungen = (int) $container->get(Database::class)->scalar(
    'SELECT COUNT(*) FROM content_revisions WHERE entry_id = :id',
    ['id' => $seiteId],
);
pruefe($fassungen >= 3, 'Jedes Speichern und jede Veroeffentlichung legt eine Fassung an', $fassungen . ' Fassungen');

// Den Titel verderben und ueber die aelteste Fassung zuruecksetzen.
$redaktion->post('/admin/inhalte/' . $seiteId . '/bearbeiten', [
    'titel' => 'Versehentlich umbenannt',
    'slug' => 'haltung-im-terrarium',
    'vorlage' => 'standard',
    'aktion' => 'speichern',
]);

$redaktion->post('/admin/inhalte/' . $seiteId . '/versionen/1/zuruecksetzen', []);

$titel = (string) $container->get(Database::class)->scalar(
    'SELECT title FROM content_entries WHERE id = :id',
    ['id' => $seiteId],
);
pruefe($titel === 'Haltung im Terrarium', 'Das Zuruecksetzen stellt den Titel wieder her', 'Titel ist: ' . $titel);

// ------------------------------------- 10) Slug-Aenderung und Weiterleitung
echo "\nWeiterleitungen\n";

$redaktion->post('/admin/inhalte/' . $seiteId . '/bearbeiten', [
    'titel' => 'Haltung im Terrarium',
    'slug' => 'terrarienhaltung',
    'vorlage' => 'standard',
    'aktion' => 'speichern',
]);

$alt = $gast->get('/haltung-im-terrarium/');
pruefe($alt->status === 301, 'Der alte Pfad antwortet mit 301', 'Status ' . $alt->status);
pruefe(
    ($alt->headers['location'] ?? '') === '/terrarienhaltung/',
    'Die Weiterleitung zeigt auf den neuen Pfad',
    'Ziel war: ' . (string) ($alt->headers['location'] ?? '—'),
);
pruefe($gast->get('/terrarienhaltung/')->status === 200, 'Die Seite ist unter der neuen Adresse da', 'kein 200');

// Zweite Umbenennung: Der erste Pfad muss direkt aufs Ziel zeigen, nicht in
// eine Kette laufen.
$redaktion->post('/admin/inhalte/' . $seiteId . '/bearbeiten', [
    'titel' => 'Haltung im Terrarium',
    'slug' => 'haltung-im-terrarium',
    'vorlage' => 'standard',
    'aktion' => 'speichern',
]);

$ziel = (string) $container->get(Database::class)->scalar(
    "SELECT to_path FROM content_redirects WHERE from_path = '/haltung-im-terrarium/'",
);
$erste = (string) $container->get(Database::class)->scalar(
    "SELECT to_path FROM content_redirects WHERE from_path = '/terrarienhaltung/'",
);

pruefe($erste === '/haltung-im-terrarium/', 'Die zweite Umbenennung legt ihre eigene 301 an', 'Ziel: ' . $erste);
pruefe(
    $ziel === '' || $ziel === '/haltung-im-terrarium/',
    'Keine Weiterleitungskette entsteht',
    'Ziel der ersten: ' . $ziel,
);

$schleife = $redaktion->sendPost('/admin/weiterleitungen', [
    'von' => '/terrarienhaltung/',
    'nach' => '/haltung-im-terrarium/',
    'code' => '301',
]);
pruefe($schleife->status >= 300 && $schleife->status < 400, 'Die Weiterleitungsliste nimmt Eingaben an', 'Status ' . $schleife->status);

// ------------------------------------------------- 11) Sitemap und robots
echo "\nSitemap und robots.txt\n";

$sitemap = $gast->get('/sitemap.xml');
pruefe($sitemap->status === 200, 'Die Sitemap antwortet', 'Status ' . $sitemap->status);
pruefe(@simplexml_load_string($sitemap->body) !== false, 'Die Sitemap ist gueltiges XML', 'XML-Fehler');
pruefe(
    str_contains($sitemap->body, '/haltung-im-terrarium/'),
    'Die veroeffentlichte Seite steht in der Sitemap',
    'die Seite fehlt',
);

$etag = (string) ($sitemap->headers['etag'] ?? '');
pruefe($etag !== '', 'Die Sitemap traegt ein ETag', 'kein ETag');

$robots = $gast->get('/robots.txt');
pruefe($robots->status === 200, 'robots.txt antwortet', 'Status ' . $robots->status);
pruefe(str_contains($robots->body, 'Sitemap:'), 'robots.txt verweist auf die Sitemap', 'kein Sitemap-Verweis');
pruefe(str_contains($robots->body, 'Disallow: /admin/'), 'robots.txt sperrt die Verwaltung', 'kein Disallow');

// ------------------------------------------------------------ 12) Menues
echo "\nMenues\n";

$menues = $redaktion->get('/admin/menues');
pruefe($menues->status === 200, 'Die Menueverwaltung rendert', 'Status ' . $menues->status);

$redaktion->post('/admin/menues', [
    'menu' => 'hauptmenu',
    'label' => 'Haltung',
    'ziel_typ' => 'entry',
    'ziel_wert' => (string) $seiteId,
    'sichtbarkeit' => 'alle',
    'reihenfolge' => '0',
]);

$start = $gast->get('/markt/');
pruefe(
    str_contains($start->body, '>Haltung</a>'),
    'Der Menueeintrag erscheint in der Kopfzeile',
    'kein Menueeintrag im Markup',
);

// ----------------------------------------------------- 13) Statuswechsel
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

// ----------------------------------------------------------- 14) Loeschen
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

foreach ([
    'content.created',
    'content.published',
    'content.scheduled',
    'content.restored',
    'content.unpublished',
    'content.deleted',
] as $erwartet) {
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
        [$pfad, $query] = self::split($path);

        $response = $this->send(new Request('GET', $pfad, $query, [], $this->headers(), [], $this->cookies(), $this->ip));

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
            $this->ip,
            $files,
        ));

        if ($response->status < 300 || $response->status >= 400) {
            fehler(sprintf('POST %s antwortete mit %d: %s', $path, $response->status, strip_tags($response->body)));
        }

        $location = $response->headers['location'] ?? '';

        return is_string($location) ? $location : '';
    }

    /**
     * Wie post(), aber ohne Pruefung der Weiterleitung — fuer Faelle, in denen
     * gerade die Abweisung das erwartete Ergebnis ist.
     *
     * @param array<string, mixed> $body
     */
    public function sendPost(string $path, array $body): Response
    {
        return $this->send(new Request(
            'POST',
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
     * Trennt Pfad und Abfrageteil. Ohne das landet "?q=..." im Pfad, und die
     * Auffangroute antwortet mit 404 statt zu suchen.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function split(string $path): array
    {
        $stelle = strpos($path, '?');

        if ($stelle === false) {
            return [$path, []];
        }

        parse_str(substr($path, $stelle + 1), $query);

        /** @var array<string, string> $query */
        return [substr($path, 0, $stelle), $query];
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
    // Der Upload-Pfad verschiebt die Datei, deshalb je Upload eine Kopie.
    $kopie = $quelle . '.' . bin2hex(random_bytes(3));
    copy($quelle, $kopie);

    return new UploadedFile($kopie, $name, 'image/jpeg', (int) filesize($kopie));
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
