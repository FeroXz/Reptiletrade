<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Site;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Domain\Site\SiteIdentity;

#[CoversClass(SiteIdentity::class)]
final class SiteIdentityTest extends TestCase
{
    public function testDieAuslieferungIstAbsichtlichUnvollstaendig(): void
    {
        /** @var array<string, mixed> $vorlage */
        $vorlage = require \dirname(__DIR__, 3) . '/config/impressum.php';
        $identity = new SiteIdentity($vorlage);

        // Ein Impressum mit Platzhaltern sieht aus wie ein fertiges. Genau
        // deshalb muss die mitgelieferte Datei durchfallen.
        self::assertFalse($identity->isComplete());
        self::assertNotSame([], $identity->missing());
    }

    public function testVollstaendigeAngabenBestehenDiePruefung(): void
    {
        $identity = new SiteIdentity($this->vollstaendig());

        self::assertSame([], $identity->missing());
        self::assertTrue($identity->isComplete());
    }

    public function testDasFlagAlleinReichtNichtUndFehltEsWirdGemeckert(): void
    {
        // Angaben vollstaendig, aber der Schalter steht noch auf "unfertig":
        // Dann bleibt der Hinweis stehen.
        $identity = new SiteIdentity(['unvollstaendig' => true] + $this->vollstaendig());

        self::assertSame([], $identity->missing());
        self::assertFalse($identity->isComplete());
    }

    public function testEinPlatzhalterZaehltNichtAlsAngabe(): void
    {
        $config = $this->vollstaendig();
        $config['anbieter']['strasse'] = 'BITTE AUSFÜLLEN — Straße und Hausnummer';

        $fehlt = (new SiteIdentity($config))->missing();

        self::assertCount(1, $fehlt);
        self::assertStringContainsString('Postfach', $fehlt[0]);
    }

    public function testEineUnbrauchbareAdresseFehlt(): void
    {
        $config = $this->vollstaendig();
        $config['kontakt']['email'] = 'kein-at-zeichen';

        self::assertNotSame([], (new SiteIdentity($config))->missing());
    }

    public function testDiePlatzhalterPostleitzahlZaehltNicht(): void
    {
        $config = $this->vollstaendig();
        $config['anbieter']['plz'] = '00000';

        self::assertSame(['Postleitzahl'], (new SiteIdentity($config))->missing());
    }

    public function testDieAnschriftKommtInDerReihenfolgeDesBriefkopfes(): void
    {
        $config = $this->vollstaendig();
        $config['anbieter']['rechtsform'] = 'Einzelunternehmen';
        $config['anbieter']['vertreten_durch'] = 'Vertreten durch Anke Beispiel';

        self::assertSame([
            'Beispiel Reptilien',
            'Einzelunternehmen',
            'Vertreten durch Anke Beispiel',
            'Musterweg 3',
            '12345 Musterstadt',
            'Deutschland',
        ], (new SiteIdentity($config))->addressLines());
    }

    public function testLeereFelderErscheinenNichtInDerAnschrift(): void
    {
        $zeilen = (new SiteIdentity($this->vollstaendig()))->addressLines();

        self::assertSame(['Beispiel Reptilien', 'Musterweg 3', '12345 Musterstadt', 'Deutschland'], $zeilen);
    }

    public function testDieStreitschlichtungIstVoreingestelltAbgelehnt(): void
    {
        $identity = new SiteIdentity($this->vollstaendig());

        // § 36 VSBG: Die Aussage muss dastehen — auch die verneinende.
        self::assertFalse($identity->readyForDisputeResolution());
        self::assertSame('', $identity->disputeBody());
    }

    public function testEineBenannteSchlichtungsstelleWirdDurchgereicht(): void
    {
        $config = $this->vollstaendig();
        $config['streitschlichtung'] = ['bereit' => true, 'stelle' => 'Universalschlichtungsstelle des Bundes'];

        $identity = new SiteIdentity($config);

        self::assertTrue($identity->readyForDisputeResolution());
        self::assertSame('Universalschlichtungsstelle des Bundes', $identity->disputeBody());
    }

    public function testFehlendeAbschnitteStuerzenNichtAb(): void
    {
        $identity = new SiteIdentity([]);

        self::assertSame([], $identity->provider());
        self::assertSame([], $identity->contact());
        self::assertSame([], $identity->register());
        self::assertSame([], $identity->privacy());
        self::assertSame([], $identity->hosting());
        self::assertSame('ersatz', $identity->value('umsatzsteuer_id', 'ersatz'));
        self::assertSame([], $identity->addressLines());
        self::assertFalse($identity->isComplete());
    }

    /**
     * @return array<string, mixed>
     */
    private function vollstaendig(): array
    {
        return [
            'unvollstaendig' => false,
            'anbieter' => [
                'name' => 'Beispiel Reptilien',
                'rechtsform' => '',
                'strasse' => 'Musterweg 3',
                'plz' => '12345',
                'ort' => 'Musterstadt',
                'land' => 'Deutschland',
                'vertreten_durch' => '',
            ],
            'kontakt' => ['email' => 'kontakt@example.tld', 'telefon' => '', 'formular' => '/kontakt'],
            'register' => ['gericht' => '', 'nummer' => ''],
            'umsatzsteuer_id' => '',
            'streitschlichtung' => ['bereit' => false, 'stelle' => ''],
            'datenschutz' => ['verantwortlicher' => '', 'aufsichtsbehoerde' => 'Landesbehörde'],
            'hosting' => ['anbieter' => 'Beispiel Hosting GmbH', 'ort' => 'Deutschland', 'avv_geschlossen' => true],
        ];
    }
}
