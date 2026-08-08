<?php

declare(strict_types=1);

use Reptilienmarkt\Domain\Admin\DashboardService;
use Reptilienmarkt\Domain\Admin\SpeciesCatalogService;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Domain\Auth\AuthenticationService;
use Reptilienmarkt\Domain\Auth\PasswordHasher;
use Reptilienmarkt\Domain\Auth\SessionRepository;
use Reptilienmarkt\Domain\Auth\TokenRepository;
use Reptilienmarkt\Domain\Auth\TokenService;
use Reptilienmarkt\Domain\Auth\TotpAuthenticator;
use Reptilienmarkt\Domain\Billing\BillingConfiguration;
use Reptilienmarkt\Domain\Billing\BillingService;
use Reptilienmarkt\Domain\Billing\BoostRepository;
use Reptilienmarkt\Domain\Billing\BoostService;
use Reptilienmarkt\Domain\Billing\EntitlementService;
use Reptilienmarkt\Domain\Billing\PaymentProvider;
use Reptilienmarkt\Domain\Billing\PaymentRepository;
use Reptilienmarkt\Domain\Billing\SubscriptionRepository;
use Reptilienmarkt\Domain\Breeding\BreedingAnnouncementRepository;
use Reptilienmarkt\Domain\Breeding\BreedingAnnouncementService;
use Reptilienmarkt\Domain\Contact\ContactRepository;
use Reptilienmarkt\Domain\Contact\ContactService;
use Reptilienmarkt\Domain\Content\ContentBlockRepository;
use Reptilienmarkt\Domain\Content\ContentEditorRepository;
use Reptilienmarkt\Domain\Content\ContentEntryRepository;
use Reptilienmarkt\Domain\Content\ContentPermission;
use Reptilienmarkt\Domain\Content\ContentRenderer;
use Reptilienmarkt\Domain\Content\ContentRevisionRepository;
use Reptilienmarkt\Domain\Content\ContentSearchIndex;
use Reptilienmarkt\Domain\Content\ContentService;
use Reptilienmarkt\Domain\Content\ContentTermRepository;
use Reptilienmarkt\Domain\Content\ContentText;
use Reptilienmarkt\Domain\Content\MarkdownRenderer;
use Reptilienmarkt\Domain\Content\MediaRepository;
use Reptilienmarkt\Domain\Content\MediaUsageRepository;
use Reptilienmarkt\Domain\Content\PreviewService;
use Reptilienmarkt\Domain\Content\PreviewTokenRepository;
use Reptilienmarkt\Domain\Genetics\CrossSimulation;
use Reptilienmarkt\Domain\Genetics\GeneticsConfiguration;
use Reptilienmarkt\Domain\Genetics\GeneticsSimulationRepository;
use Reptilienmarkt\Domain\Geo\PostalCodeRepository;
use Reptilienmarkt\Domain\Identity\IdentityProvider;
use Reptilienmarkt\Domain\Identity\LocalIdentityProvider;
use Reptilienmarkt\Domain\Job\JobHandler;
use Reptilienmarkt\Domain\Job\JobRepository;
use Reptilienmarkt\Domain\Job\JobRunner;
use Reptilienmarkt\Domain\Job\JobScheduler;
use Reptilienmarkt\Domain\Listing\GeneticsCalculator;
use Reptilienmarkt\Domain\Listing\LegalDocumentRepository;
use Reptilienmarkt\Domain\Listing\ListingManager;
use Reptilienmarkt\Domain\Listing\ListingMediaRepository;
use Reptilienmarkt\Domain\Listing\ListingRepository;
use Reptilienmarkt\Domain\Listing\ListingWizard;
use Reptilienmarkt\Domain\Listing\MorphStringGenerator;
use Reptilienmarkt\Domain\Listing\SellerStatsService;
use Reptilienmarkt\Domain\Mail\Mailer;
use Reptilienmarkt\Domain\Message\ConversationRepository;
use Reptilienmarkt\Domain\Message\MessageRepository;
use Reptilienmarkt\Domain\Message\MessagingService;
use Reptilienmarkt\Domain\Moderation\ReportRepository;
use Reptilienmarkt\Domain\Moderation\ReportService;
use Reptilienmarkt\Domain\Privacy\AccountDeletionService;
use Reptilienmarkt\Domain\Privacy\DataExportService;
use Reptilienmarkt\Domain\Privacy\RetentionPolicy;
use Reptilienmarkt\Domain\Review\ReviewRepository;
use Reptilienmarkt\Domain\Review\ReviewService;
use Reptilienmarkt\Domain\Search\ListingSearchRepository;
use Reptilienmarkt\Domain\Search\SearchIndex;
use Reptilienmarkt\Domain\Setting\Settings;
use Reptilienmarkt\Domain\Site\SiteIdentity;
use Reptilienmarkt\Domain\Site\TextOverrideRepository;
use Reptilienmarkt\Domain\Site\UiTextService;
use Reptilienmarkt\Domain\Species\MorphRepository;
use Reptilienmarkt\Domain\Species\SpeciesRepository;
use Reptilienmarkt\Domain\Trust\AutoModerationPolicy;
use Reptilienmarkt\Domain\Trust\ContactMasker;
use Reptilienmarkt\Domain\Trust\FraudKeywordFilter;
use Reptilienmarkt\Domain\Trust\RateLimiter;
use Reptilienmarkt\Domain\Trust\RateLimitRepository;
use Reptilienmarkt\Domain\Trust\TrustConfiguration;
use Reptilienmarkt\Domain\User\AccountService;
use Reptilienmarkt\Domain\User\BreederProfileRepository;
use Reptilienmarkt\Domain\User\BreederProfileService;
use Reptilienmarkt\Domain\User\UserDocumentRepository;
use Reptilienmarkt\Domain\User\UserModerationService;
use Reptilienmarkt\Domain\User\UserRepository;
use Reptilienmarkt\Domain\User\VerificationRepository;
use Reptilienmarkt\Http\Controller\AccountController;
use Reptilienmarkt\Http\Controller\AdminContentController;
use Reptilienmarkt\Http\Controller\AdminController;
use Reptilienmarkt\Http\Controller\AdminListingController;
use Reptilienmarkt\Http\Controller\AdminMediaController;
use Reptilienmarkt\Http\Controller\AdminUserController;
use Reptilienmarkt\Http\Controller\AnnouncementController;
use Reptilienmarkt\Http\Controller\ApiController;
use Reptilienmarkt\Http\Controller\AuthController;
use Reptilienmarkt\Http\Controller\BillingController;
use Reptilienmarkt\Http\Controller\ContactController;
use Reptilienmarkt\Http\Controller\ContentController;
use Reptilienmarkt\Http\Controller\GeneticsController;
use Reptilienmarkt\Http\Controller\LegalDocumentController;
use Reptilienmarkt\Http\Controller\LegalPageController;
use Reptilienmarkt\Http\Controller\ListingController;
use Reptilienmarkt\Http\Controller\ListingManagementController;
use Reptilienmarkt\Http\Controller\ListingWizardController;
use Reptilienmarkt\Http\Controller\MarketController;
use Reptilienmarkt\Http\Controller\MediaController;
use Reptilienmarkt\Http\Controller\MessageController;
use Reptilienmarkt\Http\Controller\ModerationController;
use Reptilienmarkt\Http\Controller\NewsController;
use Reptilienmarkt\Http\Controller\PasswordResetController;
use Reptilienmarkt\Http\Controller\PrivacyController;
use Reptilienmarkt\Http\Controller\ProfileController;
use Reptilienmarkt\Http\Controller\ReportController;
use Reptilienmarkt\Http\Controller\SpeciesController;
use Reptilienmarkt\Http\Controller\StatsController;
use Reptilienmarkt\Http\Kernel;
use Reptilienmarkt\Http\Middleware\SessionMiddleware;
use Reptilienmarkt\Http\Routing\Router;
use Reptilienmarkt\Http\Search\SearchRequestParser;
use Reptilienmarkt\Http\Session\CurrentUser;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Reptilienmarkt\Http\View\TwigFactory;
use Reptilienmarkt\Http\View\ViewContext;
use Reptilienmarkt\Infra\Genetics\PdfReportGenerator;
use Reptilienmarkt\Infra\Job\Handler\BanExpiryHandler;
use Reptilienmarkt\Infra\Job\Handler\BoostExpiryHandler;
use Reptilienmarkt\Infra\Job\Handler\ContentPublishHandler;
use Reptilienmarkt\Infra\Job\Handler\ListingArchiveHandler;
use Reptilienmarkt\Infra\Job\Handler\ListingExpiryNoticeHandler;
use Reptilienmarkt\Infra\Job\Handler\LogRotationHandler;
use Reptilienmarkt\Infra\Job\Handler\MediaCleanupHandler;
use Reptilienmarkt\Infra\Job\Handler\RetentionHandler;
use Reptilienmarkt\Infra\Job\Handler\SavedSearchAlertHandler;
use Reptilienmarkt\Infra\Job\Handler\SearchReindexHandler;
use Reptilienmarkt\Infra\Mail\FileMailer;
use Reptilienmarkt\Infra\Mail\SendmailMailer;
use Reptilienmarkt\Infra\Payment\NullPaymentProvider;
use Reptilienmarkt\Infra\Payment\StripePaymentProvider;
use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Infra\Persistence\Migrator;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoBoostRepository;
use Reptilienmarkt\Infra\Persistence\PdoBreederProfileRepository;
use Reptilienmarkt\Infra\Persistence\PdoBreedingAnnouncementRepository;
use Reptilienmarkt\Infra\Persistence\PdoContactRepository;
use Reptilienmarkt\Infra\Persistence\PdoContentBlockRepository;
use Reptilienmarkt\Infra\Persistence\PdoContentEditorRepository;
use Reptilienmarkt\Infra\Persistence\PdoContentEntryRepository;
use Reptilienmarkt\Infra\Persistence\PdoContentRevisionRepository;
use Reptilienmarkt\Infra\Persistence\PdoContentTermRepository;
use Reptilienmarkt\Infra\Persistence\PdoConversationRepository;
use Reptilienmarkt\Infra\Persistence\PdoGeneticsSimulationRepository;
use Reptilienmarkt\Infra\Persistence\PdoJobRepository;
use Reptilienmarkt\Infra\Persistence\PdoLegalDocumentRepository;
use Reptilienmarkt\Infra\Persistence\PdoLegalTextRepository;
use Reptilienmarkt\Infra\Persistence\PdoListingMediaRepository;
use Reptilienmarkt\Infra\Persistence\PdoListingRepository;
use Reptilienmarkt\Infra\Persistence\PdoMediaRepository;
use Reptilienmarkt\Infra\Persistence\PdoMediaUsageRepository;
use Reptilienmarkt\Infra\Persistence\PdoMessageRepository;
use Reptilienmarkt\Infra\Persistence\PdoMorphRepository;
use Reptilienmarkt\Infra\Persistence\PdoPaymentRepository;
use Reptilienmarkt\Infra\Persistence\PdoPostalCodeRepository;
use Reptilienmarkt\Infra\Persistence\PdoPreviewTokenRepository;
use Reptilienmarkt\Infra\Persistence\PdoRateLimitRepository;
use Reptilienmarkt\Infra\Persistence\PdoReportRepository;
use Reptilienmarkt\Infra\Persistence\PdoReviewRepository;
use Reptilienmarkt\Infra\Persistence\PdoSessionRepository;
use Reptilienmarkt\Infra\Persistence\PdoSettings;
use Reptilienmarkt\Infra\Persistence\PdoSpeciesRepository;
use Reptilienmarkt\Infra\Persistence\PdoSubscriptionRepository;
use Reptilienmarkt\Infra\Persistence\PdoTextOverrideRepository;
use Reptilienmarkt\Infra\Persistence\PdoTokenRepository;
use Reptilienmarkt\Infra\Persistence\PdoUserDocumentRepository;
use Reptilienmarkt\Infra\Persistence\PdoUserRepository;
use Reptilienmarkt\Infra\Persistence\PdoVerificationRepository;
use Reptilienmarkt\Infra\Search\ContentIndexer;
use Reptilienmarkt\Infra\Search\Fts5ContentSearchIndex;
use Reptilienmarkt\Infra\Search\Fts5SearchIndex;
use Reptilienmarkt\Infra\Search\ListingIndexer;
use Reptilienmarkt\Infra\Search\ListingQuery;
use Reptilienmarkt\Infra\Search\PdoListingSearchRepository;
use Reptilienmarkt\Infra\Storage\ImagePipeline;
use Reptilienmarkt\Infra\Storage\MediaService;
use Reptilienmarkt\Infra\Storage\MediaStorage;
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
use Reptilienmarkt\Support\Log\JsonLogger;
use Reptilienmarkt\Support\Log\Logger;
use Reptilienmarkt\Support\Log\LogLevel;
use Reptilienmarkt\Support\SystemClock;
use Reptilienmarkt\Support\TranslationOverrides;
use Reptilienmarkt\Support\Translator;
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

