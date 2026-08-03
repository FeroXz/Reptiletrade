<?php

declare(strict_types=1);

use Reptilienmarkt\Domain\Geo\PostalCodeRepository;
use Reptilienmarkt\Domain\Search\ListingSearchRepository;
use Reptilienmarkt\Domain\Search\SearchIndex;
use Reptilienmarkt\Domain\Setting\Settings;
use Reptilienmarkt\Domain\Species\MorphRepository;
use Reptilienmarkt\Domain\Species\SpeciesRepository;
use Reptilienmarkt\Http\Controller\ApiController;
use Reptilienmarkt\Http\Controller\MarketController;
use Reptilienmarkt\Http\Controller\SpeciesController;
use Reptilienmarkt\Http\Kernel;
use Reptilienmarkt\Http\Routing\Router;
use Reptilienmarkt\Http\Search\SearchRequestParser;
use Reptilienmarkt\Http\View\TwigFactory;
use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Infra\Persistence\Migrator;
use Reptilienmarkt\Infra\Persistence\PdoLegalTextRepository;
use Reptilienmarkt\Infra\Persistence\PdoMorphRepository;
use Reptilienmarkt\Infra\Persistence\PdoPostalCodeRepository;
use Reptilienmarkt\Infra\Persistence\PdoSettings;
use Reptilienmarkt\Infra\Persistence\PdoSpeciesRepository;
use Reptilienmarkt\Infra\Search\Fts5SearchIndex;
use Reptilienmarkt\Infra\Search\ListingIndexer;
use Reptilienmarkt\Infra\Search\ListingQuery;
use Reptilienmarkt\Infra\Search\PdoListingSearchRepository;
use Reptilienmarkt\Legal\LegalGuard;
use Reptilienmarkt\Legal\LegalRuleFactory;
use Reptilienmarkt\Legal\LegalTextRepository;
use Reptilienmarkt\Legal\LegalTextResolver;
use Reptilienmarkt\Legal\LegalTextReview;
use Reptilienmarkt\Support\Clock;
use Reptilienmarkt\Support\Container;
use Reptilienmarkt\Support\Env;
use Reptilienmarkt\Support\SystemClock;
use Twig\Environment;

$root = dirname(__DIR__);
$container = new Container();

$container->set('paths.root', static fn(): string => $root);
$container->set('paths.migrations', static fn(): string => $root . '/migrations');
$container->set('paths.data', static fn(): string => $root . '/data');

$container->set(Database::class, static function () use ($root): Database {
    $driver = Env::string('DB_DRIVER', 'sqlite');

    if ($driver !== 'sqlite') {
        throw new RuntimeException(sprintf(
            'DB_DRIVER "%s" wird noch nicht unterstuetzt. Umstiegsschwellen siehe docs/ARCHITEKTUR.md.',
            $driver,
        ));
    }

    $database = Env::string('DB_DATABASE', 'storage/db/reptilienmarkt.sqlite');
    if ($database !== ':memory:' && !str_starts_with($database, '/')) {
        $database = $root . '/' . $database;
    }

    return Database::sqlite($database);
});

$container->set(Migrator::class, static fn(Container $c): Migrator => new Migrator(
    $c->get(Database::class),
    $c->get('paths.migrations'),
));

$container->set(SpeciesRepository::class, static fn(Container $c): SpeciesRepository => new PdoSpeciesRepository($c->get(Database::class)));
$container->set(MorphRepository::class, static fn(Container $c): MorphRepository => new PdoMorphRepository($c->get(Database::class)));
$container->set(PostalCodeRepository::class, static fn(Container $c): PostalCodeRepository => new PdoPostalCodeRepository($c->get(Database::class)));
$container->set(LegalTextRepository::class, static fn(Container $c): LegalTextRepository => new PdoLegalTextRepository($c->get(Database::class)));
$container->set(Settings::class, static fn(Container $c): Settings => new PdoSettings($c->get(Database::class)));

