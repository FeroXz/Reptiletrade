<?php

declare(strict_types=1);

use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Domain\Auth\AuthenticationService;
use Reptilienmarkt\Domain\Auth\PasswordHasher;
use Reptilienmarkt\Domain\Auth\SessionRepository;
use Reptilienmarkt\Domain\Geo\PostalCodeRepository;
use Reptilienmarkt\Domain\Listing\GeneticsCalculator;
use Reptilienmarkt\Domain\Listing\LegalDocumentRepository;
use Reptilienmarkt\Domain\Listing\ListingMediaRepository;
use Reptilienmarkt\Domain\Listing\ListingRepository;
use Reptilienmarkt\Domain\Listing\ListingWizard;
use Reptilienmarkt\Domain\Listing\MorphStringGenerator;
use Reptilienmarkt\Domain\Search\ListingSearchRepository;
use Reptilienmarkt\Domain\Search\SearchIndex;
use Reptilienmarkt\Domain\Setting\Settings;
use Reptilienmarkt\Domain\Species\MorphRepository;
use Reptilienmarkt\Domain\Species\SpeciesRepository;
use Reptilienmarkt\Domain\User\UserRepository;
use Reptilienmarkt\Http\Controller\ApiController;
use Reptilienmarkt\Http\Controller\AuthController;
use Reptilienmarkt\Http\Controller\LegalDocumentController;
use Reptilienmarkt\Http\Controller\ListingController;
use Reptilienmarkt\Http\Controller\ListingWizardController;
use Reptilienmarkt\Http\Controller\MarketController;
use Reptilienmarkt\Http\Controller\MediaController;
use Reptilienmarkt\Http\Controller\SpeciesController;
use Reptilienmarkt\Http\Kernel;
use Reptilienmarkt\Http\Middleware\SessionMiddleware;
use Reptilienmarkt\Http\Routing\Router;
use Reptilienmarkt\Http\Search\SearchRequestParser;
use Reptilienmarkt\Http\Session\CurrentUser;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Reptilienmarkt\Http\View\TwigFactory;
use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Infra\Persistence\Migrator;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoLegalDocumentRepository;
use Reptilienmarkt\Infra\Persistence\PdoLegalTextRepository;
use Reptilienmarkt\Infra\Persistence\PdoListingMediaRepository;
use Reptilienmarkt\Infra\Persistence\PdoListingRepository;
use Reptilienmarkt\Infra\Persistence\PdoMorphRepository;
use Reptilienmarkt\Infra\Persistence\PdoPostalCodeRepository;
use Reptilienmarkt\Infra\Persistence\PdoSessionRepository;
use Reptilienmarkt\Infra\Persistence\PdoSettings;
use Reptilienmarkt\Infra\Persistence\PdoSpeciesRepository;
use Reptilienmarkt\Infra\Persistence\PdoUserRepository;
use Reptilienmarkt\Infra\Search\Fts5SearchIndex;
use Reptilienmarkt\Infra\Search\ListingIndexer;
use Reptilienmarkt\Infra\Search\ListingQuery;
use Reptilienmarkt\Infra\Search\PdoListingSearchRepository;
use Reptilienmarkt\Infra\Storage\ImagePipeline;
use Reptilienmarkt\Infra\Storage\PrivateStorage;
use Reptilienmarkt\Infra\Storage\PublicImageStorage;
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

// ------------------------------------------------------- Konto und Sitzung
$container->set(UserRepository::class, static fn(Container $c): UserRepository => new PdoUserRepository($c->get(Database::class)));
$container->set(SessionRepository::class, static fn(Container $c): SessionRepository => new PdoSessionRepository($c->get(Database::class)));
$container->set(AuditLog::class, static fn(Container $c): AuditLog => new PdoAuditLog($c->get(Database::class)));
$container->set(PasswordHasher::class, static fn(): PasswordHasher => new PasswordHasher());

$container->set(AuthenticationService::class, static fn(Container $c): AuthenticationService => new AuthenticationService(
    $c->get(UserRepository::class),
    $c->get(PasswordHasher::class),
    $c->get(Clock::class),
));

$container->set(SessionManager::class, static fn(Container $c): SessionManager => new SessionManager(
    $c->get(SessionRepository::class),
    $c->get(Clock::class),
    1440,
    str_starts_with(Env::string('APP_URL', 'https://example.tld'), 'https://'),
));