// Anwendungsprotokoll — getrennt vom Audit-Trail. Eine Datei je Tag, damit
// die Rotation ueberhaupt etwas zum Aufraeumen hat.
$container->set('paths.logs', static fn(): string => $root . '/' . ltrim(Env::string('LOG_DIRECTORY', 'storage/logs'), '/'));

$container->set(Logger::class, static fn(Container $c): Logger => new JsonLogger(
    $c->get('paths.logs') . '/app-' . gmdate('Y-m-d') . '.log',
    LogLevel::tryFrom(Env::string('LOG_LEVEL', 'info')) ?? LogLevel::Info,
    Env::string('APP_ENV', 'production'),
));

$container->set(RetentionPolicy::class, static function (Container $c) use ($root): RetentionPolicy {
    /** @var array<string, mixed> $config */
    $config = require $root . '/config/aufbewahrung.php';

    return new RetentionPolicy($config, $c->get(Clock::class));
});

// Die Plattform laeuft ohne fremde Identitaetsquelle. Ein anderer Wert als
// "local" braucht eine eigene Umsetzung des Interfaces — bis dahin ist ein
// Startfehler ehrlicher als eine stillschweigend lokale Anmeldung.
$container->set(IdentityProvider::class, static function (): IdentityProvider {
    $provider = Env::string('IDENTITY_PROVIDER', 'local');

    return match ($provider) {
        'local' => new LocalIdentityProvider(),
        default => throw new RuntimeException(sprintf(
            'IDENTITY_PROVIDER "%s" ist nicht umgesetzt. Verfuegbar: local.',
            $provider,
        )),
    };
});

