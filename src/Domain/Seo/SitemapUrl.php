<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Seo;

use DateTimeImmutable;

/**
 * Eine Adresse fuer die Sitemap: der Pfad und wann sich dahinter zuletzt etwas
 * geaendert hat.
 */
final readonly class SitemapUrl
{
    public function __construct(
        public string $loc,
        public ?DateTimeImmutable $lastmod = null,
    ) {}
}
