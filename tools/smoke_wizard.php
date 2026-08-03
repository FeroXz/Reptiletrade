<?php

declare(strict_types=1);

/**
 * Abnahmekriterium Phase 4: "Anzeige fuer Pogona vitticeps mit drei Morphs und
 * Foto in unter zwei Minuten anlegen."
 *
 * Das Skript geht den Weg, den ein Nutzer im Browser geht — durch den echten
 * Kernel, mit Cookie, CSRF-Token und Weiterleitungen. Gemessen wird die
 * Serverzeit; die Tippzeit laesst sich nicht messen, deshalb zaehlt das Skript
 * zusaetzlich die Pflichteingaben und rechnet sie mit einem angegebenen
 * Sekundenwert hoch. Beide Zahlen stehen im Bericht, damit nachvollziehbar
 * bleibt, was gemessen und was geschaetzt ist.
 *
 * Aufruf: php tools/smoke_wizard.php [--behalten]
 */

use Reptilienmarkt\Http\Kernel;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Message\UploadedFile;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Support\Container;
use Reptilienmarkt\Support\Env;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

/**
 * Sekunden pro Formularfeld — grob gegriffen, aber offen ausgewiesen.
 */
const SEKUNDEN_PRO_FELD = 4.0;

/**
 * Sekunden fuer die Bildauswahl im Dateidialog.
 */
const SEKUNDEN_BILDAUSWAHL = 8.0;

$behalten = in_array('--behalten', $argv, true);

// ---------------------------------------------------------- Testumgebung
$arbeitsverzeichnis = $root . '/storage/smoke';
$quelle = $root . '/storage/db/reptilienmarkt.sqlite';

if (!is_file($quelle)) {
    fwrite(\STDERR, "Es fehlt die Datenbank storage/db/reptilienmarkt.sqlite (bin/migrate.php, bin/seed.php).\n");

    exit(1);
}

verzeichnisLeeren($arbeitsverzeichnis);
@mkdir($arbeitsverzeichnis . '/db', 0o775, true);
@mkdir($arbeitsverzeichnis . '/public', 0o775, true);
@mkdir($arbeitsverzeichnis . '/private', 0o770, true);

// Kopie statt Original: Der Rauchtest darf die Entwicklungsdaten nicht anfassen.
copy($quelle, $arbeitsverzeichnis . '/db/smoke.sqlite');

$envDatei = $arbeitsverzeichnis . '/env';
file_put_contents($envDatei, implode("\n", [
    'APP_ENV=test',
    'APP_DEBUG=1',
    'APP_URL=http://localhost',
    'APP_TIMEZONE=Europe/Berlin',
    'DB_DRIVER=sqlite',
    'DB_DATABASE=storage/smoke/db/smoke.sqlite',
    'STORAGE_PUBLIC=storage/smoke/public',
    'STORAGE_PRIVATE=storage/smoke/private',
    'LEGAL_GEFAHRTIER_ENFORCEMENT=1',
]) . "\n");

Env::reset();
Env::load($envDatei, true);
date_default_timezone_set('Europe/Berlin');

/**
 * Pro Anfrage ein frischer Objektgraph — genau wie im Betrieb, wo jeder
 * Aufruf einen eigenen PHP-Prozess bekommt. Ein wiederverwendeter Container
 * wuerde z. B. den zwischengespeicherten CurrentUser ueber Anfragegrenzen
 * hinweg mitschleppen und damit etwas messen, das es real nicht gibt.
 */
$containerFabrik = static function () use ($root): Container {
    /** @var Container $container */
    $container = require $root . '/config/container.php';

    return $container;
};

$container = $containerFabrik();

// ------------------------------------------------------------ Testkloetze
$browser = new SmokeBrowser($containerFabrik);
$bild = testbildErzeugen($arbeitsverzeichnis . '/kamera.jpg');
$email = 'rauchtest-' . bin2hex(random_bytes(4)) . '@example.tld';

// Pflichtfelder und Kuerfelder werden getrennt gezaehlt: Das Kriterium haengt
// sonst an der Frage, wie ausfuehrlich der Beispieltext ausfaellt.
$pflichtfelder = 0;
$kuerfelder = 0;
$bilder = 0;
$schritte = [];

$gesamt = -microtime(true);

// 0) Konto anlegen. Zaehlt nicht zum Kriterium — es geht um das Anlegen einer
// Anzeige, und wer eine zweite einstellt, ist laengst angemeldet. Die
// Serverzeit wird trotzdem ausgewiesen.
$browser->get('/registrieren');
$ziel = $browser->post('/registrieren', [
    'email' => $email,
    'anzeigename' => 'Rauchtest Zuechter',
    'passwort' => 'einsicheres123',
]);
$registrierung = $browser->lastDuration();
$schritte[] = ['Konto anlegen (ausser Wertung)', $registrierung];

