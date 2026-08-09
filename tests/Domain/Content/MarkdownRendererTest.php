<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Content;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Domain\Content\MarkdownRenderer;

/**
 * Der Renderer gibt HTML aus, das die Templates mit |raw einsetzen. Diese
 * Testsuite ist die Gegenleistung dafuer.
 */
final class MarkdownRendererTest extends TestCase
{
    private MarkdownRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new MarkdownRenderer();
    }

    // ------------------------------------------------------------ Angriffe

    public function testSkriptTagWirdSichtbarerText(): void
    {
        $html = $this->renderer->render('<script>alert(1)</script>');

        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testJavascriptZielWirdKeinLink(): void
    {
        $html = $this->renderer->render('[Klick mich](javascript:alert(1))');

        // Kein Anker, kein href — das Ziel bleibt sichtbarer Text und wird
        // nie zu einer anklickbaren Adresse.
        self::assertStringNotContainsString('<a ', $html);
        self::assertStringNotContainsString('href=', $html);
        self::assertStringContainsString('Klick mich', $html);
    }

    public function testDataZielWirdKeinLink(): void
    {
        $html = $this->renderer->render('[X](data:text/html;base64,PHNjcmlwdD4=)');

        self::assertStringNotContainsString('<a ', $html);
        self::assertStringContainsString('X', $html);
    }

    public function testGrossgeschriebenesJavascriptZielWirdKeinLink(): void
    {
        $html = $this->renderer->render('[X](JaVaScRiPt:alert(1))');

        self::assertStringNotContainsString('<a ', $html);
    }

    public function testOnerrorAttributWirdText(): void
    {
        $html = $this->renderer->render('<img src=x onerror=alert(1)>');

        // Der Text bleibt vollstaendig lesbar, aber es entsteht kein Element:
        // ohne < gibt es kein Attribut, das ein Browser auswerten koennte.
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function testAnfuehrungszeichenBrechenNichtAusEinemAttributAus(): void
    {
        $html = $this->renderer->render('[X](/pfad" onmouseover="alert(1))');

        // Entweder gar kein Link — oder ein Ziel, in dem das Anfuehrungszeichen
        // escapet ist. Ein rohes " im href waere der Ausbruch.
        self::assertStringNotContainsString('" onmouseover="', $html);
    }

    public function testHtmlKommentarBleibtText(): void
    {
        $html = $this->renderer->render('<!-- <script>alert(1)</script> -->');

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<!--', $html);
    }

    public function testVerschachtelteAuszeichnungBleibtGeschlossen(): void
    {
        $html = $this->renderer->render('**fett mit *kursiv* darin**');

        self::assertStringContainsString('<strong>', $html);
        self::assertStringContainsString('<em>kursiv</em>', $html);
        self::assertSame(substr_count($html, '<strong>'), substr_count($html, '</strong>'));
        self::assertSame(substr_count($html, '<em>'), substr_count($html, '</em>'));
    }

    #[DataProvider('unvollstaendigeSyntax')]
    public function testUnvollstaendigeSyntaxVerliertKeinenText(string $eingabe, string $erwarteterText): void
    {
        $html = $this->renderer->render($eingabe);

        self::assertStringContainsString($erwarteterText, html_entity_decode(strip_tags($html), \ENT_QUOTES, 'UTF-8'));
    }

    /**
     * @return list<array{string, string}>
     */
    public static function unvollstaendigeSyntax(): array
    {
        return [
            ['**nie geschlossen', '**nie geschlossen'],
            ['[Text ohne Ziel', '[Text ohne Ziel'],
            ['[Text](', '[Text]('],
            ['`Code ohne Ende', '`Code ohne Ende'],
            ['> ', ''],
            ['####### zu tief', '####### zu tief'],
        ];
    }

    public function testCodeSpanneWirdNichtWeiterAusgezeichnet(): void
    {
        $html = $this->renderer->render('Beispiel: `**kein fett** <b>kein tag</b>`');

        self::assertStringContainsString('<code>', $html);
        self::assertStringNotContainsString('<strong>', $html);
        self::assertStringNotContainsString('<b>', $html);
    }

    public function testNullbyteWirdEntfernt(): void
    {
        $html = $this->renderer->render("Text\x00mit Nullbyte");

        self::assertStringNotContainsString("\x00", $html);
        self::assertStringContainsString('Textmit Nullbyte', $html);
    }

    // ------------------------------------------------------- Erlaubte Formen

    public function testUeberschriftenNurH2BisH4(): void
    {
        $html = $this->renderer->render("## Zwei\n\n### Drei\n\n#### Vier\n\n# Eins");

        self::assertStringContainsString('<h2>Zwei</h2>', $html);
        self::assertStringContainsString('<h3>Drei</h3>', $html);
        self::assertStringContainsString('<h4>Vier</h4>', $html);
        // h1 gehoert dem Seitentitel — die Zeile bleibt Text.
        self::assertStringNotContainsString('<h1>', $html);
        self::assertStringContainsString('# Eins', $html);
    }

    public function testAbsaetzeUndListen(): void
    {
        $html = $this->renderer->render("Erster Absatz.\n\n- eins\n- zwei\n\n1. a\n2. b");

        self::assertStringContainsString('<p>Erster Absatz.</p>', $html);
        self::assertStringContainsString('<ul><li>eins</li><li>zwei</li></ul>', $html);
        self::assertStringContainsString('<ol><li>a</li><li>b</li></ol>', $html);
    }

    public function testZitat(): void
    {
        $html = $this->renderer->render('> Ein Satz aus der Quelle.');

        self::assertStringContainsString('<blockquote><p>Ein Satz aus der Quelle.</p></blockquote>', $html);
    }

    public function testExterneLinksBekommenRelAttribut(): void
    {
        $html = $this->renderer->render('[Behörde](https://www.example.tld/seite)');

        self::assertStringContainsString('href="https://www.example.tld/seite"', $html);
        self::assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function testInterneLinksBekommenKeinRelAttribut(): void
    {
        $html = $this->renderer->render('[Markt](/markt/)');

        self::assertStringContainsString('href="/markt/"', $html);
        self::assertStringNotContainsString('rel=', $html);
    }

    public function testMailtoIstErlaubt(): void
    {
        self::assertStringContainsString('<a href="mailto:info@example.tld"', $this->renderer->render('[Mail](mailto:info@example.tld)'));
    }

    public function testLinkMitCodeInDerBeschriftung(): void
    {
        // Der Platzhalter des Codes steckt in dem des Links — beide muessen
        // zurueckkommen.
        $html = $this->renderer->render('[`bin/doctor.php`](/hilfe/)');

        self::assertStringContainsString('<a href="/hilfe/"><code>bin/doctor.php</code></a>', $html);
        self::assertStringNotContainsString("\x00", $html);
    }

    public function testMehrzeiligerAbsatzWirdZusammengefasst(): void
    {
        $html = $this->renderer->render("Erste Zeile\nzweite Zeile");

        self::assertStringContainsString('<p>Erste Zeile zweite Zeile</p>', $html);
    }

    public function testUnterstricheInWoerternBleibenStehen(): void
    {
        $html = $this->renderer->render('Die Datei heisst mein_schoenes_bild.webp');

        self::assertStringNotContainsString('<em>', $html);
    }

    public function testKaufmaennischesUndWirdEinmalEscapet(): void
    {
        $html = $this->renderer->render('Fisch & Co.');

        self::assertStringContainsString('Fisch &amp; Co.', $html);
        self::assertStringNotContainsString('&amp;amp;', $html);
    }

    public function testReintextFuerAnrissUndIndex(): void
    {
        $text = $this->renderer->toPlainText("## Titel\n\nEin **wichtiger** Satz mit [Link](/markt/).");

        self::assertSame('Titel Ein wichtiger Satz mit Link.', $text);
    }

    public function testLeereEingabeErgibtLeereAusgabe(): void
    {
        self::assertSame('', $this->renderer->render(''));
        self::assertSame('', $this->renderer->render("   \n\n  "));
    }
}