// Die Textueberschreibungen der Verwaltung liegen ueber dem ausgelieferten
// Katalog. Dieselbe Instanz bedient den Uebersetzer und die Textverwaltung —
// sonst saehe die eine die Aenderungen der anderen erst beim naechsten Aufruf.
// Eine Instanz, zwei Sichten: Der Uebersetzer sieht die Ueberschreibungen
// (TranslationOverrides), die Verwaltung schreibt sie (TextOverrideRepository).
// Zwei Instanzen haetten zwei Zwischenspeicher — und der Uebersetzer bekaeme
// eine Aenderung im selben Aufruf nicht mit.
$container->set(PdoTextOverrideRepository::class, static fn(Container $c): PdoTextOverrideRepository => new PdoTextOverrideRepository($c->get(Database::class)));
$container->set(TextOverrideRepository::class, static fn(Container $c): TextOverrideRepository => $c->get(PdoTextOverrideRepository::class));
$container->set(TranslationOverrides::class, static fn(Container $c): TranslationOverrides => $c->get(PdoTextOverrideRepository::class));

$container->set(Translator::class, static fn(Container $c): Translator => new Translator(
    $root . '/lang',
    Env::string('APP_LOCALE', Translator::BASE_LOCALE),
    $c->get(TranslationOverrides::class),
));

$container->set(UiTextService::class, static fn(Container $c): UiTextService => new UiTextService(
    $c->get(Translator::class),
    $c->get(TextOverrideRepository::class),
    $c->get(AuditLog::class),
    $c->get(Clock::class),
));

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

$container->set(ViewContext::class, static fn(Container $c): ViewContext => new ViewContext(
    $c->get(Viewer::class),
    $c->get(ConversationRepository::class),
    $c->get(ContentPermission::class),
));

