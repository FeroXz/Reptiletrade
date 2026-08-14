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
     * Hoechste Seitenzahl, aus der noch ein Versatz gerechnet wird — siehe offset().
     */
    public const int MAX_PAGE = 10000;

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

    /**
     * Der Versatz fuer die Abfrage.
     *
     * Die Seitenzahl wird hier noch einmal gedeckelt, obwohl die Adresse sie
     * bereits begrenzt: Kriterien entstehen auch aus gespeicherten Suchen, und
     * ein Wert nahe PHP_INT_MAX liesse dieses Produkt in eine Fliesskommazahl
     * kippen — der int-Rueckgabetyp braeche dann mit einem TypeError ab.
     */
    public function offset(): int
    {
        return (min(self::MAX_PAGE, max(1, $this->page)) - 1) * $this->limit();
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
     * Nimmt einzelne Filter wieder heraus.
     *
     * Das kann with() nicht, und es soll es auch nicht koennen: Dort heisst
     * ein ausgelassenes Argument "unveraendert lassen" — genau davon leben die
     * Facettenlinks, die einen Wert ergaenzen, ohne die uebrigen zu kennen.
     * Ein null waere dort nicht von "nicht angegeben" zu unterscheiden.
     *
     * Die Filterchips ueber der Trefferliste brauchen das Gegenteil: einen
     * gesetzten Wert loswerden. Deshalb eine zweite Methode mit Schaltern
     * statt Werten. Sie setzt immer auf Seite 1 zurueck — nach dem Entfernen
     * eines Filters gibt es mehr Treffer, und die alte Seitenzahl zeigt dann
     * auf eine andere Stelle der Liste als die, von der man kam.
     */
    public function without(
        bool $query = false,
        bool $species = false,
        bool $morphs = false,
        bool $sexes = false,
        bool $types = false,
        bool $cbStatuses = false,
        bool $countries = false,
        bool $handovers = false,
        bool $price = false,
        bool $age = false,
        bool $imageOnly = false,
        bool $radius = false,
        bool $region = false,
    ): self {
        return new self(
            $query ? null : $this->query,
            $species ? null : $this->speciesId,
            // Merkmale haengen an der Art. Faellt die Art weg, bezeichnet eine
            // Morph-Id nichts mehr, was der Nutzer ausgewaehlt haette — die
            // Facettenliste zeigt sie dann gar nicht mehr an.
            $morphs || $species ? [] : $this->morphs,
            $sexes ? [] : $this->sexes,
            $types ? [] : $this->types,
            $cbStatuses ? [] : $this->cbStatuses,
            $countries ? [] : $this->countries,
            $handovers ? [] : $this->handovers,
            $price ? null : $this->priceMinCents,
            $price ? null : $this->priceMaxCents,
            $age ? null : $this->ageMinMonths,
            $age ? null : $this->ageMaxMonths,
            !$imageOnly && $this->withImageOnly,
            $radius ? null : $this->radius,
            $region ? null : $this->admin1,
            // Die Sortierung bleibt stehen. Faellt mit dem Umkreis ihre
            // Grundlage weg, faengt effectiveSort() das ohnehin ab.
            $this->sort,
            1,
            $this->perPage,
        );
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