if ($ziel !== '/meine-anzeigen/') {
    fehler('Die Registrierung hat keine angemeldete Sitzung ergeben, Ziel war: ' . $ziel);
}

// 1) Schritt 1 — Typ und Art
$browser->get('/anzeige/neu');
$ziel = $browser->post('/anzeige/neu', ['typ' => 'verkauf', 'art_id' => (string) speciesId($container)]);
$pflichtfelder += 2;
$schritte[] = ['Schritt 1 — Typ und Art', $browser->lastDuration()];

if (!preg_match('#^/anzeige/(\d+)/schritt/2$#', $ziel, $treffer)) {
    fehler('Nach Schritt 1 wurde nicht auf Schritt 2 weitergeleitet, sondern auf: ' . $ziel);
}
$listingId = (int) $treffer[1];

// 2) Schritt 2 — drei Morphs (aus dem Abnahmekriterium)
$browser->get(sprintf('/anzeige/%d/schritt/2', $listingId));
$browser->post(sprintf('/anzeige/%d/schritt/2', $listingId), [
    'morph' => [
        (string) morphId($container, 'Hypomelanistic') => 'visual',
        (string) morphId($container, 'Translucent') => 'visual',
        (string) morphId($container, 'Zero') => 'het',
    ],
]);
$pflichtfelder += 3;
$schritte[] = ['Schritt 2 — drei Merkmale', $browser->lastDuration()];

// 3) Schritt 3 — Details. Titel ist Pflicht, der Rest macht die Anzeige gut.
$browser->get(sprintf('/anzeige/%d/schritt/3', $listingId));
$browser->post(sprintf('/anzeige/%d/schritt/3', $listingId), [
    'titel' => 'Bartagame Hypo Trans het Zero, NZ 2026',
    'beschreibung' => 'Kräftiges Tier aus eigener Nachzucht, frisst Gemüse und Insekten zuverlässig.',
    'geschlecht' => 'maennlich',
    'herkunft' => 'nachzucht',
    'anzahl' => '1',
    'schlupfdatum' => '2026-04-18',
    'gewicht' => '210',
]);
++$pflichtfelder;   // Titel
$kuerfelder += 6;   // Beschreibung, Geschlecht, Herkunft, Anzahl, Schlupfdatum, Gewicht
$schritte[] = ['Schritt 3 — Details', $browser->lastDuration()];

// 4) Schritt 4 — ein Foto. Ohne Bild laesst sich die Anzeige nicht stellen.
$browser->get(sprintf('/anzeige/%d/schritt/4', $listingId));
$browser->upload(
    sprintf('/anzeige/%d/bilder', $listingId),
    [],
    ['bild' => neueKopie($bild, $arbeitsverzeichnis . '/upload-1.jpg')],
);
++$bilder;
$schritte[] = ['Schritt 4 — Bild hochladen', $browser->lastDuration()];

// 5) Schritt 5 — keine Nachweise noetig, Bartagame ist nicht geschuetzt.
$browser->get(sprintf('/anzeige/%d/schritt/5', $listingId));
$browser->post(sprintf('/anzeige/%d/schritt/5', $listingId), []);
$schritte[] = ['Schritt 5 — Rechtsnachweise', $browser->lastDuration()];

// 6) Schritt 6 — Preis und Standort
$browser->get(sprintf('/anzeige/%d/schritt/6', $listingId));
$browser->post(sprintf('/anzeige/%d/schritt/6', $listingId), [
    'preis' => '180,00',
    'land' => 'DE',
    'plz' => '80331',
    'uebergabe' => 'abholung',
    'verhandelbar' => '1',
]);
$pflichtfelder += 3; // Preis, PLZ, Uebergabe — das Land steht auf DE vor
++$kuerfelder;       // Verhandelbar
$schritte[] = ['Schritt 6 — Preis und Standort', $browser->lastDuration()];

// 7) Schritt 7 — Vorschau und veroeffentlichen
$browser->get(sprintf('/anzeige/%d/schritt/7', $listingId));
$ziel = $browser->post(sprintf('/anzeige/%d/veroeffentlichen', $listingId), []);
++$pflichtfelder; // der Klick auf "Veroeffentlichen"
$schritte[] = ['Schritt 7 — Veröffentlichen', $browser->lastDuration()];

