<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Storage;

final readonly class ProcessedImage
{
    public function __construct(
        public string $path,
        public string $thumbnailPath,
        public int $width,
        public int $height,
        public int $byteSize,
    ) {}
}
