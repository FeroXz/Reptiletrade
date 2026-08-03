<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Search;

use Reptilienmarkt\Domain\Search\SearchCriteria;

final readonly class ParsedSearch
{
    public function __construct(
        public SearchCriteria $criteria,
        public SearchUrlContext $urlContext,
    ) {}
}