$container->set(Environment::class, static fn(Container $c): Environment => TwigFactory::create(
    $root . '/templates',
    Env::bool('APP_DEBUG'),
    $root . '/storage/cache/twig',
    $c->get(Translator::class),
    $c->get(ViewContext::class),
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

// Das Secure-Flag des Cookies kommt nicht mehr aus APP_URL, sondern aus der
// tatsaechlichen Verbindung — siehe Request::detectSecure().
$container->set(SessionManager::class, static fn(Container $c): SessionManager => new SessionManager(
    $c->get(SessionRepository::class),
    $c->get(Clock::class),
    1440,
    $c->get(Logger::class),
));

$container->set(CurrentUser::class, static fn(Container $c): CurrentUser => new CurrentUser(
    $c->get(SessionManager::class),
    $c->get(UserRepository::class),
));
$container->set(Viewer::class, static fn(Container $c): Viewer => $c->get(CurrentUser::class));

// ------------------------------------------------------- Redaktionssystem
$container->set(ContentEntryRepository::class, static fn(Container $c): ContentEntryRepository => new PdoContentEntryRepository($c->get(Database::class)));
$container->set(ContentBlockRepository::class, static fn(Container $c): ContentBlockRepository => new PdoContentBlockRepository($c->get(Database::class)));

$container->set(ContentRevisionRepository::class, static fn(Container $c): ContentRevisionRepository => new PdoContentRevisionRepository($c->get(Database::class)));
$container->set(PreviewTokenRepository::class, static fn(Container $c): PreviewTokenRepository => new PdoPreviewTokenRepository($c->get(Database::class)));

$container->set(PreviewService::class, static fn(Container $c): PreviewService => new PreviewService(
    $c->get(PreviewTokenRepository::class),
    $c->get(Clock::class),
));

$container->set(ContentEditorRepository::class, static fn(Container $c): ContentEditorRepository => new PdoContentEditorRepository($c->get(Database::class)));

$container->set(ContentPermission::class, static fn(Container $c): ContentPermission => new ContentPermission(
    $c->get(ContentEditorRepository::class),
));

$container->set(ContentService::class, static fn(Container $c): ContentService => new ContentService(
    $c->get(ContentEntryRepository::class),
    $c->get(ContentBlockRepository::class),
    $c->get(ContentRevisionRepository::class),
    $c->get(ContentSearchIndex::class),
    $c->get(ContentText::class),
    $c->get(RetentionPolicy::class),
    $c->get(AuditLog::class),
    $c->get(Clock::class),
));

$container->set(ContentTermRepository::class, static fn(Container $c): ContentTermRepository => new PdoContentTermRepository($c->get(Database::class)));
$container->set(ContentSearchIndex::class, static fn(Container $c): ContentSearchIndex => new Fts5ContentSearchIndex($c->get(Database::class)));

$container->set(ContentText::class, static fn(Container $c): ContentText => new ContentText($c->get(MarkdownRenderer::class)));

$container->set(ContentIndexer::class, static fn(Container $c): ContentIndexer => new ContentIndexer(
    $c->get(ContentEntryRepository::class),
    $c->get(ContentBlockRepository::class),
    $c->get(ContentText::class),
    $c->get(ContentSearchIndex::class),
));

$container->set(MediaRepository::class, static fn(Container $c): MediaRepository => new PdoMediaRepository($c->get(Database::class)));
$container->set(MediaUsageRepository::class, static fn(Container $c): MediaUsageRepository => new PdoMediaUsageRepository($c->get(Database::class)));

$container->set(MediaStorage::class, static fn(): MediaStorage => new MediaStorage(
    $root . '/' . ltrim(Env::string('STORAGE_MEDIA', 'public/media'), '/'),
));

$container->set(MediaService::class, static fn(Container $c): MediaService => new MediaService(
    $c->get(MediaRepository::class),
    $c->get(MediaUsageRepository::class),
    $c->get(ImagePipeline::class),
    $c->get(MediaStorage::class),
    $c->get(AuditLog::class),
    $c->get(Clock::class),
));

$container->set(MarkdownRenderer::class, static fn(): MarkdownRenderer => new MarkdownRenderer());

$container->set(ContentRenderer::class, static fn(Container $c): ContentRenderer => new ContentRenderer(
    $c->get(MarkdownRenderer::class),
    $c->get(ContentText::class),
    $c->get(ListingRepository::class),
    $c->get(SpeciesRepository::class),
    $c->get(MediaRepository::class),
));

$container->set(ContentController::class, static fn(Container $c): ContentController => new ContentController(
    $c->get(ContentEntryRepository::class),
    $c->get(ContentBlockRepository::class),
    $c->get(ContentRenderer::class),
    $c->get(PreviewService::class),
    $c->get(Environment::class),
));

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
    $c->get(AutoModerationPolicy::class),
    $c->get(EntitlementService::class),
));

// Bearbeiten, Pausieren, Loeschen nach der Veroeffentlichung.
$container->set(ListingManager::class, static fn(Container $c): ListingManager => new ListingManager(
    $c->get(ListingRepository::class),
    $c->get(ListingMediaRepository::class),
    $c->get(LegalDocumentRepository::class),
    $c->get(ListingWizard::class),
    $c->get(ListingIndexer::class),
    $c->get(PublicImageStorage::class),
    $c->get(PrivateStorage::class),
    $c->get(AuditLog::class),
));

$container->set(ListingManagementController::class, static fn(Container $c): ListingManagementController => new ListingManagementController(
    $c->get(ListingRepository::class),
    $c->get(ListingManager::class),
    $c->get(SpeciesRepository::class),
    $c->get(PostalCodeRepository::class),
    $c->get(Viewer::class),
    $c->get(SessionManager::class),
    $c->get(Environment::class),
));

$container->set(AdminListingController::class, static fn(Container $c): AdminListingController => new AdminListingController(
    $c->get(ListingRepository::class),
    $c->get(ListingManager::class),
    $c->get(Viewer::class),
    $c->get(SessionManager::class),
    $c->get(Environment::class),
));

$container->set(SellerStatsService::class, static fn(Container $c): SellerStatsService => new SellerStatsService(
    $c->get(Database::class),
    $c->get(Clock::class),
));

