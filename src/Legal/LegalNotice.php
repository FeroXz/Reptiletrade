<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal;

/**
 * Ein Hinweis, der dem Nutzer an der Anzeige gezeigt wird. Der Text stammt aus
 * der redaktionell gepflegten Tabelle legal_texts, nicht aus dem Quellcode.
 */
final readonly class LegalNotice
{
    public function __construct(
        public string $key,
        public string $title,
        public string $body,
        public ?string $sourceReference = null,
        public NoticeSeverity $severity = NoticeSeverity::Info,
        public bool $textMissing = false,
    ) {}

    /**
     * @return array{key: string, title: string, body: string, source_reference: string|null, severity: string, text_missing: bool}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'body' => $this->body,
            'source_reference' => $this->sourceReference,
            'severity' => $this->severity->value,
            'text_missing' => $this->textMissing,
        ];
    }
}
