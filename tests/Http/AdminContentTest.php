<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Http;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Content\ContentPermission;
use Reptilienmarkt\Domain\Content\ContentService;
use Reptilienmarkt\Domain\Content\ContentStatus;
use Reptilienmarkt\Domain\Content\ContentType;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Domain\User\UserStatus;
use Reptilienmarkt\Http\Controller\AdminContentController;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Session\NotAuthenticatedException;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\View\TwigFactory;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoContentBlockRepository;
use Reptilienmarkt\Infra\Persistence\PdoContentEditorRepository;
use Reptilienmarkt\Infra\Persistence\PdoContentEntryRepository;
use Reptilienmarkt\Infra\Persistence\PdoSessionRepository;
use Reptilienmarkt\Support\Translator;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;
use Reptilienmarkt\Tests\Support\StubViewer;

/**
 * Die Redaktionsoberflaeche ueber HTTP: Zugriffsschutz, Anlegen, Bloecke,
 * Veroeffentlichen — und was davon im Audit-Trail landet.
 */
#[CoversClass(AdminContentController::class)]
#[CoversClass(ContentService::class)]
#[CoversClass(ContentPermission::class)]
final class AdminContentTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    private PdoContentEntryRepository $entries;

    private PdoContentBlockRepository $blocks;

    private PdoContentEditorRepository $editors;

    private SessionManager $session;

    private string $csrf = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-08T10:00:00+00:00'));
        $this->entries = new PdoContentEntryRepository($this->database);
        $this->blocks = new PdoContentBlockRepository($this->database);
        $this->editors = new PdoContentEditorRepository($this->database);
        $this->session = new SessionManager(new PdoSessionRepository($this->database), $this->clock);
        $this->session->start(new Request('GET', '/admin/inhalte'));
        $this->csrf = $this->session->csrfToken();
    }

    // ------------------------------------------------------ Zugriffsschutz

    public function testOhneAnmeldungKeineListe(): void
    {
        $this->expectException(NotAuthenticatedException::class);

        $this->controller(null)->index(new Request('GET', '/admin/inhalte'));
    }

    public function testEinGewoehnlichesKontoBekommt404StattEines403(): void
    {
        // 404 statt 403: Die Redaktion muss sich nicht dadurch verraten, dass
        // sie einen Zugriff ablehnt.
        try {
            $this->controller($this->user(5, Role::Seller))->index(new Request('GET', '/admin/inhalte'));
            self::fail('Der Zugriff haette abgewiesen werden muessen.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->status);
        }
    }

    public function testEinRedakteurDarfDieListeSehen(): void
    {
        $id = $this->createUser('redaktion@example.tld');
        $this->editors->grant($id, $this->clock->now(), null);

        $response = $this->controller($this->user($id, Role::Seller))->index(new Request('GET', '/admin/inhalte'));

        self::assertSame(200, $response->status);
    }

    public function testDieVerwaltungDarfAuchOhneEintrag(): void
    {
        $response = $this->controller($this->user(1, Role::Admin))->index(new Request('GET', '/admin/inhalte'));

        self::assertSame(200, $response->status);
    }

    public function testEinGesperrtesRedaktionskontoDarfNichtMehr(): void
    {
        $id = $this->createUser('gesperrt@example.tld');
        $this->editors->grant($id, $this->clock->now(), null);

        $permission = new ContentPermission($this->editors);

        self::assertFalse($permission->mayEdit($this->user($id, Role::Seller, aktiv: false)));
    }

    // ------------------------------------------------------------ Anlegen

    public function testSeiteAnlegenVeroeffentlichenUndAbrufen(): void
    {
        $admin = $this->user($this->createUser('verwaltung@example.tld'), Role::Admin);
        $controller = $this->controller($admin);

        $response = $controller->create($this->post(['titel' => 'Haltung im Terrarium']));

        self::assertSame(302, $response->status);

        $entry = $this->entries->findByPath('/haltung-im-terrarium/');
        self::assertNotNull($entry);
        self::assertSame(ContentStatus::Entwurf, $entry->status);
        self::assertSame(ContentType::Seite, $entry->type);

        // Der Audit-Trail haelt die Anlage fest.
        $eintraege = (new PdoAuditLog($this->database))->forEntity('content_entry', $entry->id ?? 0);
        self::assertSame('content.created', $eintraege[0]['action']);
    }

    public function testEinBelegterPfadWirdMitNamenAbgewiesen(): void
    {
        $admin = $this->user($this->createUser('verwaltung@example.tld'), Role::Admin);

        $this->controller($admin)->create($this->post(['titel' => 'Impressum']));

        self::assertNull($this->entries->findByPath('/impressum/'));

        $meldungen = $this->session->takeFlashes();
        self::assertArrayHasKey('fehler', $meldungen);
        self::assertStringContainsString('/impressum/', $meldungen['fehler']);
    }

    // ------------------------------------------------------------ Bloecke

    public function testBlockHinzufuegenVerschiebenLoeschen(): void
    {
        $admin = $this->user($this->createUser('verwaltung@example.tld'), Role::Admin);
        $controller = $this->controller($admin);
        $id = $this->page($admin, 'Haltung');

        // Hinzufuegen ueber das Auswahlfeld — also ohne Javascript.
        $controller->save($this->post([
            'titel' => 'Haltung',
            'slug' => 'haltung',
            'aktion' => 'block-hinzufuegen',
            'neuer_block' => 'block-hinzufuegen:text',
        ], $id));

        self::assertCount(1, $this->blocks->forEntry($id));

        // Ein zweiter Block, dann tauschen.
        $controller->save($this->post([
            'titel' => 'Haltung',
            'slug' => 'haltung',
            'aktion' => 'block-hinzufuegen:zitat',
            'block' => [['typ' => 'text', 'text' => 'Erster']],
        ], $id));

        $bloecke = $this->blocks->forEntry($id);
        self::assertCount(2, $bloecke);
        self::assertSame('Erster', $bloecke[0]->string('text'));

        $controller->save($this->post([
            'titel' => 'Haltung',
            'slug' => 'haltung',
            'aktion' => 'block-hoch:1',
            'block' => [
                ['typ' => 'text', 'text' => 'Erster'],
                ['typ' => 'zitat', 'text' => 'Zweiter', 'quelle' => 'Q'],
            ],
        ], $id));

        $bloecke = $this->blocks->forEntry($id);
        self::assertSame('zitat', $bloecke[0]->type->value);
        self::assertSame('text', $bloecke[1]->type->value);

        $controller->save($this->post([
            'titel' => 'Haltung',
            'slug' => 'haltung',
            'aktion' => 'block-loeschen:0',
            'block' => [
                ['typ' => 'zitat', 'text' => 'Zweiter', 'quelle' => 'Q'],
                ['typ' => 'text', 'text' => 'Erster'],
            ],
        ], $id));

        $bloecke = $this->blocks->forEntry($id);
        self::assertCount(1, $bloecke);
        self::assertSame('text', $bloecke[0]->type->value);
    }

    public function testFremdeFelderLandenNichtInDenBlockdaten(): void
    {
        $admin = $this->user($this->createUser('verwaltung@example.tld'), Role::Admin);
        $id = $this->page($admin, 'Haltung');

        $this->controller($admin)->save($this->post([
            'titel' => 'Haltung',
            'slug' => 'haltung',
            'block' => [['typ' => 'text', 'text' => 'Sichtbar', 'geschmuggelt' => '<script>']],
        ], $id));

        $daten = $this->blocks->forEntry($id)[0]->data;

        self::assertSame(['text' => 'Sichtbar'], $daten);
    }

    public function testEinCtaZielMitJavascriptWirdVerworfen(): void
    {
        $admin = $this->user($this->createUser('verwaltung@example.tld'), Role::Admin);
        $id = $this->page($admin, 'Haltung');

        $this->controller($admin)->save($this->post([
            'titel' => 'Haltung',
            'slug' => 'haltung',
            'block' => [['typ' => 'cta', 'label' => 'Klick', 'ziel' => 'javascript:alert(1)']],
        ], $id));

        self::assertSame('', $this->blocks->forEntry($id)[0]->string('ziel'));
    }

    // ---------------------------------------------------- Veroeffentlichung

    public function testVeroeffentlichenSetztDenStatusUndProtokolliert(): void
    {
        $admin = $this->user($this->createUser('verwaltung@example.tld'), Role::Admin);
        $id = $this->page($admin, 'Haltung');

        $this->controller($admin)->publish($this->post(['termin' => ''], $id));

        $entry = $this->entries->findById($id);
        self::assertNotNull($entry);
        self::assertSame(ContentStatus::Veroeffentlicht, $entry->status);
        self::assertNotNull($entry->publishedAt);

        $aktionen = array_column((new PdoAuditLog($this->database))->forEntity('content_entry', $id), 'action');
        self::assertContains('content.published', $aktionen);
    }

    public function testEinTerminInDerZukunftErgibtGeplant(): void
    {
        $admin = $this->user($this->createUser('verwaltung@example.tld'), Role::Admin);
        $id = $this->page($admin, 'Haltung');

        $this->controller($admin)->publish($this->post(['termin' => '2026-12-24T09:00:00+00:00'], $id));

        $entry = $this->entries->findById($id);
        self::assertNotNull($entry);
        self::assertSame(ContentStatus::Geplant, $entry->status);
    }

    public function testEinUnlesbarerTerminWirdAbgewiesen(): void
    {
        $admin = $this->user($this->createUser('verwaltung@example.tld'), Role::Admin);
        $id = $this->page($admin, 'Haltung');

        // Nicht stillschweigend "jetzt": Das waere die Sorte Fehler, die erst
        // auffaellt, wenn ein Beitrag zu frueh online steht.
        $this->controller($admin)->publish($this->post(['termin' => 'irgendwann'], $id));

        $entry = $this->entries->findById($id);
        self::assertNotNull($entry);
        self::assertSame(ContentStatus::Entwurf, $entry->status);
    }

    public function testZuruecknehmenMachtWiederEinenEntwurf(): void
    {
        $admin = $this->user($this->createUser('verwaltung@example.tld'), Role::Admin);
        $id = $this->page($admin, 'Haltung');

        $controller = $this->controller($admin);
        $controller->publish($this->post(['termin' => ''], $id));
        $controller->unpublish($this->post([], $id));

        $entry = $this->entries->findById($id);
        self::assertNotNull($entry);
        self::assertSame(ContentStatus::Entwurf, $entry->status);

        $aktionen = array_column((new PdoAuditLog($this->database))->forEntity('content_entry', $id), 'action');
        self::assertContains('content.unpublished', $aktionen);
    }

    public function testLoeschenProtokolliertVorDemLoeschen(): void
    {
        $admin = $this->user($this->createUser('verwaltung@example.tld'), Role::Admin);
        $id = $this->page($admin, 'Haltung');

        $this->controller($admin)->delete($this->post([], $id));

        self::assertNull($this->entries->findById($id));

        $eintraege = (new PdoAuditLog($this->database))->forEntity('content_entry', $id);
        $geloescht = array_values(array_filter($eintraege, static fn(array $e): bool => $e['action'] === 'content.deleted'));

        self::assertCount(1, $geloescht);
        self::assertSame('/haltung/', $geloescht[0]['data']['pfad']);
    }

    public function testEineSeiteMitUnterseitenWirdNichtGeloescht(): void
    {
        $admin = $this->user($this->createUser('verwaltung@example.tld'), Role::Admin);
        $service = $this->service();

        $eltern = $service->create(ContentType::Seite, 'Haltung', null, null, $admin->id ?? 0);
        $service->create(ContentType::Seite, 'Terrarium', null, $eltern->id, $admin->id ?? 0);

        $this->controller($admin)->delete($this->post([], $eltern->id ?? 0));

        self::assertNotNull($this->entries->findById($eltern->id ?? 0));
        self::assertArrayHasKey('fehler', $this->session->takeFlashes());
    }

    // -------------------------------------------------------------- Helfer

    private function page(User $admin, string $title): int
    {
        return $this->service()->create(ContentType::Seite, $title, null, null, $admin->id ?? 0)->id ?? 0;
    }

    private function service(): ContentService
    {
        return new ContentService(
            $this->entries,
            $this->blocks,
            new PdoAuditLog($this->database),
            $this->clock,
        );
    }

    private function controller(?User $user): AdminContentController
    {
        $root = \dirname(__DIR__, 2);
        $translator = new Translator($root . '/lang');

        return new AdminContentController(
            $this->service(),
            $this->entries,
            $this->blocks,
            new ContentPermission($this->editors),
            new StubViewer($user),
            $this->session,
            $translator,
            TwigFactory::create($root . '/templates', true, null, $translator),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body, ?int $id = null): Request
    {
        $request = new Request('POST', '/admin/inhalte', [], $body + ['_csrf' => $this->csrf]);

        return $id === null ? $request : $request->withAttributes(['id' => (string) $id]);
    }

    private function user(int $id, Role $role, bool $aktiv = true): User
    {
        return new User(
            $id,
            'nutzer' . $id . '@example.tld',
            'Nutzer',
            $role,
            $aktiv ? UserStatus::Aktiv : UserStatus::Gesperrt,
        );
    }
}