$container->set(StatsController::class, static fn(Container $c): StatsController => new StatsController(
    $c->get(SellerStatsService::class),
    $c->get(EntitlementService::class),
    $c->get(Viewer::class),
    $c->get(SessionManager::class),
    $c->get(Environment::class),
));

// ------------------------------------- Impressum, Kontakt, Kontosperren
$container->set(SiteIdentity::class, static function () use ($root): SiteIdentity {
    /** @var array<string, mixed> $config */
    $config = require $root . '/config/impressum.php';

    return new SiteIdentity($config);
});

$container->set(ContactRepository::class, static fn(Container $c): ContactRepository => new PdoContactRepository($c->get(Database::class)));

$container->set(ContactService::class, static fn(Container $c): ContactService => new ContactService(
    $c->get(ContactRepository::class),
    $c->get(Mailer::class),
    $c->get(Translator::class),
    $c->get(AuditLog::class),
    // Anfragen gehen an die Adresse aus dem Impressum — eine zweite zu pflegen
    // waere eine, die irgendwann nicht mehr stimmt.
    $c->get(SiteIdentity::class)->contact()['email'] ?? '',
));

$container->set(UserModerationService::class, static fn(Container $c): UserModerationService => new UserModerationService(
    $c->get(UserRepository::class),
    $c->get(SessionRepository::class),
    $c->get(ListingRepository::class),
    $c->get(ListingIndexer::class),
    $c->get(AccountDeletionService::class),
    $c->get(AuditLog::class),
    $c->get(Clock::class),
));

$container->set(LegalPageController::class, static fn(Container $c): LegalPageController => new LegalPageController(
    $c->get(SiteIdentity::class),
    $c->get(Environment::class),
    Env::string('APP_URL', 'https://example.tld'),
));

$container->set(ContactController::class, static fn(Container $c): ContactController => new ContactController(
    $c->get(ContactService::class),
    $c->get(SiteIdentity::class),
    $c->get(RateLimiter::class),
    $c->get(Viewer::class),
    $c->get(SessionManager::class),
    $c->get(Environment::class),
));

$container->set(AdminUserController::class, static fn(Container $c): AdminUserController => new AdminUserController(
    $c->get(UserRepository::class),
    $c->get(UserModerationService::class),
    $c->get(ContactRepository::class),
    $c->get(Viewer::class),
    $c->get(SessionManager::class),
    $c->get(Environment::class),
));

$container->set(AuthController::class, static fn(Container $c): AuthController => new AuthController(
    $c->get(AuthenticationService::class),
    $c->get(RateLimiter::class),
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
    $c->get(RateLimiter::class),
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
    $c->get(UserRepository::class),
    $c->get(BreederProfileRepository::class),
    $c->get(SessionManager::class),
    $c->get(Viewer::class),
    $c->get(Environment::class),
));

// --------------------------------------- Vertrauen und Missbrauchsabwehr
$container->set(TrustConfiguration::class, static function () use ($root): TrustConfiguration {
    /** @var array<string, mixed> $config */
    $config = require $root . '/config/trust.php';

    return new TrustConfiguration($config);
});

$container->set(RateLimitRepository::class, static fn(Container $c): RateLimitRepository => new PdoRateLimitRepository($c->get(Database::class)));

$container->set(RateLimiter::class, static fn(Container $c): RateLimiter => new RateLimiter(
    $c->get(RateLimitRepository::class),
    $c->get(Clock::class),
    $c->get(TrustConfiguration::class)->rateLimits(),
));

$container->set(FraudKeywordFilter::class, static fn(Container $c): FraudKeywordFilter => $c->get(TrustConfiguration::class)->keywordFilter());
$container->set(ContactMasker::class, static fn(Container $c): ContactMasker => $c->get(TrustConfiguration::class)->contactMasker());
$container->set(AutoModerationPolicy::class, static fn(Container $c): AutoModerationPolicy => $c->get(TrustConfiguration::class)->autoModeration());

// -------------------------------------------------------------- Mailversand
$container->set(Mailer::class, static function () use ($root): Mailer {
    // Voreinstellung ist die Datei-Ablage: Ein falsch konfigurierter Server
    // soll keine echten Mails an echte Adressen schicken.
    return Env::string('MAIL_TRANSPORT', 'datei') === 'sendmail'
        ? new SendmailMailer(
            Env::string('MAIL_FROM', 'noreply@example.tld'),
            Env::string('MAIL_FROM_NAME', 'Reptilienmarkt'),
        )
        : new FileMailer($root . '/' . ltrim(Env::string('MAIL_DIRECTORY', 'storage/mail'), '/'));
});

// -------------------------------------------- Konto, Verifizierung, Token
$container->set(TokenRepository::class, static fn(Container $c): TokenRepository => new PdoTokenRepository($c->get(Database::class)));
$container->set(VerificationRepository::class, static fn(Container $c): VerificationRepository => new PdoVerificationRepository($c->get(Database::class)));
$container->set(UserDocumentRepository::class, static fn(Container $c): UserDocumentRepository => new PdoUserDocumentRepository($c->get(Database::class)));
$container->set(BreederProfileRepository::class, static fn(Container $c): BreederProfileRepository => new PdoBreederProfileRepository($c->get(Database::class)));

$container->set(TokenService::class, static fn(Container $c): TokenService => new TokenService(
    $c->get(TokenRepository::class),
    $c->get(Clock::class),
));

$container->set(TotpAuthenticator::class, static fn(Container $c): TotpAuthenticator => new TotpAuthenticator($c->get(Clock::class)));

