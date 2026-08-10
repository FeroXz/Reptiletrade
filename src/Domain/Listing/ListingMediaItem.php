<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

use Reptilienmarkt\Infra\Storage\PublicImageStorage;

final readonly class ListingMediaItem
{
    public function __construct(
        public int $id,
        public int $listingId,
        public string $mediaType,
        public string $path,
        public int $sortOrder = 0,
        public bool $isPrimary = false,
        public ?int $width = null,
        public ?int $height = null,
        public ?int $byteSize = null,
        /**
         * Die tatsaechlich erzeugten Breiten, sortiert und kommagetrennt
         * ('400,800,1600'). NULL heisst "noch nicht erzeugt" — ein Bestandsbild
         * vor dem Lauf von bin/reimage.php.
         */
        public ?string $variantWidths = null,
    ) {}

    public function isImage(): bool
    {
        return $this->mediaType === 'bild';
    }

    /**
     * Die Miniatur folgt der Namenskonvention der Ablage.
     */
    public function thumbnailPath(): string
    {
        return $this->isImage() ? PublicImageStorage::thumbnailFor($this->path) : $this->path;
    }
}
