<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Storage;

final readonly class StoredFile
{
    public function __construct(
        public string $relativePath,
        public string $originalFilename,
        public string $mimeType,
        public int $byteSize,
    ) {}
}