$container->set(CurrentUser::class, static fn(Container $c): CurrentUser => new CurrentUser(
    $c->get(SessionManager::class),
    $c->get(UserRepository::class),
));
$container->set(Viewer::class, static fn(Container $c): Viewer => $c->get(CurrentUser::class));

// ------------------------------------------------------ Anzeigen und Ablage
$container->set(ListingRepository::class, static fn(Container $c): ListingRepository => new PdoListingRepository($c->get(Database::class)));
$container->set(ListingMediaRepository::class, static fn(Container $c): ListingMediaRepository => new PdoListingMediaRepository($c->get(Database::class)));
$container->set(LegalDocumentRepository::class, static fn(Container $c): LegalDocumentRepository => new PdoLegalDocumentRepository($c->get(Database::class)));
$container->set(GeneticsCalculator::class, static fn(): GeneticsCalculator => new MorphStringGenerator());

$container->set(ImagePipeline::class, static fn(): ImagePipeline => new ImagePipeline());

$container->set(PublicImageStorage::class, static fn(): PublicImageStorage => new PublicImageStorage(
    $root . '/' . ltrim(Env::string('STORAGE_PUBLIC', 'public/uploads'), '/'),
));

$container->set(PrivateStorage::class, static fn(): PrivateStorage => new PrivateStorage(
    $root . '/' . ltrim(Env::string('STORAGE_PRIVATE', 'storage/private'), '/'),
));

$container->set(ListingWizard::class, static fn(Container $c): ListingWizard => new ListingWizard(
    $c->get(ListingRepository::class),
    $c->get(ListingMediaRepository::class),
    $c->get(LegalDocumentRepository::class),
    $c->get(SpeciesRepository::class),
    $c->get(PostalCodeRepository::class),
    $c->get(UserRepository::class),
    $c->get(LegalGuard::class),
    $c->get(GeneticsCalculator::class),
    $c->get(AuditLog::class),
    $c->get(Clock::class),
));

$container->set(AuthController::class, static fn(Container $c): AuthController => new AuthController(
    $c->get(AuthenticationService::class),
    $c->get(SessionManager::class),
    $c->get(CurrentUser::class),
    $c->get(AuditLog::class),
    $c->get(Environment::class),
));

$container->set(ListingWizardController::class, static fn(Container $c): ListingWizardController => new ListingWizardController(
    $c->get(ListingRepository::class),
    $c->get(ListingMediaRepository::class),
    $c->get(LegalDocumentRepository::class),
    $c->get(SpeciesRepository::class),
    $c->get(MorphRepository::class),
    $c->get(PostalCodeRepository::class),
    $c->get(ListingWizard::class),
    $c->get(ListingIndexer::class),
    $c->get(Viewer::class),
    $c->get(SessionManager::class),
    $c->get(Environment::class),
));

$container->set(MediaController::class, static fn(Container $c): MediaController => new MediaController(
    $c->get(ListingRepository::class),
    $c->get(ListingMediaRepository::class),
    $c->get(LegalDocumentRepository::class),
    $c->get(ImagePipeline::class),
    $c->get(PublicImageStorage::class),
    $c->get(PrivateStorage::class),
    $c->get(Viewer::class),
    $c->get(SessionManager::class),
));

$container->set(LegalDocumentController::class, static fn(Container $c): LegalDocumentController => new LegalDocumentController(
    $c->get(LegalDocumentRepository::class),
    $c->get(ListingRepository::class),
    $c->get(Viewer::class),
    $c->get(PrivateStorage::class),
));

$container->set(ListingController::class, static fn(Container $c): ListingController => new ListingController(
    $c->get(ListingRepository::class),
    $c->get(ListingMediaRepository::class),
    $c->get(SpeciesRepository::class),
    $c->get(ListingWizard::class),
    $c->get(Viewer::class),
    $c->get(Environment::class),
));

$container->set(Kernel::class, static fn(Container $c): Kernel => new Kernel(
    $c->get(Router::class),
    $c,
    [new SessionMiddleware($c->get(SessionManager::class))],
    Env::bool('APP_DEBUG'),
));

return $container;
