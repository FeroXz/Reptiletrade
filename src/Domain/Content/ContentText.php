<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

/**
 * Der durchsuchbare Reintext eines Rumpfes.
 *
 * Eigene Klasse, weil zwei Stellen ihn brauchen: der Renderer (fuer Anrisse)
 * und der Volltextindex. Zwei Umsetzungen waeren zwei Wahrheiten ueber
 * denselben Text — und die Suche faende dann Woerter, die auf der Seite nicht
 * stehen, oder umgekehrt.
 */
final readonly class ContentText
{
    public function __construct(private MarkdownRenderer $markdown) {}

    /**
     * @param list<ContentBlock> $blocks
     */
    public function plainText(array $blocks): string
    {
        $parts = [];

        foreach ($blocks as $block) {
            $text = $block->searchText();

            if ($text !== '') {
                $parts[] = $this->markdown->toPlainText($text);
            }
        }

        return trim(implode("\n", $parts));
    }
}
