<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal;

/**
 * Der Hinweis, der im Admin ueber jeder Ansicht der Rechts-Engine steht.
 */
final class Disclaimer
{
    public const string TITLE = 'Keine Rechtsberatung';

    public const string BODY = <<<'TEXT'
        Die Rechts-Engine setzt ausschließlich das Regelwerk um, das unter
        config/legal_rules.php und in der Tabelle legal_texts hinterlegt ist. Sie ersetzt
        keine rechtliche Beratung und trifft keine Aussage darüber, ob ein Verkauf im
        Einzelfall zulässig ist.

        Artenschutzrecht ändert sich laufend: CITES-Anhänge werden auf jeder
        Vertragsstaatenkonferenz angepasst, die Anhänge der EG-VO 338/97 folgen zeitversetzt,
        Gefahrtierverordnungen sind Landes- bzw. Kantonsrecht und weichen voneinander ab.

        Die Pflicht, Regelwerk, Rechtstexte und den Schutzstatus im Artenstamm zu prüfen und
        aktuell zu halten, liegt beim Betreiber der Plattform.
        TEXT;

    /**
     * @return array{title: string, body: string}
     */
    public static function toArray(): array
    {
        return ['title' => self::TITLE, 'body' => self::BODY];
    }
}
