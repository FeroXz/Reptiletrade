<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Privacy;

final readonly class DeletionResult
{
    public function __construct(
        public bool $anonymized,
        public int $filesRemoved,
    ) {}

    public function message(): string
    {
        return $this->anonymized
            ? 'Dein Konto wurde anonymisiert. Die Bewertungen deiner Handelspartner bleiben bestehen.'
            : 'Dein Konto wurde vollständig gelöscht.';
    }
}
