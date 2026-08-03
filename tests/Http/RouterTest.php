<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Http\Controller\MarketController;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Routing\Route;
use Reptilienmarkt\Http\Routing\Router;

#[CoversClass(Router::class)]
#[CoversClass(Route::class)]
#[CoversClass(Request::class)]
final class RouterTest extends TestCase
{
    private function router(): Router
    {
        /** @var Router $router */
        $router = require \dirname(__DIR__, 2) . '/config/routes.php';

        return $router;
    }

    private function request(string $path, string $method = 'GET'): Request
    {
        return new Request($method, $path);
    }

    public function testMarktpfadOhneParameter(): void
    {
        $match = $this->router()->match($this->request('/markt/'));

        self::assertNotNull($match);
        self::assertSame('markt', $match->route->name);
        self::assertSame([], $match->attributes);
    }

    public function testSeoPfadLiefertAlleSegmente(): void
    {
        $match = $this->router()->match($this->request('/markt/bartagame/red-hypo-translucent/bayern/'));

        self::assertNotNull($match);
        self::assertSame(MarketController::class, $match->route->controller);
        self::assertSame([
            'art' => 'bartagame',
            'morphs' => 'red-hypo-translucent',
            'region' => 'bayern',
        ], $match->attributes);
    }

    public function testArtenprofil(): void
    {
        $match = $this->router()->match($this->request('/art/pogona-vitticeps/'));

        self::assertNotNull($match);
        self::assertSame('art', $match->route->name);
        self::assertSame(['slug' => 'pogona-vitticeps'], $match->attributes);
    }

    public function testUnbekannterPfadTrifftNicht(): void
    {
        self::assertNull($this->router()->match($this->request('/gibt-es-nicht/')));
        self::assertFalse($this->router()->pathExists('/gibt-es-nicht/'));
    }

    public function testFalscheMethodeIstKeinTreffer(): void
    {
        $router = $this->router();

        self::assertNull($router->match($this->request('/markt/', 'POST')));
        self::assertTrue($router->pathExists('/markt/'), 'Der Pfad existiert — die Antwort muss 405 sein, nicht 404.');
    }

    public function testPlatzhalterEndetAmSchraegstrich(): void
    {
        // Ein Slug darf keinen weiteren Pfadabschnitt verschlucken.
        $match = $this->router()->match($this->request('/art/pogona/vitticeps/'));

        self::assertNull($match);
    }

    public function testHeadWirdWieGetBehandelt(): void
    {
        self::assertNotNull($this->router()->match($this->request('/markt/', 'HEAD')));
    }

    public function testAnfrageLiestMehrfachParameter(): void
    {
        $request = new Request('GET', '/markt/', [
            'geschlecht' => ['w', 'm'],
            'typ' => 'verkauf,tausch',
            'seite' => '3',
            'mit_bild' => '1',
            'leer' => '  ',
        ]);

        self::assertSame(['w', 'm'], $request->queryList('geschlecht'));
        self::assertSame(['verkauf', 'tausch'], $request->queryList('typ'));
        self::assertSame(3, $request->queryInt('seite'));
        self::assertTrue($request->queryBool('mit_bild'));
        self::assertNull($request->queryString('leer'));
        self::assertSame('vorgabe', $request->queryString('fehlt', 'vorgabe'));
    }

    public function testApiPfadeWollenJson(): void
    {
        self::assertTrue((new Request('GET', '/api/v1/listings'))->wantsJson());
        self::assertFalse((new Request('GET', '/markt/'))->wantsJson());
        self::assertTrue((new Request('GET', '/markt/', [], [], ['accept' => 'application/json']))->wantsJson());
    }
}
