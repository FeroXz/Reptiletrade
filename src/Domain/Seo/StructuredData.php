<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Seo;

use Reptilienmarkt\Domain\Listing\Listing;
use Reptilienmarkt\Domain\Listing\ListingStatus;
use Reptilienmarkt\Domain\Review\ReviewSummary;
use Reptilienmarkt\Domain\Species\Species;
use Reptilienmarkt\Domain\User\User;

/**
 * Strukturierte Daten fuer die Seiten ausserhalb des Redaktionssystems.
 *
 * An einer Stelle und nicht je Template: JSON gehoert von json_encode gebaut,
 * nicht von einer Templatesprache zusammengesetzt — ein Anfuehrungszeichen im
 * Anzeigentitel waere sonst die Luecke, die `script-src 'self'` nicht auffaengt,
 * weil ein eigener ld+json-Block ja erlaubt ist. Denselben Weg geht SeoContext
 * fuer die Inhaltsseiten; dieselben Kodierregeln gelten hier.
 */
final readonly class StructuredData
{
    /**
     * Ab wie vielen Bewertungen eine Durchschnittsnote ausgezeichnet wird.
     *
     * Unter drei sagt der Schnitt mehr ueber den Zufall als ueber den
     * Zuechter, und eine ausgezeichnete Note landet als Sternchen in der
     * Suchergebnisliste — mit einer einzigen Bewertung waere das eine
     * Behauptung, die niemand belegen kann.
     */
    public const int MIN_RATINGS = 3;

    /**
     * Eine Anzeige als Product.
     *
     * Bewusst **ohne** aggregateRating: Die Bewertungen gelten dem Konto und
     * nicht diesem Tier. Sie an der Anzeige auszuzeichnen waere eine falsche
     * Aussage — und Suchmaschinen zeigen sie dann an der Anzeige, wo sie nichts
     * ueber die Sache sagt.
     *
     * @param list<string> $imageUrls absolute Adressen
     *
     * @return array<string, mixed>
     */
    public static function product(
        Listing $listing,
        ?Species $species,
        array $imageUrls,
        string $url,
        ?string $sellerName = null,
    ): array {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $listing->title,
            'url' => $url,
        ];

        if (trim($listing->description) !== '') {
            $data['description'] = self::shorten($listing->description);
        }

        if ($imageUrls !== []) {
            $data['image'] = $imageUrls;
        }

        if ($species !== null) {
            $data['category'] = $species->commonNameDe;
            $data['additionalProperty'] = [[
                '@type' => 'PropertyValue',
                'name' => 'Wissenschaftlicher Name',
                'value' => $species->scientificName,
            ]];
        }

        // Ein Offer ohne Preis ist kein Angebot, sondern eine leere Huelle —
        // bei Tausch und "Preis auf Anfrage" bleibt es deshalb weg.
        if ($listing->priceCents !== null) {
            $offer = [
                '@type' => 'Offer',
                'url' => $url,
                'price' => number_format($listing->priceCents / 100, 2, '.', ''),
                'priceCurrency' => $listing->currency,
                'availability' => self::availability($listing->status),
                'itemCondition' => 'https://schema.org/NewCondition',
            ];

            if ($sellerName !== null) {
                $offer['seller'] = ['@type' => 'Person', 'name' => $sellerName];
            }

            $data['offers'] = $offer;
        }

        return $data;
    }

    /**
     * Ein Artenprofil: die Sammelseite und der Weg dorthin.
     *
     * Beides in einem @graph statt in zwei script-Bloecken — ein Block je
     * Seite, und die Beziehung zwischen den Objekten bleibt sichtbar.
     *
     * @return array<string, mixed>
     */
    public static function speciesPage(Species $species, string $baseUrl, int $listingCount): array
    {
        $basis = rtrim($baseUrl, '/');
        $url = $basis . '/art/' . $species->slug . '/';

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'CollectionPage',
                    'name' => $species->commonNameDe . ' kaufen und tauschen',
                    'url' => $url,
                    'about' => [
                        '@type' => 'Thing',
                        'name' => $species->commonNameDe,
                        'alternateName' => $species->scientificName,
                    ],
                    'mainEntity' => [
                        '@type' => 'ItemList',
                        'numberOfItems' => $listingCount,
                    ],
                ],
                [
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Startseite', 'item' => $basis . '/'],
                        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Markt', 'item' => $basis . '/markt/'],
                        ['@type' => 'ListItem', 'position' => 3, 'name' => $species->commonNameDe, 'item' => $url],
                    ],
                ],
            ],
        ];
    }

    /**
     * Eine Zuechterseite.
     *
     * Person oder Organization je nachdem, was das Konto von sich sagt — ein
     * gewerblicher Anbieter ist keine Privatperson, und die Auszeichnung soll
     * nicht das Gegenteil behaupten.
     *
     * @return array<string, mixed>
     */
    public static function breeder(
        User $owner,
        string $url,
        ?string $description,
        ?string $website,
        ReviewSummary $reviews,
    ): array {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => $owner->isCommercial ? 'Organization' : 'Person',
            'name' => $owner->displayName,
            'url' => $url,
        ];

        if ($description !== null && trim($description) !== '') {
            $data['description'] = self::shorten($description);
        }

        if ($website !== null && trim($website) !== '') {
            $data['sameAs'] = [$website];
        }

        if ($reviews->count >= self::MIN_RATINGS && $reviews->average !== null) {
            $data['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => round($reviews->average, 1),
                'reviewCount' => $reviews->count,
                'bestRating' => 5,
                'worstRating' => 1,
            ];
        }

        return $data;
    }

    /**
     * Kodiert wie SeoContext: JSON_HEX_TAG schliesst "</script>" im Titel aus —
     * der einzige Weg, aus einem ld+json-Block auszubrechen.
     *
     * @param array<string, mixed> $data
     */
    public static function encode(array $data): ?string
    {
        $json = json_encode(
            $data,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT,
        );

        return $json === false ? null : $json;
    }

    /**
     * Der Status der Anzeige in der Sprache von schema.org.
     *
     * Das Vokabular steht hier und nicht im Enum: ListingStatus beschreibt den
     * Zustand einer Anzeige auf diesem Marktplatz, nicht den einer Ware in
     * einem Schema-Vokabular.
     */
    private static function availability(ListingStatus $status): string
    {
        return 'https://schema.org/' . match ($status) {
            ListingStatus::Aktiv => 'InStock',
            ListingStatus::Reserviert => 'LimitedAvailability',
            ListingStatus::Verkauft => 'SoldOut',
            default => 'OutOfStock',
        };
    }

    private static function shorten(string $text): string
    {
        $einzeilig = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_strlen($einzeilig) <= 300 ? $einzeilig : mb_substr($einzeilig, 0, 297) . '…';
    }
}