$container->set(Clock::class, static fn(): Clock => new SystemClock(Env::string('APP_TIMEZONE', 'Europe/Berlin')));

// --------------------------------------------------------------- Rechts-Engine
$container->set('legal.rules_config', static function () use ($root): array {
    /** @var array<string, mixed> $config */
    $config = require $root . '/config/legal_rules.php';

    return $config;
});

$container->set(LegalTextResolver::class, static fn(Container $c): LegalTextResolver => new LegalTextResolver(
    $c->get(LegalTextRepository::class),
));

$container->set(LegalGuard::class, static function (Container $c): LegalGuard {
    $factory = new LegalRuleFactory($c->get(Clock::class), $c->get(Settings::class));

    /** @var array<string, mixed> $config */
    $config = $c->get('legal.rules_config');

    return new LegalGuard($factory->fromConfig($config), $c->get(LegalTextResolver::class));
});

$container->set(LegalTextReview::class, static function (Container $c): LegalTextReview {
    /** @var array<string, mixed> $config */
    $config = $c->get('legal.rules_config');
    $configured = $config['review_max_age_months'] ?? 12;

    // Das Setting sticht die Konfigurationsdatei — der Admin soll die Frist
    // ohne Deployment aendern koennen.
    $months = $c->get(Settings::class)->int(
        'legal.review_max_age_months',
        is_int($configured) ? $configured : 12,
    );

    return new LegalTextReview($c->get(LegalTextRepository::class), $c->get(Clock::class), $months);
});

// ------------------------------------------------------------------- Suche
$container->set(SearchIndex::class, static fn(Container $c): SearchIndex => new Fts5SearchIndex($c->get(Database::class)));

$container->set(ListingIndexer::class, static fn(Container $c): ListingIndexer => new ListingIndexer(
    $c->get(Database::class),
    $c->get(SearchIndex::class),
));

$container->set(ListingQuery::class, static fn(Container $c): ListingQuery => new ListingQuery($c->get(Clock::class)));

$container->set(ListingSearchRepository::class, static fn(Container $c): ListingSearchRepository => new PdoListingSearchRepository(
    $c->get(Database::class),
    $c->get(ListingQuery::class),
));

// -------------------------------------------------------------------- HTTP
$container->set(Router::class, static function () use ($root): Router {
    /** @var Router $router */
    $router = require $root . '/config/routes.php';

    return $router;
});

$container->set(Environment::class, static fn(): Environment => TwigFactory::create(
    $root . '/templates',
    Env::bool('APP_DEBUG'),
    $root . '/storage/cache/twig',
));

$container->set(SearchRequestParser::class, static fn(Container $c): SearchRequestParser => new SearchRequestParser(
    $c->get(SpeciesRepository::class),
    $c->get(MorphRepository::class),
    $c->get(PostalCodeRepository::class),
    $c->get(Database::class),
));

$container->set(MarketController::class, static fn(Container $c): MarketController => new MarketController(
    $c->get(ListingSearchRepository::class),
    $c->get(SearchRequestParser::class),
    $c->get(Environment::class),
));

$container->set(SpeciesController::class, static fn(Container $c): SpeciesController => new SpeciesController(
    $c->get(SpeciesRepository::class),
    $c->get(MorphRepository::class),
    $c->get(ListingSearchRepository::class),
    $c->get(Environment::class),
));

$container->set(ApiController::class, static fn(Container $c): ApiController => new ApiController(
    $c->get(ListingSearchRepository::class),
    $c->get(SpeciesRepository::class),
    $c->get(MorphRepository::class),
    $c->get(PostalCodeRepository::class),
    $c->get(SearchRequestParser::class),
));

$container->set(Kernel::class, static fn(Container $c): Kernel => new Kernel(
    $c->get(Router::class),
    $c,
    Env::bool('APP_DEBUG'),
));

return $container;
