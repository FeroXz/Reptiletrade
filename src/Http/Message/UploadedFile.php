<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Message;

/**
 * Eine hochgeladene Datei. Der vom Browser gemeldete Typ wird mitgefuehrt,
 * aber nie geglaubt — die Pruefung passiert am Inhalt.
 */
final readonly class UploadedFile
{
    public function __construct(
        public string $temporaryPath,
        public string $clientFilename,
        public string $clientMimeType,
        public int $size,
        public int $error = \UPLOAD_ERR_OK,
    ) {}

    public function isOk(): bool
    {
        return $this->error === \UPLOAD_ERR_OK && $this->size > 0 && is_file($this->temporaryPath);
    }

    public function errorMessage(): ?string
    {
        return match ($this->error) {
            \UPLOAD_ERR_OK => $this->size > 0 ? null : 'Die Datei ist leer.',
            \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => 'Die Datei ist zu groß.',
            \UPLOAD_ERR_PARTIAL => 'Der Upload wurde abgebrochen.',
            \UPLOAD_ERR_NO_FILE => 'Es wurde keine Datei ausgewählt.',
            default => 'Der Upload ist fehlgeschlagen.',
        };
    }

    /**
     * @param array<string, mixed> $entry Eintrag aus $_FILES
     */
    public static function fromGlobalEntry(array $entry): ?self
    {
        if (!isset($entry['tmp_name']) || !\is_string($entry['tmp_name'])) {
            return null;
        }

        return new self(
            $entry['tmp_name'],
            \is_string($entry['name'] ?? null) ? $entry['name'] : 'datei',
            \is_string($entry['type'] ?? null) ? $entry['type'] : 'application/octet-stream',
            \is_int($entry['size'] ?? null) ? $entry['size'] : 0,
            \is_int($entry['error'] ?? null) ? $entry['error'] : \UPLOAD_ERR_NO_FILE,
        );
    }
}
