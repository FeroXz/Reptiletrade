<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

/**
 * Ein bewusst kleiner Markdown-Renderer.
 *
 * Er kann: Ueberschriften h2–h4, Absatz, geordnete und ungeordnete Liste, Link,
 * fett, kursiv, Zitat, Inline-Code. Sonst nichts.
 *
 * Zwei Entscheidungen tragen alles Weitere:
 *
 * 1. **Erst escapen, dann auszeichnen.** Die Eingabe wird vollstaendig durch
 *    htmlspecialchars geschickt, bevor irgendein Muster greift. Damit kann kein
 *    Zeichen der Eingabe je zu Markup werden — ein Sanitizer, der Markup wieder
 *    einzufangen versucht, waere die umgekehrte und deutlich schlechtere
 *    Richtung.
 *
 * 2. **Unbekanntes bleibt sichtbar.** Was der Renderer nicht kennt, gibt er
 *    escapet aus statt es zu verwerfen. Verschwindender Text ist schlimmer als
 *    sichtbar falscher: Der eine faellt beim Korrekturlesen auf, der andere
 *    erst, wenn ihn jemand vermisst.
 *
 * Ausgabe ist HTML und darf in Twig mit |raw eingesetzt werden — das ist die
 * Zusicherung dieser Klasse, und tests/Domain/Content/MarkdownRendererTest
 * haelt sie mit Angriffsfaellen fest.
 */
final class MarkdownRenderer
{
    /**
     * Erlaubte Ziel-Schemata. Alles andere — allen voran javascript: und
     * data: — wird nicht zum Link, sondern bleibt sichtbarer Text.
     *
     * @var list<string>
     */
    private const array ALLOWED_SCHEMES = ['http://', 'https://', 'mailto:'];

    /** Platzhalter fuer bereits fertige Teilstuecke. \x00 kann in der Eingabe nicht vorkommen. */
    private const string PLACEHOLDER = "\x00%d\x00";

    /** @var list<string> */
    private array $fragments = [];

    public function render(string $markdown): string
    {
        $this->fragments = [];

        $html = [];
        /** @var list<string> $buffer */
        $buffer = [];
        /** @var BlockMode|null $mode */
        $mode = null;

        foreach (explode("\n", self::clean($markdown)) as $line) {
            $trimmed = rtrim($line);

            if (trim($trimmed) === '') {
                $html = $this->flush($html, $mode, $buffer);
                $buffer = [];
                $mode = null;

                continue;
            }

            // Ueberschriften: nur h2 bis h4. Ein einzelnes # bleibt Text — h1
            // ist der Seitentitel, und den vergibt nicht der Rumpf.
            if (preg_match('/^(#{2,4})\s+(.+)$/', $trimmed, $match) === 1) {
                $html = $this->flush($html, $mode, $buffer);
                $buffer = [];
                $mode = null;

                $level = \strlen($match[1]);
                $html[] = '<h' . $level . '>' . $this->inline($match[2]) . '</h' . $level . '>';

                continue;
            }

            $zeile = null;
            $neuerModus = null;

            if (preg_match('/^\s*[-*]\s+(.*)$/', $trimmed, $match) === 1) {
                $neuerModus = BlockMode::UnorderedList;
                $zeile = $match[1];
            } elseif (preg_match('/^\s*\d+\.\s+(.*)$/', $trimmed, $match) === 1) {
                $neuerModus = BlockMode::OrderedList;
                $zeile = $match[1];
            } elseif (preg_match('/^\s*>\s?(.*)$/', $trimmed, $match) === 1) {
                $neuerModus = BlockMode::Quote;
                $zeile = $match[1];
            } else {
                // Eine Zeile, die keiner Blockform folgt, gehoert zum Absatz.
                // Steht sie mitten in einer Liste, beendet sie diese — sonst
                // zoege eine vergessene Leerzeile den halben Text in ein <li>.
                $neuerModus = BlockMode::Paragraph;
                $zeile = $trimmed;
            }

            if ($mode !== $neuerModus) {
                $html = $this->flush($html, $mode, $buffer);
                $buffer = [];
                $mode = $neuerModus;
            }

            $buffer[] = $zeile;
        }

        $html = $this->flush($html, $mode, $buffer);

        return $this->restore(implode("\n", $html));
    }

