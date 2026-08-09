<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\View;

use Reptilienmarkt\Domain\Search\SearchCriteria;
use Reptilienmarkt\Http\Search\SearchUrlBuilder;
use Reptilienmarkt\Http\Search\SearchUrlContext;
use Reptilienmarkt\Infra\Storage\PublicImageStorage;
use Reptilienmarkt\Support\Slugger;
use Reptilienmarkt\Support\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class TwigFactory
{
    public static function create(
        string $templatePath,
        bool $debug,
        ?string $cachePath = null,
        ?Translator $translator = null,
        ?ViewContext $context = null,
        ?PublicImageStorage $images = null,
    ): Environment {
        $twig = new Environment(new FilesystemLoader($templatePath), [
            'debug' => $debug,
            'strict_variables' => true,
            'cache' => $debug || $cachePath === null ? false : $cachePath,
            'autoescape' => 'html',
        ]);

        $twig->addFunction(new TwigFunction(
            'markt_url',
            static fn(SearchCriteria $criteria, SearchUrlContext $context): string => SearchUrlBuilder::build($criteria, $context),
        ));

        // srcset fuer Anzeigenbilder. Ohne Ablage — etwa in einem Test, der nur
        // ein Template uebersetzt — bleibt es leer, und das img faellt auf sein
        // src zurueck. Eine fehlende Ablage soll kein kaputtes Markup ergeben.
        $twig->addFunction(new TwigFunction(
            'bild_srcset',
            static fn(?string $path): string => $path === null || $images === null ? '' : $images->srcset($path),
        ));

        $twig->addFilter(new TwigFilter('slug', static fn(string $value): string => Slugger::slug($value)));

        // Alle Oberflaechentexte laufen ueber den Uebersetzer. Fehlt ein
        // Schluessel, erscheint er selbst — sichtbar statt stillschweigend leer.
        $twig->addFunction(new TwigFunction(
            't',
            static function (string $key, array $parameters = []) use ($translator): string {
                /** @var array<string, string|int|float> $parameters */
                return $translator?->translate($key, $parameters) ?? $key;
            },
        ));

        $twig->addFunction(new TwigFunction(
            'tw',
            static function (string $key, int $count, array $parameters = []) use ($translator): string {
                /** @var array<string, string|int|float> $parameters */
                return $translator?->choose($key, $count, $parameters) ?? $key;
            },
        ));

        if ($context !== null) {
            $twig->addGlobal('betrachter', $context);
        }

        // Twig kennt kein array_values; die Kriterien-Objekte erwarten aber
        // lueckenlose Listen, sonst schlaegt die Typpruefung fehl.
        $twig->addFilter(new TwigFilter('werte', static function (iterable $value): array {
            return \is_array($value) ? array_values($value) : iterator_to_array($value, false);
        }));

        $twig->addFilter(new TwigFilter('preis', static function (?int $cents, string $currency = 'EUR'): string {
            if ($cents === null) {
                return 'auf Anfrage';
            }

            return number_format($cents / 100, 2, ',', '.') . ' ' . $currency;
        }));

        return $twig;
    }
}
