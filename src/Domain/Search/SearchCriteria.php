<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Search;

use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Listing\CbStatus;
use Reptilienmarkt\Domain\Listing\Handover;
use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Domain\Listing\Sex;

/**
 * Der vollstaendige Suchzustand. Unveraenderlich: Jede Filteraenderung erzeugt
 * ein neues Objekt, damit sich Facettenlinks ("dieser Filter zusaetzlich")
 * gefahrlos ableiten lassen.
 */
final readonly class SearchCriteria
{
    public const int MAX_PER_PAGE = 96;

    /**
     * @param list<MorphFilter>  $morphs
     * @param list<Sex>          $sexes
     * @param list<ListingType>  $types
     * @param list<CbStatus>     $cbStatuses
     * @param list<Country>      $countries
     * @param list<Handover>     $handovers
     */
    public function __construct(
        public ?string $query = null,
        public ?int $speciesId = null,
        public array $morphs = [],
        public array $sexes = [],
        public array $types = [],
        public array $cbStatuses = [],
        public array $countries = [],
        public array $handovers = [],
        public ?int $priceMinCents = null,
        public ?int $priceMaxCents = null,
        public ?int $ageMinMonths = null,
        public ?int $ageMaxMonths = null,
        public bool $withImageOnly = false,
        public ?RadiusFilter $radius = null,
        public ?string $admin1 = null,
        public SortOrder $sort = SortOrder::Neueste,
        public int $page = 1,
        public int $perPage = 24,
    ) {}

    public function hasQuery(): bool
    {
        return $this->query !== null && trim($this->query) !== '';
    }

    public function hasRadius(): bool
    {
        return $this->radius !== null;
    }

    /**
     * Die tatsaechlich verwendete Sortierung. Entfernung ohne Standort und
     * Relevanz ohne Suchbegriff fallen auf "neueste" zurueck.
     */
    public function effectiveSort(): SortOrder
    {
        return $this->sort->isAvailable($this->hasRadius(), $this->hasQuery())
            ? $this->sort
            : SortOrder::Neueste;
    }

    public function offset(): int
    {
        return (max(1, $this->page) - 1) * $this->limit();
    }

    public function limit(): int
    {
        return min(self::MAX_PER_PAGE, max(1, $this->perPage));
    }

    /**
     * Greift ueberhaupt ein Filter? Steuert die Anzeige des "Filter zuruecksetzen"-Knopfs.
     */
    public function isFiltered(): bool
    {
        return $this->hasQuery()
            || $this->speciesId !== null
            || $this->morphs !== []
            || $this->sexes !== []
            || $this->types !== []
            || $this->cbStatuses !== []
            || $this->countries !== []
            || $this->handovers !== []
            || $this->priceMinCents !== null
            || $this->priceMaxCents !== null
            || $this->ageMinMonths !== null
            || $this->ageMaxMonths !== null
            || $this->withImageOnly
            || $this->radius !== null
            || $this->admin1 !== null;
    }

    public function withPage(int $page): self
    {
        return $this->with(page: $page);
    }

    public function withSort(SortOrder $sort): self
    {
        return $this->with(sort: $sort, page: 1);
    }

    /**
     * Kopiert die Kriterien mit einzelnen Aenderungen. Nicht angegebene
     * Parameter behalten ihren Wert.
     *
     * @param list<MorphFilter>|null  $morphs
     * @param list<Sex>|null          $sexes
     * @param list<ListingType>|null  $types
     * @param list<CbStatus>|null     $cbStatuses
     * @param list<Country>|null      $countries
     * @param list<Handover>|null     $handovers
     */
    public function with(
        ?string $query = null,
        ?int $speciesId = null,
        ?array $morphs = null,
        ?array $sexes = null,
        ?array $types = null,
        ?array $cbStatuses = null,
        ?array $countries = null,
        ?array $handovers = null,
        ?RadiusFilter $radius = null,
        ?string $admin1 = null,
        ?SortOrder $sort = null,
        ?int $page = null,
        ?int $perPage = null,
    ): self {
        return new self(
            $query ?? $this->query,
            $speciesId ?? $this->speciesId,
            $morphs ?? $this->morphs,
            $sexes ?? $this->sexes,
            $types ?? $this->types,
            $cbStatuses ?? $this->cbStatuses,
            $countries ?? $this->countries,
            $handovers ?? $this->handovers,
            $this->priceMinCents,
            $this->priceMaxCents,
            $this->ageMinMonths,
            $this->ageMaxMonths,
            $this->withImageOnly,
            $radius ?? $this->radius,
            $admin1 ?? $this->admin1,
            $sort ?? $this->sort,
            $page ?? $this->page,
            $perPage ?? $this->perPage,
        );
    }
}