    /**
     * Schliesst den offenen Block ab und haengt ihn an.
     *
     * @param list<string> $html
     * @param list<string> $buffer
     *
     * @return list<string>
     */
    private function flush(array $html, ?BlockMode $mode, array $buffer): array
    {
        if ($buffer === [] || $mode === null) {
            return $html;
        }

        $items = static fn(callable $render): string => implode('', array_map($render, $buffer));
        $li = fn(string $line): string => '<li>' . $this->inline($line) . '</li>';

        $html[] = match ($mode) {
            BlockMode::UnorderedList => '<ul>' . $items($li) . '</ul>',
            BlockMode::OrderedList => '<ol>' . $items($li) . '</ol>',
            BlockMode::Quote => '<blockquote><p>' . $this->inline(implode(' ', $buffer)) . '</p></blockquote>',
            BlockMode::Paragraph => '<p>' . $this->inline(implode(' ', $buffer)) . '</p>',
        };

        return $html;
    }

    /**
     * Nur der Text, ohne Auszeichnung — fuer Anrisse und den Volltextindex.
     */
    public function toPlainText(string $markdown): string
    {
        $text = strip_tags($this->render($markdown));

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, \ENT_QUOTES, 'UTF-8')));
    }

    /**
     * Steuerzeichen raus, Zeilenenden vereinheitlichen. \x00 wuerde sonst mit
     * den Platzhaltern kollidieren — und ein Nullbyte im Text ist ohnehin nie
     * gewollt.
     */
    private static function clean(string $markdown): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $markdown);

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $normalized);
    }

    /**
     * Die Auszeichnung innerhalb einer Zeile.
     *
     * Reihenfolge mit Absicht: escapen, dann Code-Spannen und Links in
     * Platzhalter auslagern (damit ihr Inhalt nicht noch einmal angefasst
     * wird), dann fett vor kursiv (sonst frisst ein einzelnes * die Haelfte
     * eines **).
     */
    private function inline(string $text): string
    {
        $escaped = htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        $escaped = (string) preg_replace_callback(
            '/`([^`]+)`/',
            fn(array $m): string => $this->stash('<code>' . $m[1] . '</code>'),
            $escaped,
        );

        $escaped = (string) preg_replace_callback(
            '/\[([^\]]*)\]\(([^)\s]+)\)/',
            fn(array $m): string => $this->link($m[1], $m[2]),
            $escaped,
        );

        $escaped = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $escaped);
        $escaped = (string) preg_replace('/(?<![\w*])\*(?=\S)([^*\n]+?)(?<=\S)\*(?![\w*])/', '<em>$1</em>', $escaped);
        $escaped = (string) preg_replace('/(?<![\w_])_(?=\S)([^_\n]+?)(?<=\S)_(?![\w_])/', '<em>$1</em>', $escaped);

        return $escaped;
    }

    /**
     * Baut einen Link — oder gibt das Geschriebene unveraendert zurueck.
     *
     * Ein abgewiesenes Ziel loescht den Text nicht: [Klick](javascript:…)
     * erscheint als genau diese Zeichenfolge. Der Redakteur sieht damit, dass
     * etwas nicht angenommen wurde, statt sich zu wundern, wo sein Link ist.
     */
    private function link(string $label, string $target): string
    {
        // htmlspecialchars hat & bereits zu &amp; gemacht; fuer die
        // Schemapruefung zaehlt der geschriebene Wert.
        $url = html_entity_decode($target, \ENT_QUOTES, 'UTF-8');
        $lower = strtolower(ltrim($url));

        $isRelative = str_starts_with($url, '/') || str_starts_with($url, '#');
        $isAllowed = $isRelative;

        foreach (self::ALLOWED_SCHEMES as $scheme) {
            if (str_starts_with($lower, $scheme)) {
                $isAllowed = true;

                break;
            }
        }

        if (!$isAllowed) {
            return '[' . $label . '](' . $target . ')';
        }

        // Externe Ziele bekommen rel="noopener noreferrer": Ohne noopener kann
        // die Zielseite ueber window.opener auf den Tab zurueckgreifen.
        $attributes = $isRelative ? '' : ' rel="noopener noreferrer"';

        return $this->stash('<a href="' . $target . '"' . $attributes . '>' . $label . '</a>');
    }

    private function stash(string $html): string
    {
        $this->fragments[] = $html;

        return \sprintf(self::PLACEHOLDER, \count($this->fragments) - 1);
    }

    /**
     * Rueckwaerts, weil ein spaeter ausgelagertes Teilstueck ein frueheres
     * enthalten kann — ein Link, dessen Beschriftung Inline-Code traegt. Wer
     * vorwaerts einsetzt, ersetzt den Platzhalter des Codes, bevor der Link
     * ueberhaupt im Text steht, und laesst ihn danach stehen.
     */
    private function restore(string $html): string
    {
        for ($index = \count($this->fragments) - 1; $index >= 0; --$index) {
            $html = str_replace(\sprintf(self::PLACEHOLDER, $index), $this->fragments[$index], $html);
        }

        return $html;
    }
}
