<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

/**
 * Wo ein Medium verwendet wird.
 */
enum MediaUsageContext: string
{
    case Block = 'content_block';
    case EntryOg = 'entry_og';
    case Menu = 'menu';

    public function label(): string
    {
        return match ($this) {
            self::Block => 'Im Inhalt',
            self::EntryOg => 'Als Vorschaubild',
            self::Menu => 'Im Menü',
        };
    }
}
