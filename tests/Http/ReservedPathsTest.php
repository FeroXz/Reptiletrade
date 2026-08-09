<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Http;

use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Domain\Content\ContentPath;
use Reptilienmarkt\Domain\Content\ReservedPaths;
use Reptilienmarkt\Http\Routing\Route;
use Reptilienmarkt\Http\Routing\Router;

/**
 * Der Abgleich zwischen Reservierungsliste und Router.
 *
 * Ohne diesen Test laeuft beides auseinander, sobald jemand eine Route
 * ergaenzt: Die Liste bliebe stehen, ein Redakteur duerfte den Slug vergeben,
 * und die Seite waere danach unerreichbar — weil die Catch-all-Route als letzte
 * steht und die neue Route vorher greift. Dieser Fehler faellt im Betrieb erst
 * auf, wenn jemand fragt, warum eine Seite nicht da ist.
 */
final class ReservedPathsTest extends TestCase
{
    public function testJedeRegistrierteRouteStehtAufDerListe(): void
    {
        $fehlend = [];

        foreach ($this->routes() as $route) {
            if ($route->name === 'inhalt.seite') {
                // Die Catch-all-Route selbst beansprucht nichts.
                continue;
            }

            $segment = ContentPath::firstSegment($this->literalPrefix($route->pattern));

            if ($segment === '' || \in_array($segment, ReservedPaths::all(), true)) {
                continue;
            }

            $fehlend[$segment] = $route->pattern;
        }

        self::assertSame([], $fehlend, \sprintf(
            'Diese Routen belegen ein Pfadsegment, das nicht in ReservedPaths steht: %s. '
            . 'Ergaenze die Liste, sonst kann die Redaktion einen Slug vergeben, der nie ausgeliefert wird.',
            json_encode($fehlend, \JSON_UNESCAPED_SLASHES),
        ));
    }

    public function testDieRechtsseitenSindGesperrt(): void
    {
        // Sie speisen sich aus config/impressum.php und gehoeren in eine Datei,
        // die beim Deployment mitgeht — nicht in eine Datenbanktabelle.
        foreach (['/impressum', '/datenschutz', '/nutzungsbedingungen'] as $pfad) {
            self::assertTrue(ReservedPaths::isReserved($pfad), $pfad . ' muss gesperrt bleiben.');
        }
    }

    public function testDieCatchAllRouteStehtAlsLetzte(): void
    {
        $routes = $this->routes();
        $letzte = $routes[\count($routes) - 1];

        self::assertSame('inhalt.seite', $letzte->name);
    }

    public function testDieCatchAllRouteTrifftMehrereSegmente(): void
    {
        $route = new Route(['GET'], '/{pfad*}', self::class, 'show', 'test');

        self::assertSame(['pfad' => 'ueber-uns/team/'], $route->match('GET', '/ueber-uns/team/'));
        self::assertSame(['pfad' => 'haltung/'], $route->match('GET', '/haltung/'));
        self::assertNull($route->match('GET', '/'));
    }

    public function testEinfacherPlatzhalterBleibtAufEinSegmentBeschraenkt(): void
    {
        $route = new Route(['GET'], '/art/{slug}/', self::class, 'show', 'test');

        self::assertSame(['slug' => 'pogona-vitticeps'], $route->match('GET', '/art/pogona-vitticeps/'));
        self::assertNull($route->match('GET', '/art/a/b/'));
    }

    /**
     * Der Teil eines Musters vor dem ersten Platzhalter. Nur der ist fest
     * genug, um ein Segment zu beanspruchen.
     */
    private function literalPrefix(string $pattern): string
    {
        $brace = strpos($pattern, '{');

        return $brace === false ? $pattern : substr($pattern, 0, $brace);
    }

    /**
     * @return list<Route>
     */
    private function routes(): array
    {
        /** @var Router $router */
        $router = require \dirname(__DIR__, 2) . '/config/routes.php';

        return $router->routes();
    }
}
