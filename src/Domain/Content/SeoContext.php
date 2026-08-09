<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

/**
 * Alles, was in den Kopf einer Inhaltsseite gehoert — an einer Stelle
 * zusammengestellt statt in vier Templates verteilt.
 *
 * Das JSON-LD entsteht hier und nicht im Template: Es ist JSON in einem
 * script-Tag, und JSON gehoert von json_encode gebaut, nicht von einer
 * Templatesprache zusammengesetzt. Ein Anfuehrungszeichen im Titel waere sonst
 * genau die Luecke, die die Content-Security-Policy nicht mehr auffangen kann,
 * weil `script-src 'self'` einen eigenen ld+json-Block ja erlaubt.
 */
final readonly class SeoContext
{
    /**
     * @param list<ContentEntry> $breadcrumb von der Wurzel abwaerts
     */
    public function __construct(
        public ContentEntry $entry,
        public string $baseUrl,
        public array $breadcrumb = [],
        public ?Media $ogImage = null,
        public string $siteName = 'Reptilienmarkt',
    ) {}

    public function canonical(): string
    {
        return $this->absolute($this->entry->path);
    }

    public function title(): string
    {
        return $this->entry->seoTitle();
    }

    public function description(): ?string
    {
        return $this->entry->seoDescription();
    }

    public function ogType(): string
    {
        return $this->entry->type === ContentType::Beitrag ? 'article' : 'website';
    }

    public function imageUrl(): ?string
    {
        return $this->ogImage === null ? null : $this->absolute($this->ogImage->url());
    }

    /**
     * Twitter kommt ohne eigene Bildangabe aus — die Karte faellt auf die
     * OpenGraph-Felder zurueck. Nur der Kartentyp muss gesagt werden.
     */
    public function twitterCard(): string
    {
        return $this->ogImage === null ? 'summary' : 'summary_large_image';
    }

    /**
     * Das strukturierte Datenobjekt als JSON.
     *
     * Article fuer Beitraege, BreadcrumbList fuer Seiten mit Ahnenreihe — was
     * die Vorgabe verlangt und was Suchmaschinen tatsaechlich auswerten. Mehr
     * Typen anzubieten hiesse, Aussagen zu machen, die niemand pflegt.
     */
    public function jsonLd(): ?string
    {
        $data = $this->entry->type === ContentType::Beitrag ? $this->article() : $this->breadcrumbList();

        if ($data === null) {
            return null;
        }

        // JSON_HEX_TAG schliesst "</script>" im Titel aus — der einzige Weg,
        // aus einem ld+json-Block auszubrechen.
        $json = json_encode(
            $data,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT,
        );

        return $json === false ? null : $json;
    }

    /**
     * Ein ETag aus dem, was die Seite ausmacht: Adresse, Aenderungszeitpunkt,
     * Status. Aendert sich einer dieser Werte, ist die zwischengespeicherte
     * Fassung ueberholt.
     */
    public function etag(): string
    {
        return '"' . substr(hash('sha256', implode('|', [
            $this->entry->path,
            $this->entry->updatedAt?->format(\DATE_ATOM) ?? '',
            $this->entry->status->value,
        ])), 0, 32) . '"';
    }

    /**
     * @return array<string, mixed>
     */
    private function article(): array
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $this->entry->title,
            'url' => $this->canonical(),
            'inLanguage' => $this->entry->locale,
            'publisher' => ['@type' => 'Organization', 'name' => $this->siteName],
        ];

        if ($this->entry->publishedAt !== null) {
            $data['datePublished'] = $this->entry->publishedAt->format(\DATE_ATOM);
        }

        if ($this->entry->updatedAt !== null) {
            $data['dateModified'] = $this->entry->updatedAt->format(\DATE_ATOM);
        }

        $description = $this->description();
        if ($description !== null) {
            $data['description'] = $description;
        }

        $image = $this->imageUrl();
        if ($image !== null) {
            $data['image'] = [$image];
        }

        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function breadcrumbList(): ?array
    {
        // Eine Brotkrumenliste mit einem einzigen Element sagt nichts aus.
        if (\count($this->breadcrumb) < 2) {
            return null;
        }

        $items = [];
        $position = 1;

        foreach ($this->breadcrumb as $stufe) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position,
                'name' => $stufe->title,
                'item' => $this->absolute($stufe->path),
            ];
            ++$position;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    private function absolute(string $path): string
    {
        return rtrim($this->baseUrl, '/') . $path;
    }
}
