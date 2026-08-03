<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Trust;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Domain\Trust\FraudKeywordFilter;
use Reptilienmarkt\Domain\Trust\KeywordMode;
use Reptilienmarkt\Domain\Trust\KeywordRule;
use Reptilienmarkt\Domain\Trust\TrustConfiguration;

#[CoversClass(FraudKeywordFilter::class)]
#[CoversClass(KeywordRule::class)]
#[CoversClass(TrustConfiguration::class)]
final class FraudKeywordFilterTest extends TestCase
{
    /**
     * Der Filter aus der echten Konfiguration — ein Test gegen erfundene
     * Wortlisten wuerde nichts ueber den Betrieb aussagen.
     */
    private function filter(): FraudKeywordFilter
    {
        /** @var array<string, mixed> $config */
        $config = require \dirname(__DIR__, 3) . '/config/trust.php';

        return (new TrustConfiguration($config))->keywordFilter();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function verdaechtigeTexte(): iterable
    {
        yield 'Vorkasse' => ['Bitte Vorkasse per Überweisung, dann schicke ich das Tier.', 'vorkasse'];
        yield 'Western Union' => ['Zahlung bitte über Western Union.', 'unsichere_zahlung'];
        yield 'PayPal Freunde' => ['Schick es als PayPal Freunde, dann sparen wir Gebühren.', 'unsichere_zahlung'];
        yield 'Versand' => ['Das Tier wird versendet, ganz unkompliziert.', 'versand'];
        yield 'Kanalwechsel' => ['Schreib mir auf WhatsApp, hier lese ich nicht.', 'kanalwechsel'];
        yield 'Zeitdruck' => ['Das muss heute noch entschieden werden.', 'notlage'];
    }

    #[DataProvider('verdaechtigeTexte')]
    public function testVerdaechtigeFormulierungenWerdenErkannt(string $text, string $regel): void
    {
        $urteil = $this->filter()->inspect($text);

        self::assertFalse($urteil->isClean());
        self::assertContains($regel, array_column(
            array_map(static fn(object $treffer): array => ['key' => $treffer->ruleKey], $urteil->matches),
            'key',
        ));
    }

    public function testNurUnsichereZahlungswegeSperren(): void
    {
        // Ein Wortfilter ist ein Verdacht. Gesperrt wird nur, wo das Geld
        // nachweislich nicht zurueckkommt.
        self::assertTrue($this->filter()->inspect('Zahlung über Western Union bitte.')->blocked);
        self::assertFalse($this->filter()->inspect('Ich hätte gern Vorkasse.')->blocked);
        self::assertTrue($this->filter()->inspect('Ich hätte gern Vorkasse.')->flagged);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function harmloseTexte(): iterable
    {
        yield 'Frage nach Abholung' => ['Wann kann ich das Tier bei dir abholen?'];
        yield 'Fütterung' => ['Frisst sie schon Heimchen oder noch Micros?'];
        yield 'Genetik' => ['Ist der Vater visual Hypo oder nur het?'];
        yield 'Termin' => ['Samstag um 14 Uhr würde mir passen.'];
    }

    #[DataProvider('harmloseTexte')]
    public function testAlltagsnachrichtenBleibenUnauffaellig(string $text): void
    {
        $urteil = $this->filter()->inspect($text);

        self::assertTrue($urteil->isClean(), 'Fehlalarm bei: ' . $text);
        self::assertFalse($urteil->blocked);
        self::assertNull($urteil->reasonSummary());
    }

    public function testTrefferNurAnWortgrenzen(): void
    {
        $regel = new KeywordRule('test', KeywordMode::Markieren, 'Test', ['bar']);

        self::assertSame(['bar'], $regel->matches('Zahlung an der Bar.'));
        self::assertSame([], $regel->matches('Die Bartagame frisst gut.'));
    }

    public function testGrossschreibungUndZusaetzlicheLeerzeichenAendernNichts(): void
    {
        $urteil = $this->filter()->inspect("WESTERN    UNION\nbitte");

        self::assertTrue($urteil->blocked);
    }

    public function testDieSperrmeldungVerraetDieWortlisteNicht(): void
    {
        $urteil = $this->filter()->inspect('Zahlung über Western Union.');

        self::assertStringNotContainsString('Western Union', $urteil->senderMessage());
    }

    public function testAuditNenntDieRegelnAberNichtDenText(): void
    {
        $payload = $this->filter()->inspect('Vorkasse per Western Union')->auditPayload();

        self::assertTrue($payload['gesperrt']);
        self::assertContains('unsichere_zahlung', $payload['regeln']);
    }
}
