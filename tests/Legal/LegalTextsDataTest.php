<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Legal;

use PHPUnit\Framework\TestCase;

/**
 * Haelt Regelwerk und Textbestand zusammen: Jeder in config/legal_rules.php
 * referenzierte Textschluessel muss in data/legal_texts.json existieren, sonst
 * sieht der Nutzer im Betrieb einen Platzhalter.
 */
final class LegalTextsDataTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private static function texts(): array
    {
        /** @var array{texte: list<array<string, mixed>>} $data */
        $data = json_decode(
            (string) file_get_contents(\dirname(__DIR__, 2) . '/data/legal_texts.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        return $data['texte'];
    }

    /**
     * @return list<string>
     */
    private static function configuredTextKeys(): array
    {
        /** @var array{rules: array<string, array<string, mixed>>} $config */
        $config = require \dirname(__DIR__, 2) . '/config/legal_rules.php';

        $keys = [];
        foreach ($config['rules'] as $rule) {
            $key = $rule['text_key'] ?? null;
            if (\is_string($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public function testJedeRegelHatEinenTextschluessel(): void
    {
        self::assertCount(8, self::configuredTextKeys());
    }

    public function testJederTextschluesselAusDemRegelwerkExistiert(): void
    {
        $available = array_column(self::texts(), 'key');

        foreach (self::configuredTextKeys() as $key) {
            self::assertContains($key, $available, \sprintf('Rechtstext "%s" fehlt in data/legal_texts.json.', $key));
        }
    }

    public function testSchluesselSindEindeutig(): void
    {
        $pairs = array_map(
            static fn(array $text): string => (string) $text['key'] . '|' . (string) ($text['jurisdiction'] ?? 'DE'),
            self::texts(),
        );

        self::assertSame($pairs, array_values(array_unique($pairs)));
    }

    public function testTexteSindInhaltlichGefuellt(): void
    {
        foreach (self::texts() as $text) {
            $key = (string) $text['key'];

            self::assertNotSame('', trim((string) $text['title']), \sprintf('%s ohne Titel.', $key));
            self::assertGreaterThan(80, mb_strlen((string) $text['body']), \sprintf('%s hat einen sehr kurzen Text.', $key));
            self::assertNotSame(
                '',
                trim((string) ($text['source_reference'] ?? '')),
                \sprintf('%s ohne Fundstelle — die braucht die Redaktion beim Prüfen.', $key),
            );
        }
    }

    /**
     * Die Ausgangstexte duerfen kein Pruefdatum mitbringen: Sie sollen im Admin
     * als "nie geprueft" auftauchen, bis sie jemand freigegeben hat.
     */
    public function testAusgangstexteTragenKeinPruefdatum(): void
    {
        foreach (self::texts() as $text) {
            self::assertArrayNotHasKey('last_reviewed_at', $text);
        }
    }
}
