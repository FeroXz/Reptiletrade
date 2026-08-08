<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

/**
 * Der offene Blockzustand beim Zeilenlesen im MarkdownRenderer.
 *
 * Ein Aufzaehlungstyp statt vier Zeichenketten: Ein Tippfehler in 'quote'
 * faellt sonst erst auf, wenn ein Zitat als Absatz erscheint — und das sieht
 * niemand im Test, sondern jemand auf der fertigen Seite.
 */
enum BlockMode
{
    case Paragraph;
    case UnorderedList;
    case OrderedList;
    case Quote;
}
