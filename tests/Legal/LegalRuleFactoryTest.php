<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Legal;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Domain\Setting\ArraySettings;
use Reptilienmarkt\Legal\LegalConfigurationException;
use Reptilienmarkt\Legal\LegalRuleFactory;
use Reptilienmarkt\Tests\Support\FrozenClock;

/**
 * Ein Tippfehler im Regelwerk darf nicht als stillschweigend abgeschaltete
 * Regel enden — die Fabrik muss laut scheitern.
 */
#[CoversClass(LegalRuleFactory::class)]
final class LegalRuleFactoryTest extends TestCase
{
    private function factory(): LegalRuleFactory
    {
        return new LegalRuleFactory(
            new FrozenClock(new DateTimeImmutable('2026-08-02T12:00:00+00:00')),
            new ArraySettings(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function realConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = require \dirname(__DIR__, 2) . '/config/legal_rules.php';

        return $config;
    }

    public function testDasMitgelieferteRegelwerkLaesstSichBauen(): void
    {
        $rules = $this->factory()->fromConfig($this->realConfig());

        self::assertCount(8, $rules, 'Das Regelwerk umfasst acht Regeln.');
    }

    public function testAbgeschalteteRegelWirdUebersprungen(): void
    {
        $config = $this->realConfig();
        /** @var array<string, array<string, mixed>> $rules */
        $rules = $config['rules'];
        $rules['gefahrtier']['enabled'] = false;
        $config['rules'] = $rules;

        $keys = array_map(
            static fn($rule): string => $rule->key(),
            $this->factory()->fromConfig($config),
        );

        self::assertNotContains('gefahrtier', $keys);
        self::assertCount(7, $keys);
    }

    public function testFehlenderRulesAbschnittScheitert(): void
    {
        $this->expectException(LegalConfigurationException::class);
        $this->expectExceptionMessageMatches('/rules/');

        $this->factory()->fromConfig(['review_max_age_months' => 12]);
    }

    public function testUnbekannteRegelScheitert(): void
    {
        $this->expectException(LegalConfigurationException::class);
        $this->expectExceptionMessageMatches('/Unbekannte Regel/');

        $this->factory()->fromConfig(['rules' => ['artenschutz_deluxe' => ['enabled' => true]]]);
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('fehlerhafteOptionen')]
    public function testFehlerhafteOptionScheitert(string $rule, array $options, string $messagePattern): void
    {
        $this->expectException(LegalConfigurationException::class);
        $this->expectExceptionMessageMatches($messagePattern);

        $this->factory()->fromConfig(['rules' => [$rule => $options]]);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function fehlerhafteOptionen(): iterable
    {
        yield 'unbekannter EU-Anhang' => [
            'anhang_a',
            ['annexes' => ['Z'], 'bnatschg_statuses' => [], 'required_fields' => [], 'text_key' => 'x'],
            '/EU-Anhang "Z"/',
        ];

        yield 'unbekannter Schutzstatus' => [
            'anhang_a',
            ['annexes' => ['A'], 'bnatschg_statuses' => ['sehr_streng'], 'required_fields' => [], 'text_key' => 'x'],
            '/Schutzstatus "sehr_streng"/',
        ];

        yield 'fehlender Textschluessel' => [
            'versand',
            ['allowed_countries' => ['DE']],
            '/text_key/',
        ];

        yield 'leerer Textschluessel' => [
            'versand',
            ['allowed_countries' => ['DE'], 'text_key' => ''],
            '/text_key/',
        ];

        yield 'unbekanntes Land' => [
            'versand',
            ['allowed_countries' => ['FR'], 'text_key' => 'x'],
            '/Land "FR"/',
        ];

        yield 'unbekannter Gefahrtiermodus' => [
            'gefahrtier',
            ['default_mode' => 'streng', 'setting_key' => 's', 'text_key' => 'x'],
            '/Modus "streng"/',
        ];

        yield 'unbekannter Modus in einer Region' => [
            'gefahrtier',
            [
                'default_mode' => 'warnung',
                'regions' => ['DE' => ['Bayern' => 'verboten']],
                'setting_key' => 's',
                'text_key' => 'x',
            ],
            '/Modus "verboten"/',
        ];

        yield 'unbekanntes Land in regions' => [
            'gefahrtier',
            [
                'default_mode' => 'warnung',
                'regions' => ['FR' => ['Elsass' => 'sperre']],
                'setting_key' => 's',
                'text_key' => 'x',
            ],
            '/Land "FR"/',
        ];

        yield 'Schwellwert ist keine Zahl' => [
            'gewerblichkeit',
            [
                'active_listing_threshold' => 'zehn',
                'sales_per_year_threshold' => 25,
                'required_fields' => [],
                'text_key' => 'x',
            ],
            '/ganze Zahl/',
        ];

        yield 'Liste enthaelt keine Zeichenkette' => [
            'kennzeichnung',
            [
                'genera' => ['Testudo', 42],
                'allowed_methods' => [],
                'methods_needing_code' => [],
                'method_field' => 'a',
                'code_field' => 'b',
                'text_key' => 'x',
            ],
            '/nur Zeichenketten/',
        ];

        yield 'enabled ist kein Wahrheitswert' => [
            'versand',
            ['enabled' => 'ja', 'allowed_countries' => ['DE'], 'text_key' => 'x'],
            '/true oder false/',
        ];
    }

    public function testRegionenModusWirdInEinEnumUebersetzt(): void
    {
        $rules = $this->factory()->fromConfig([
            'rules' => [
                'gefahrtier' => [
                    'default_mode' => 'sperre',
                    'regions' => ['DE' => ['Bayern' => 'warnung']],
                    'setting_key' => 'legal.gefahrtier_enforcement',
                    'text_key' => 'legal.gefahrtier',
                ],
            ],
        ]);

        self::assertCount(1, $rules);
        self::assertSame('gefahrtier', $rules[0]->key());
    }
}
