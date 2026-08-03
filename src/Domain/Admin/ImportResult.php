<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Admin;

final readonly class ImportResult
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        public int $created,
        public int $updated,
        public array $errors = [],
        public bool $dryRun = false,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->errors === [];
    }

    public function message(): string
    {
        if (!$this->isSuccessful()) {
            return \sprintf('%d Fehler — es wurde nichts geschrieben.', \count($this->errors));
        }

        if ($this->dryRun) {
            return \sprintf('Probelauf: %d Zeilen sind einwandfrei. Es wurde nichts geschrieben.', $this->created);
        }

        return \sprintf('%d neu angelegt, %d aktualisiert.', $this->created, $this->updated);
    }
}