$container->set(AccountService::class, static fn(Container $c): AccountService => new AccountService(
    $c->get(UserRepository::class),
    $c->get(VerificationRepository::class),
    $c->get(TokenService::class),
    $c->get(PasswordHasher::class),
    $c->get(TotpAuthenticator::class),
    $c->get(Mailer::class),
    $c->get(AuditLog::class),
    $c->get(Clock::class),
    $c->get(Translator::class),
    Env::string('APP_URL', 'https://example.tld'),
));

$container->set(BreederProfileService::class, static fn(Container $c): BreederProfileService => new BreederProfileService(
    $c->get(BreederProfileRepository::class),
    $c->get(AuditLog::class),
));

// ----------------------------------------- Postfach, Bewertungen, Meldungen
$container->set(ConversationRepository::class, static fn(Container $c): ConversationRepository => new PdoConversationRepository($c->get(Database::class)));
$container->set(MessageRepository::class, static fn(Container $c): MessageRepository => new PdoMessageRepository($c->get(Database::class)));
$container->set(ReviewRepository::class, static fn(Container $c): ReviewRepository => new PdoReviewRepository($c->get(Database::class)));
$container->set(ReportRepository::class, static fn(Container $c): ReportRepository => new PdoReportRepository($c->get(Database::class)));

$container->set(MessagingService::class, static fn(Container $c): MessagingService => new MessagingService(
    $c->get(ConversationRepository::class),
    $c->get(MessageRepository::class),
    $c->get(ListingRepository::class),
    $c->get(RateLimiter::class),
    $c->get(FraudKeywordFilter::class),
    $c->get(ContactMasker::class),
    $c->get(AuditLog::class),
    $c->get(Clock::class),
));

$container->set(ReviewService::class, static fn(Container $c): ReviewService => new ReviewService(
    $c->get(ReviewRepository::class),
    $c->get(AuditLog::class),
    $c->get(Clock::class),
));

$container->set(ReportService::class, static fn(Container $c): ReportService => new ReportService(
    $c->get(ReportRepository::class),
    $c->get(RateLimiter::class),
    $c->get(AuditLog::class),
    $c->get(Clock::class),
));

$container->set(AccountController::class, static fn(Container $c): AccountController => new AccountController(
    $c->get(AccountService::class),
    $c->get(UserDocumentRepository::class),
    $c->get(PrivateStorage::class),
    $c->get(TotpAuthenticator::class),
    $c->get(RateLimiter::class),
    $c->get(Viewer::class),
    $c->get(SessionManager::class),
    $c->get(Translator::class),
    $c->get(Environment::class),
    Env::string('APP_NAME', 'Reptilienmarkt'),
));

$container->set(PasswordResetController::class, static fn(Container $c): PasswordResetController => new PasswordResetController(
    $c->get(AccountService::class),
    $c->get(RateLimiter::class),
    $c->get(SessionManager::class),
    $c->get(Environment::class),
));

$container->set(ProfileController::class, static fn(Container $c): ProfileController => new ProfileController(
    $c->get(BreederProfileRepository::class),
    $c->get(BreederProfileService::class),
    $c->get(ReviewService::class),
    $c->get(UserRepository::class),
    $c->get(SpeciesRepository::class),
    $c->get(ListingRepository::class),
    $c->get(Viewer::class),
    $c->get(SessionManager::class),
    $c->get(Translator::class),
    $c->get(Environment::class),
));

$container->set(MessageController::class, static fn(Container $c): MessageController => new MessageController(
    $c->get(ConversationRepository::class),
    $c->get(MessagingService::class),
    $c->get(ReviewService::class),
    $c->get(ListingRepository::class),
    $c->get(SpeciesRepository::class),
    $c->get(UserRepository::class),
    $c->get(Viewer::class),
    $c->get(SessionManager::class),
    $c->get(Translator::class),
    $c->get(Environment::class),
));

$container->set(ReportController::class, static fn(Container $c): ReportController => new ReportController(
    $c->get(ReportService::class),
    $c->get(Viewer::class),
    $c->get(SessionManager::class),
    $c->get(Translator::class),
    $c->get(Environment::class),
));

$container->set(ModerationController::class, static fn(Container $c): ModerationController => new ModerationController(
    $c->get(ReportService::class),
    $c->get(ReportRepository::class),
    $c->get(MessageRepository::class),
    $c->get(ListingRepository::class),
    $c->get(UserDocumentRepository::class),
    $c->get(VerificationRepository::class),
    $c->get(ListingIndexer::class),
    $c->get(AuditLog::class),
    $c->get(Viewer::class),
    $c->get(SessionManager::class),
    $c->get(Clock::class),
    $c->get(Environment::class),
));

// ---------------------------------------- Monetarisierung (Phase 6)
// Vorbereitet, nicht aktiviert: config/monetarisierung.php steht auf
// enabled => false, und der Null-Anbieter lehnt jeden Zahlungsvorgang ab.
$container->set(BillingConfiguration::class, static function (Container $c) use ($root): BillingConfiguration {
    /** @var array<string, mixed> $config */
    $config = require $root . '/config/monetarisierung.php';

    return new BillingConfiguration($config, $c->get(Settings::class));
});

$container->set(SubscriptionRepository::class, static fn(Container $c): SubscriptionRepository => new PdoSubscriptionRepository($c->get(Database::class)));
$container->set(PaymentRepository::class, static fn(Container $c): PaymentRepository => new PdoPaymentRepository($c->get(Database::class)));
$container->set(BoostRepository::class, static fn(Container $c): BoostRepository => new PdoBoostRepository($c->get(Database::class)));
$container->set(BreedingAnnouncementRepository::class, static fn(Container $c): BreedingAnnouncementRepository => new PdoBreedingAnnouncementRepository($c->get(Database::class)));

