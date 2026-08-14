<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Reptilienmarkt\Domain\Content\ContentEntryRepository;
use Reptilienmarkt\Domain\Seo\SitemapRepository;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Support\Clock;

/**
 * sitemap.xml und robots.txt — erzeugt, nicht gespeichert.
 *
 * Eine Datei auf der Platte muesste nach jeder Aenderung neu geschrieben
 * werden, und der Moment, in dem das ausfaellt, faellt niemandem auf. Die
 * Erzeugung kostet bei dieser Groessenordnung wenige Millisekunden; damit sie
 * bei einem eifrigen Suchmaschinen-Roboter nicht doch ins Gewicht faellt,
 * beantwortet sie If-None-Match mit 304 und traegt einen Cache-Header.
 */
final readonly class SitemapController
{
    /**
     * Ab dieser Zahl schreibt das Protokoll einen Sitemap-Index vor. 50 000
     * waere die Grenze; 5 000 ist frueher, weil eine Datei mit fuenftausend
     * Adressen schon ein Megabyte gross ist und sich schlechter uebertraegt als
     * mehrere kleine.
     */
    public const int MAX_URLS = 5000;

    public function __construct(
        private ContentEntryRepository $entries,
        private SitemapRepository $sources,
        private Clock $clock,
        private string $baseUrl = 'https://example.tld',
    ) {}

    /**
     * GET /sitemap.xml
     *
     * Ab MAX_URLS ein Index, der auf /sitemap.xml?teil=N verweist.
     */
    public function sitemap(Request $request): Response
    {
        $urls = $this->urls();
        $basis = rtrim($this->baseUrl, '/');
        $teil = $request->queryInt('teil');

        if ($teil === null && \count($urls) > self::MAX_URLS) {
            return $this->xml($this->index($urls, $basis), $urls);
        }

        // Die Teilnummer wird wie eine Seitenzahl gedeckelt: Ohne Obergrenze
        // kippt (Teil - 1) * MAX_URLS bei einem Wert nahe PHP_INT_MAX in eine
        // Fliesskommazahl, und array_slice() nimmt keinen Float als Versatz.
        $seite = $teil === null
            ? $urls
            : \array_slice($urls, (min(Request::MAX_PAGE, max(1, $teil)) - 1) * self::MAX_URLS, self::MAX_URLS);

        return $this->xml($this->urlSet($seite, $basis), $seite, $request);
    }

    /**
     * GET /robots.txt
     *
     * Erzeugt, mit Verweis auf die Sitemap. Die Verwaltungs- und Kontopfade
     * stehen darin — nicht als Schutz (dafuer gibt es die Controller), sondern
     * damit ein Roboter seine Zeit nicht mit Seiten verbringt, die er ohnehin
     * nur als Weiterleitung zur Anmeldung sieht.
     */
    public function robots(Request $request): Response
    {
        $zeilen = [
            'User-agent: *',
            'Disallow: /admin/',
            'Disallow: /konto/',
            'Disallow: /postfach/',
            'Disallow: /moderation/',
            'Disallow: /vorschau/',
            'Disallow: /meine-anzeigen/',
            'Disallow: /api/',
            'Disallow: /nachweis/',
            '',
            'Sitemap: ' . rtrim($this->baseUrl, '/') . '/sitemap.xml',
            '',
        ];

        return new Response(implode("\n", $zeilen), 200, [
            'content-type' => 'text/plain; charset=utf-8',
            'cache-control' => 'public, max-age=3600',
        ]);
    }

    /**
     * Alle oeffentlichen Adressen: Einstiege, Inhaltsseiten, Anzeigen,
     * Artenprofile und Zuechterseiten — jeweils mit Aenderungsdatum, soweit es
     * eines gibt.
     *
     * @return list<array{loc: string, lastmod: ?DateTimeImmutable}>
     */
    private function urls(): array
    {
        $urls = [
            ['loc' => '/', 'lastmod' => null],
            ['loc' => '/markt/', 'lastmod' => null],
            ['loc' => '/news/', 'lastmod' => null],
        ];

        foreach ($this->entries->allPublished() as $entry) {
            if ($entry->noindex) {
                // Was noindex traegt, gehoert nicht in die Sitemap: Sie ist
                // eine Einladung, und beides zugleich zu sagen ist ein
                // Widerspruch, den Suchmaschinen als Fehler melden.
                continue;
            }

            $urls[] = ['loc' => $entry->path, 'lastmod' => $entry->updatedAt ?? $entry->publishedAt];
        }

        foreach ([$this->sources->listings(), $this->sources->species(), $this->sources->breeders()] as $gruppe) {
            foreach ($gruppe as $url) {
                $urls[] = ['loc' => $url->loc, 'lastmod' => $url->lastmod];
            }
        }

        return $urls;
    }

    /**
     * @param list<array{loc: string, lastmod: ?DateTimeImmutable}> $urls
     */
    private function urlSet(array $urls, string $basis): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $url) {
            $xml .= '  <url><loc>' . htmlspecialchars($basis . $url['loc'], \ENT_XML1) . '</loc>';

            if ($url['lastmod'] !== null) {
                $xml .= '<lastmod>' . $url['lastmod']->format('Y-m-d') . '</lastmod>';
            }

            $xml .= "</url>\n";
        }

        return $xml . "</urlset>\n";
    }

    /**
     * @param list<array{loc: string, lastmod: ?DateTimeImmutable}> $urls
     */
    private function index(array $urls, string $basis): string
    {
        $teile = (int) ceil(\count($urls) / self::MAX_URLS);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        for ($teil = 1; $teil <= $teile; ++$teil) {
            $xml .= '  <sitemap><loc>' . htmlspecialchars($basis . '/sitemap.xml?teil=' . $teil, \ENT_XML1) . '</loc></sitemap>' . "\n";
        }

        return $xml . "</sitemapindex>\n";
    }

    /**
     * @param list<array{loc: string, lastmod: ?DateTimeImmutable}> $urls
     */
    private function xml(string $body, array $urls, ?Request $request = null): Response
    {
        $letzte = null;

        foreach ($urls as $url) {
            if ($url['lastmod'] !== null && ($letzte === null || $url['lastmod'] > $letzte)) {
                $letzte = $url['lastmod'];
            }
        }

        $etag = '"' . substr(hash('sha256', $body), 0, 32) . '"';

        $headers = [
            'content-type' => 'application/xml; charset=utf-8',
            'cache-control' => 'public, max-age=3600',
            'etag' => $etag,
            'last-modified' => ($letzte ?? $this->clock->now())->setTimezone(new DateTimeZone('UTC'))
                ->format('D, d M Y H:i:s') . ' GMT',
        ];

        if ($request !== null && ($request->headers['if-none-match'] ?? '') === $etag) {
            return new Response('', 304, $headers);
        }

        return new Response($body, 200, $headers);
    }
}