$gesamt += microtime(true);

// ------------------------------------------------------------- Pruefungen
if ($ziel !== sprintf('/anzeige/%d/', $listingId)) {
    fehler('Das Veroeffentlichen hat nicht auf die Detailseite geleitet, sondern auf: ' . $ziel);
}

$db = $container->get(\Reptilienmarkt\Infra\Persistence\Database::class);

$status = $db->scalar('SELECT status FROM listings WHERE id = :id', ['id' => $listingId]);
$morphAnzahl = (int) $db->scalar('SELECT COUNT(*) FROM listing_morphs WHERE listing_id = :id', ['id' => $listingId]);
$bildAnzahl = (int) $db->scalar("SELECT COUNT(*) FROM listing_media WHERE listing_id = :id AND media_type = 'bild'", ['id' => $listingId]);
$morphString = $container->get(\Reptilienmarkt\Domain\Listing\ListingWizard::class)->morphString($listingId);
$indexiert = (int) $db->scalar('SELECT COUNT(*) FROM listing_search WHERE rowid = :id', ['id' => $listingId]);

// Seit Phase 5 gehen die ersten Anzeigen eines frischen Kontos in die
// Vorpruefung — genau das passiert hier, denn der Rauchtest legt jedes Mal ein
// neues Konto an. "aktiv" waere jetzt das falsche Ergebnis: Es hiesse, die
// Vorpruefung greift nicht.
pruefe($status === 'pruefung', 'Status ist "pruefung" (Vorprüfung neuer Konten)', 'Status ist "' . (string) $status . '"');
pruefe($morphAnzahl === 3, 'Drei Merkmale gespeichert', $morphAnzahl . ' Merkmale gespeichert');
pruefe($bildAnzahl === 1, 'Ein Bild gespeichert', $bildAnzahl . ' Bilder gespeichert');
pruefe($morphString === 'Hypo Trans het Zero', 'Morph-String "Hypo Trans het Zero"', 'Morph-String "' . $morphString . '"');
pruefe($indexiert === 1, 'Anzeige steht im Suchindex', 'Anzeige fehlt im Suchindex');

// Das Bild muss metadatenfrei auf der Platte liegen.
$pfad = (string) $db->scalar("SELECT path FROM listing_media WHERE listing_id = :id AND media_type = 'bild'", ['id' => $listingId]);
$bilddatei = $arbeitsverzeichnis . '/public/' . ltrim($pfad, '/');
pruefe(is_file($bilddatei), 'Bilddatei liegt in der Ablage', 'Bilddatei fehlt: ' . $bilddatei);

if (is_file($bilddatei)) {
    $inhalt = (string) file_get_contents($bilddatei);
    pruefe(!str_contains($inhalt, 'GPS'), 'Keine GPS-Spur im gespeicherten Bild', 'Im Bild steht noch "GPS"');
    $info = @getimagesize($bilddatei);
    pruefe(is_array($info) && $info['mime'] === 'image/webp', 'Bild als WebP gespeichert', 'Bild ist kein WebP');
}

// Die veroeffentlichte Anzeige muss oeffentlich sichtbar sein.
$oeffentlich = $browser->get(sprintf('/anzeige/%d/', $listingId));
pruefe($oeffentlich->status === 200, 'Detailseite antwortet mit 200', 'Detailseite antwortet mit ' . $oeffentlich->status);

// ---------------------------------------------------------------- Bericht
$serverzeit = $browser->totalDuration() - $registrierung;
$felder = $pflichtfelder + $kuerfelder;
$eingabezeit = $felder * SEKUNDEN_PRO_FELD + $bilder * SEKUNDEN_BILDAUSWAHL;
$mindestzeit = $pflichtfelder * SEKUNDEN_PRO_FELD + $bilder * SEKUNDEN_BILDAUSWAHL;

echo "\n";
echo "Abnahme Phase 4 — Anzeige anlegen\n";
echo "=================================\n\n";
echo "Art:            Pogona vitticeps (Bartagame)\n";
echo sprintf("Merkmale:       %s\n", $morphString);
echo sprintf("Anfragen:       %d\n", $browser->requestCount());
echo "\n";

foreach ($schritte as [$name, $dauer]) {
    echo sprintf("  %-34s %7.1f ms\n", $name, $dauer * 1000);
}