$container->set(PaymentProvider::class, static function (Container $c): PaymentProvider {
    $config = $c->get(BillingConfiguration::class);
    $name = $config->providerName();

    if ($name === 'keiner') {
        return new NullPaymentProvider();
    }

    if ($name !== 'stripe') {
        throw new RuntimeException(sprintf('Zahlungsanbieter "%s" ist nicht umgesetzt.', $name));
    }

    /** @var array<string, mixed> $options */
    $options = $config->providerOptions('stripe');
    $secretEnv = is_string($options['secret_key_env'] ?? null) ? $options['secret_key_env'] : 'STRIPE_SECRET_KEY';
    $hookEnv = is_string($options['webhook_secret_env'] ?? null) ? $options['webhook_secret_env'] : 'STRIPE_WEBHOOK_SECRET';

    return new StripePaymentProvider(
        Env::string($secretEnv),
        Env::string($hookEnv),
        is_string($options['api_base'] ?? null) ? $options['api_base'] : 'https://api.stripe.com/v1',
    );
});

$container->set(EntitlementService::class, static fn(Container $c): EntitlementService => new EntitlementService(
    $c->get(BillingConfiguration::class),
    $c->get(SubscriptionRepository::class),
    $c->get(UserRepository::class),
    $c->get(Clock::class),
));

$container->set(BoostService::class, static fn(Container $c): BoostService => new BoostService(
    $c->get(BoostRepository::class),
    $c->get(ListingRepository::class),
    $c->get(BillingConfiguration::class),
    $c->get(AuditLog::class),
    $c->get(Clock::class),
));

$container->set(BillingService::class, static fn(Container $c): BillingService => new BillingService(
    $c->get(BillingConfiguration::class),
    $c->get(SubscriptionRepository::class),
    $c->get(PaymentRepository::class),
    $c->get(BoostService::class),
    $c->get(PaymentProvider::class),
    $c->get(AuditLog::class),
    $c->get(Clock::class),
    Env::string('APP_URL', 'https://example.tld'),
));

$container->set(BreedingAnnouncementService::class, static fn(Container $c): BreedingAnnouncementService => new BreedingAnnouncementService(
    $c->get(BreedingAnnouncementRepository::class),
    $c->get(SpeciesRepository::class),
    $c->get(EntitlementService::class),
    $c->get(Clock::class),
));

$container->set(BillingController::class, static fn(Container $c): BillingController => new BillingController(
    $c->get(BillingService::class),
    $c->get(EntitlementService::class),
    $c->get(BoostService::class),
    $c->get(ListingRepository::class),
    $c->get(UserRepository::class),
    $c->get(Viewer::class),
    $c->get(SessionManager::class),
    $c->get(Environment::class),
));

$container->set(AnnouncementController::class, static fn(Container $c): AnnouncementController => new AnnouncementController(
    $c->get(BreedingAnnouncementService::class),
    $c->get(BreedingAnnouncementRepository::class),
    $c->get(SpeciesRepository::class),
    $c->get(Viewer::class),
    $c->get(SessionManager::class),
    $c->get(Environment::class),
));

// ------------------------------------------ Vererbungsrechnung (Phase 10)
// Der Schalter steht in config/genetik.php; das Setting "genetik.enabled"
// sticht ihn zur Laufzeit, und rollout_percentage gibt das Merkmal
// stufenweise frei.
$container->set(GeneticsConfiguration::class, static function (Container $c) use ($root): GeneticsConfiguration {
    /** @var array<string, mixed> $config */
    $config = require $root . '/config/genetik.php';

    return new GeneticsConfiguration($config, $c->get(Settings::class));
});

$container->set(GeneticsSimulationRepository::class, static fn(Container $c): GeneticsSimulationRepository => new PdoGeneticsSimulationRepository($c->get(Database::class)));

// Die Simulation nutzt denselben GeneticsCalculator wie der
// Anzeigenassistent: Ein Nachkomme "Hypo het Zero" heisst im Bericht genau so
// wie in der Anzeige, die spaeter daraus wird.
$container->set(CrossSimulation::class, static fn(Container $c): CrossSimulation => new CrossSimulation(
    $c->get(MorphRepository::class),
    $c->get(SpeciesRepository::class),
    $c->get(GeneticsConfiguration::class),
    $c->get(Clock::class),
    $c->get(GeneticsCalculator::class),
));

$container->set(PdfReportGenerator::class, static fn(Container $c): PdfReportGenerator => new PdfReportGenerator(
    $c->get(Environment::class),
));

$container->set(GeneticsController::class, static fn(Container $c): GeneticsController => new GeneticsController(
    $c->get(CrossSimulation::class),
    $c->get(GeneticsSimulationRepository::class),
    $c->get(PdfReportGenerator::class),
    $c->get(GeneticsConfiguration::class),
    $c->get(ListingRepository::class),
    $c->get(SpeciesRepository::class),
    $c->get(MorphRepository::class),
    $c->get(RateLimiter::class),
    $c->get(Viewer::class),
    $c->get(SessionManager::class),
    $c->get(Environment::class),
    $c->get(Logger::class),
    $c->get(Clock::class),
));

// ------------------------------------------- Auftraege und Betrieb (Phase 7)
$container->set(JobRepository::class, static fn(Container $c): JobRepository => new PdoJobRepository($c->get(Database::class)));

/**
 * Die Handler. Der Schluessel ist der Auftragstyp — dieselbe Zeichenkette,
 * die in der Tabelle steht und die der Scheduler einplant.
 *
 * @return array<string, JobHandler>
 */
