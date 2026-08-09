<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Http\Kernel;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Routing\Router;
use Reptilienmarkt\Support\Container;

#[CoversClass(Kernel::class)]
final class SecurityHeaderTest extends TestCase
{
    public function testUeberHttpFehltHsts(): void
    {
        $antwort = $this->handle(secure: false);

        // Ein Browser, der den Kopf einmal gesehen hat, spricht die Domain ein
        // Jahr lang nur noch ueber HTTPS an — auch ohne Zertifikat. Ueber eine
        // unverschluesselte Verbindung waere er ein sich selbst aussperrender
        // Fehlstart.
        self::assertArrayNotHasKey('strict-transport-security', $antwort->headers);
    }

    public function testUeberHttpsStehtHsts(): void
    {
        $antwort = $this->handle(secure: true);

        self::assertSame(
            'max-age=31536000; includeSubDomains; preload',
            $antwort->headers['strict-transport-security'] ?? null,
        );
    }

    public function testDieUebrigenSchutzkoepfeStehenImmer(): void
    {
        foreach ([false, true] as $tls) {
            $koepfe = $this->handle($tls)->headers;

            self::assertSame('nosniff', $koepfe['x-content-type-options'] ?? null);
            self::assertSame('DENY', $koepfe['x-frame-options'] ?? null);
            self::assertSame('same-origin', $koepfe['cross-origin-opener-policy'] ?? null);
            self::assertSame('same-origin', $koepfe['cross-origin-resource-policy'] ?? null);
            self::assertSame(
                'camera=(), microphone=(), geolocation=(), interest-cohort=()',
                $koepfe['permissions-policy'] ?? null,
            );
        }
    }

    public function testDieRichtlinieBleibtOhneUnsafeInlineBeiSkripten(): void
    {
        $richtlinie = (string) ($this->handle(false)->headers['content-security-policy'] ?? '');

        self::assertStringContainsString("script-src 'self';", $richtlinie);
        self::assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $richtlinie);
        self::assertStringNotContainsString('unsafe-eval', $richtlinie);
    }

    /**
     * Der Kernel braucht fuer diesen Test keinen Treffer: Auch die 404-Antwort
     * laeuft durch withSecurityHeaders, und darum geht es hier.
     */
    private function handle(bool $secure): \Reptilienmarkt\Http\Message\Response
    {
        $kernel = new Kernel(new Router(), new Container());

        return $kernel->handle(new Request('GET', '/gibt-es-nicht/', secure: $secure));
    }
}