echo "\n";
echo "Gemessen\n";
echo sprintf("  Serverzeit ohne Registrierung    %6.2f s\n", $serverzeit);
echo sprintf("  Wanduhr des Skripts              %6.2f s\n", $gesamt);
echo "\n";
echo sprintf("Geschaetzt (%.0f s je Feld, %.0f s fuer die Bildauswahl)\n", SEKUNDEN_PRO_FELD, SEKUNDEN_BILDAUSWAHL);
echo sprintf("  Pflichtfelder                    %6d\n", $pflichtfelder);
echo sprintf("  Freiwillige Angaben              %6d\n", $kuerfelder);
echo sprintf("  Bilder                           %6d\n", $bilder);
echo sprintf("  Eingabezeit kurzer Weg           %6.2f s\n", $mindestzeit);
echo sprintf("  Eingabezeit gefahrener Weg       %6.2f s\n", $eingabezeit);
echo "\n";
echo sprintf("Summe kurzer Weg:                  %6.2f s von 120 s\n", $serverzeit + $mindestzeit);
echo sprintf("Summe gefahrener Weg:              %6.2f s von 120 s\n", $serverzeit + $eingabezeit);
echo "\n";

// Gewertet wird der Weg, den das Skript tatsaechlich gegangen ist — samt der
// freiwilligen Angaben. Wer nur die Pflichtfelder ausfuellt, ist schneller.
$bestanden = ($serverzeit + $eingabezeit) < 120.0;
echo $bestanden
    ? "Ergebnis: bestanden — die Anzeige steht innerhalb der Zeitvorgabe.\n"
    : "Ergebnis: NICHT bestanden — der Weg dauert laenger als zwei Minuten.\n";

if (!$behalten) {
    verzeichnisLeeren($arbeitsverzeichnis);
}

exit($bestanden ? 0 : 1);

// ------------------------------------------------------------- Hilfsmittel

/**
 * Ein winziger Browser: haelt das Sitzungs-Cookie, holt den CSRF-Token aus dem
 * Formular und folgt Weiterleitungen nicht automatisch, damit das Ziel pruefbar
 * bleibt.
 */
final class SmokeBrowser
{
    private ?string $cookie = null;

    private string $csrf = '';

    private float $total = 0.0;

    private float $last = 0.0;

    private int $requests = 0;

    /**
     * @param callable(): Container $container Liefert pro Anfrage einen frischen Objektgraphen
     */
    public function __construct(private $container) {}

    public function get(string $path): Response
    {
        $response = $this->send(new Request('GET', $path, [], [], $this->headers(), [], $this->cookies(), '203.0.113.7'));

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

    public function lastDuration(): float
    {
        return $this->last;
    }

    public function totalDuration(): float
    {
        return $this->total;
    }

    public function requestCount(): int
    {
        return $this->requests;
    }

    private function send(Request $request): Response
    {
        $kernel = ($this->container)()->get(Kernel::class);

        $start = microtime(true);
        $response = $kernel->handle($request);
        $this->last = microtime(true) - $start;
        $this->total += $this->last;
        ++$this->requests;

        if ($response->status >= 500) {
            fehler(sprintf('%s %s antwortete mit %d: %s', $request->method, $request->path, $response->status, strip_tags($response->body)));
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
            fehler(sprintf('POST %s antwortete mit %d statt einer Weiterleitung: %s', $path, $response->status, strip_tags($response->body)));
        }

        $location = $response->headers['location'] ?? '';

        return is_string($location) ? $location : '';
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

/**
 * Ein JPEG mit GPS-Kennzeichnung — wie es eine Handykamera liefert.
 */
function testbildErzeugen(string $pfad): string
{
    return \Reptilienmarkt\Tests\Support\JpegWithGps::create($pfad);
}

function neueKopie(string $quelle, string $ziel): UploadedFile
{
    // Der Upload-Pfad verschiebt die Datei, deshalb pro Upload eine Kopie.
    copy($quelle, $ziel);

    return new UploadedFile($ziel, 'IMG_2026.jpg', 'image/jpeg', (int) filesize($ziel));
}

function speciesId(Container $container): int
{
    $species = $container->get(\Reptilienmarkt\Domain\Species\SpeciesRepository::class)->findBySlug('pogona-vitticeps');

    if ($species === null || $species->id === null) {
        fehler('Pogona vitticeps fehlt in der Datenbank.');
    }

    return $species->id;
}

function morphId(Container $container, string $name): int
{
    foreach ($container->get(\Reptilienmarkt\Domain\Species\MorphRepository::class)->forSpecies(speciesId($container)) as $morph) {
        if ($morph->name === $name && $morph->id !== null) {
            return $morph->id;
        }
    }

    return fehler(sprintf('Merkmal "%s" fehlt in der Datenbank.', $name));
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
