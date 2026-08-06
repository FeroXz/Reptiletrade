<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Site;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Site\TextException;
use Reptilienmarkt\Domain\Site\UiText;
use Reptilienmarkt\Domain\Site\UiTextService;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoTextOverrideRepository;
use Reptilienmarkt\Support\Translator;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;

/**
 * Die Textverwaltung: Was die Verwaltung aendert, gilt sofort — und laesst
 * sich jederzeit auf den ausgelieferten Text zuruecknehmen.
 */
#[CoversClass(UiTextService::class)]
#[CoversClass(UiText::class)]
#[CoversClass(PdoTextOverrideRepository::class)]
#[CoversClass(Translator::class)]
final class UiTextServiceTest extends DatabaseTestCase
{
    private UiTextService $texts;

    private PdoTextOverrideRepository $overrides;

    private Translator $translator;

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminId = $this->createUser('verwaltung@example.tld');
        $this->overrides = new PdoTextOverrideRepository($this->database);
        $this->translator = new Translator(\dirname(__DIR__, 3) . '/lang', Translator::BASE_LOCALE, $this->overrides);

        $this->texts = new UiTextService(
            $this->translator,
            $this->overrides,
            new PdoAuditLog($this->database),
            new FrozenClock(new DateTimeImmutable('2026-08-05T12:00:00+00:00')),
        );
    }

    public function testDieListeEnthaeltDenGanzenKatalog(): void
    {
        $texte = $this->texts->all();

        self::assertGreaterThan(200, \count($texte));
        self::assertSame('Verwaltung', $this->text('admin.titel')->original);
        self::assertFalse($this->text('admin.titel')->isOverridden);
    }

    public function testEineAenderungGiltSofort(): void
    {
        $this->texts->update('postfach.titel', 'Nachrichten', $this->adminId);

        self::assertSame('Nachrichten', $this->translator->translate('postfach.titel'));

        $text = $this->text('postfach.titel');
        self::assertTrue($text->isOverridden);
        self::assertSame('Nachrichten', $text->current);
        self::assertSame('Postfach', $text->original, 'Der ausgelieferte Text bleibt daneben stehen.');
    }

    public function testZuruecksetzenStelltDenAusgelieferteTextWiederHer(): void
    {
        $this->texts->update('postfach.titel', 'Nachrichten', $this->adminId);
        $this->texts->reset('postfach.titel', $this->adminId);

        self::assertSame('Postfach', $this->translator->translate('postfach.titel'));
        self::assertFalse($this->text('postfach.titel')->isOverridden);
        self::assertSame(0, $this->texts->changedCount());
    }

    /**
     * Wer den ausgelieferten Text wieder eintippt, meint "zurueck auf Anfang" —
     * und bekommt keinen Eintrag, der behauptet, hier sei etwas geaendert.
     */
    public function testDerAusgelieferteTextWirdNichtAlsAenderungGespeichert(): void
    {
        $this->texts->update('postfach.titel', 'Postfach', $this->adminId);

        self::assertSame(0, $this->texts->changedCount());
        self::assertFalse($this->text('postfach.titel')->isOverridden);
    }

    /**
     * Der wichtigste Fall: Aus "Der Code gilt {minuten} Minuten" darf kein
     * "Der Code gilt kurz" werden — die Zahl fehlt dann fuer immer, und
     * auffallen wuerde es erst dem Nutzer.
     */
    public function testEinFehlenderPlatzhalterWirdAbgelehnt(): void
    {
        $this->expectException(TextException::class);
        $this->expectExceptionMessage('{minuten}');

        $this->texts->update('konto.telefon_code_gesendet', 'Der Code ist unterwegs.', $this->adminId);
    }

    public function testDerPlatzhalterDarfSichVerschieben(): void
    {
        $this->texts->update('konto.telefon_code_gesendet', '{minuten} Minuten gültig — der Code ist unterwegs.', $this->adminId);

        self::assertSame(
            '15 Minuten gültig — der Code ist unterwegs.',
            $this->translator->translate('konto.telefon_code_gesendet', ['minuten' => 15]),
        );
    }

    public function testEinUnbekannterSchluesselWirdAbgelehnt(): void
    {
        $this->expectException(TextException::class);

        $this->texts->update('gibt.es.nicht', 'Text', $this->adminId);
    }

    public function testEinLeererTextWirdAbgelehnt(): void
    {
        $this->expectException(TextException::class);

        $this->texts->update('postfach.titel', '   ', $this->adminId);
    }

    public function testEinZuLangerTextWirdAbgelehnt(): void
    {
        $this->expectException(TextException::class);

        $this->texts->update('postfach.titel', str_repeat('a', UiTextService::MAX_LENGTH + 1), $this->adminId);
    }

    public function testJedeAenderungStehtImAuditLog(): void
    {
        $this->texts->update('postfach.titel', 'Nachrichten', $this->adminId);
        $this->texts->reset('postfach.titel', $this->adminId);

        $eintraege = $this->database->select(
            "SELECT action FROM audit_log WHERE entity_type = 'ui_text' ORDER BY id",
        );

        self::assertSame(['text.updated', 'text.reset'], array_column($eintraege, 'action'));
    }

    public function testZuruecksetzenOhneAenderungSchreibtNichts(): void
    {
        $this->texts->reset('postfach.titel', $this->adminId);

        $anzahl = $this->database->scalar("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'ui_text'");

        self::assertSame(0, (int) (is_numeric($anzahl) ? $anzahl : 0));
    }

    // ------------------------------------------------------ Suchen und Filtern

    public function testDieSucheFindetSchluesselUndText(): void
    {
        self::assertNotEmpty($this->texts->all('postfach.titel'));
        self::assertNotEmpty($this->texts->all('Postfach'));
        self::assertSame([], $this->texts->all('gibtesganzsichernicht'));
    }

    public function testDerBereichsfilterGrenztEin(): void
    {
        $texte = $this->texts->all(null, 'postfach');

        self::assertNotEmpty($texte);

        foreach ($texte as $text) {
            self::assertSame('postfach', $text->section());
        }
    }

    public function testDieBereicheKommenAusDenSchluesseln(): void
    {
        $bereiche = $this->texts->sections();

        self::assertContains('admin', $bereiche);
        self::assertContains('postfach', $bereiche);
        self::assertSame($bereiche, array_unique($bereiche));
    }

    public function testPlatzhalterWerdenErkannt(): void
    {
        self::assertSame(['minuten'], $this->text('konto.telefon_code_gesendet')->placeholders());
        self::assertSame([], $this->text('postfach.titel')->placeholders());
        self::assertSame(['anzahl', 'grenze'], UiText::placeholdersIn('{anzahl} von {grenze}'));
    }

    private function text(string $key): UiText
    {
        return $this->texts->find($key) ?? self::fail('Unbekannter Schlüssel ' . $key);
    }
}
