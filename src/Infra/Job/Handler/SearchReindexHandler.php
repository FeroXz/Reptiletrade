<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Job\Handler;

use Reptilienmarkt\Domain\Job\Job;
use Reptilienmarkt\Domain\Job\JobHandler;
use Reptilienmarkt\Infra\Search\ListingIndexer;

/**
 * Baut den Volltextindex neu auf.
 *
 * Laeuft nicht regelmaessig, sondern nach Aenderungen am Artenstamm oder am
 * Merkmalskatalog: Deren Namen stehen im Index, und eine Umbenennung erreicht
 * ihn sonst nie.
 */
final readonly class SearchReindexHandler implements JobHandler
{
    public function __construct(private ListingIndexer $indexer) {}

    public function type(): string
    {
        return 'search.reindex';
    }

    public function handle(Job $job): string
    {
        return \sprintf('%d Anzeigen neu indexiert', $this->indexer->rebuildAll());
    }
}