$container->set('jobs.handlers', static function (Container $c) use ($root): array {
    $handlers = [
        new ListingExpiryNoticeHandler(
            $c->get(Database::class),
            $c->get(Mailer::class),
            $c->get(RetentionPolicy::class),
            $c->get(Translator::class),
            $c->get(Clock::class),
            Env::string('APP_URL', 'https://example.tld'),
        ),
        new ListingArchiveHandler($c->get(Database::class), $c->get(ListingIndexer::class), $c->get(Clock::class)),
        new SavedSearchAlertHandler(
            $c->get(Database::class),
            $c->get(Mailer::class),
            $c->get(Translator::class),
            $c->get(Clock::class),
            Env::string('APP_URL', 'https://example.tld'),
        ),
        new ContentPublishHandler(
            $c->get(ContentService::class),
            $c->get(PreviewService::class),
            $c->get(Clock::class),
            $c->get(Logger::class),
        ),
        new MediaCleanupHandler(
            $c->get(Database::class),
            $root . '/' . ltrim(Env::string('STORAGE_PUBLIC', 'public/uploads'), '/'),
            $c->get(Logger::class),
        ),
        new RetentionHandler(
            $c->get(Database::class),
            $c->get(RetentionPolicy::class),
            $c->get(PrivateStorage::class),
            $c->get(Logger::class),
        ),
        new BoostExpiryHandler($c->get(BoostService::class), $c->get(BillingService::class)),
        new SearchReindexHandler($c->get(ListingIndexer::class)),
        new LogRotationHandler($c->get('paths.logs'), $c->get(RetentionPolicy::class)),
        new BanExpiryHandler($c->get(UserModerationService::class)),
    ];

    $indiziert = [];
    foreach ($handlers as $handler) {
        $indiziert[$handler->type()] = $handler;
    }

    return $indiziert;
});

$container->set(JobRunner::class, static function (Container $c): JobRunner {
    /** @var array<string, JobHandler> $handlers */
    $handlers = $c->get('jobs.handlers');

    return new JobRunner(
        $c->get(JobRepository::class),
        $handlers,
        $c->get(Clock::class),
        $c->get(Logger::class),
        // Die Worker-Kennung macht im Protokoll unterscheidbar, wer was
        // angefasst hat.
        gethostname() . ':' . getmypid(),
    );
});

$container->set(JobScheduler::class, static fn(Container $c): JobScheduler => new JobScheduler(
    $c->get(JobRepository::class),
    $c->get(Clock::class),
));

// ------------------------------------------------------------------ DSGVO
$container->set(DataExportService::class, static fn(Container $c): DataExportService => new DataExportService(
    $c->get(Database::class),
    $c->get(AuditLog::class),
    $c->get(Clock::class),
));

$container->set(AccountDeletionService::class, static fn(Container $c): AccountDeletionService => new AccountDeletionService(
    $c->get(Database::class),
    $c->get(PublicImageStorage::class),
    $c->get(PrivateStorage::class),
    $c->get(AuditLog::class),
    $c->get(Clock::class),
));

$container->set(PrivacyController::class, static fn(Container $c): PrivacyController => new PrivacyController(
    $c->get(DataExportService::class),
    $c->get(AccountDeletionService::class),
    $c->get(UserRepository::class),
    $c->get(PasswordHasher::class),
    $c->get(Viewer::class),
    $c->get(SessionManager::class),
    $c->get(Environment::class),
));

// -------------------------------------------------------------- Verwaltung
$container->set(DashboardService::class, static fn(Container $c): DashboardService => new DashboardService(
    $c->get(Database::class),
    $c->get(JobRepository::class),
    $c->get(LegalTextReview::class),
    $c->get(Clock::class),
));

$container->set(SpeciesCatalogService::class, static fn(Container $c): SpeciesCatalogService => new SpeciesCatalogService(
    $c->get(SpeciesRepository::class),
    $c->get(MorphRepository::class),
    $c->get(AuditLog::class),
));

$container->set(AdminController::class, static fn(Container $c): AdminController => new AdminController(
    $c->get(DashboardService::class),
    $c->get(SpeciesCatalogService::class),
    $c->get(JobRepository::class),
    $c->get(RetentionPolicy::class),
    $c->get(UiTextService::class),
    $c->get(Viewer::class),
    $c->get(SessionManager::class),
    $c->get(Translator::class),
    $c->get(Environment::class),
));

$container->set(AdminContentController::class, static fn(Container $c): AdminContentController => new AdminContentController(
    $c->get(ContentService::class),
    $c->get(ContentEntryRepository::class),
    $c->get(ContentBlockRepository::class),
    $c->get(ContentRevisionRepository::class),
    $c->get(ContentTermRepository::class),
    $c->get(PreviewService::class),
    $c->get(MediaService::class),
    $c->get(ContentPermission::class),
    $c->get(Viewer::class),
    $c->get(SessionManager::class),
    $c->get(Translator::class),
    $c->get(Environment::class),
));

$container->set(NewsController::class, static fn(Container $c): NewsController => new NewsController(
    $c->get(ContentEntryRepository::class),
    $c->get(ContentTermRepository::class),
    $c->get(ContentSearchIndex::class),
    $c->get(Environment::class),
    Env::string('APP_URL', 'https://example.tld'),
));

$container->set(AdminMediaController::class, static fn(Container $c): AdminMediaController => new AdminMediaController(
    $c->get(MediaService::class),
    $c->get(MediaRepository::class),
    $c->get(MediaUsageRepository::class),
    $c->get(ContentPermission::class),
    $c->get(Viewer::class),
    $c->get(SessionManager::class),
    $c->get(Translator::class),
    $c->get(Environment::class),
));

$container->set(Kernel::class, static fn(Container $c): Kernel => new Kernel(
    $c->get(Router::class),
    $c,
    [new SessionMiddleware($c->get(SessionManager::class))],
    Env::bool('APP_DEBUG'),
    $c->get(Logger::class),
));

return $container;
