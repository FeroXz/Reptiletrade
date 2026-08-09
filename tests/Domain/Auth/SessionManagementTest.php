<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Auth;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Auth\DeviceFingerprint;
use Reptilienmarkt\Domain\Auth\Session;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Infra\Persistence\PdoSessionRepository;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;

#[CoversClass(DeviceFingerprint::class)]
#[CoversClass(PdoSessionRepository::class)]
#[CoversClass(SessionManager::class)]
final class SessionManagementTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    private PdoSessionRepository $sessions;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-09T12:00:00Z'));
        $this->sessions = new PdoSessionRepository($this->database);
        $this->userId = $this->createUser('halter@example.tld');
    }

    // ------------------------------------------------------ Kuerzung

    public function testDieAdresseVerliertIhrLetztesOktett(): void
    {
        self::assertSame('203.0.113.0', DeviceFingerprint::shortenIp('203.0.113.42'));
        self::assertSame('10.0.0.0', DeviceFingerprint::shortenIp('10.0.0.255'));
    }

    public function testIpv6VerliertDenInterfaceIdentifier(): void
    {
        // Die unteren 64 Bit sind das Geraet, die oberen das Netz.
        self::assertSame('2001:db8:1234:5678::', DeviceFingerprint::shortenIp('2001:db8:1234:5678:9abc:def0:1234:5678'));
    }

    public function testEinUnbrauchbarerWertWirdVerworfenStattRepariert(): void
    {
        self::assertNull(DeviceFingerprint::shortenIp('kein.ip.wert'));
        self::assertNull(DeviceFingerprint::shortenIp(''));
        self::assertNull(DeviceFingerprint::shortenIp(null));
    }

    public function testDieBrowserkennungWirdGekuerzt(): void
    {
        $lang = str_repeat('a', 400);

        self::assertSame(180, mb_strlen((string) DeviceFingerprint::shortenAgent($lang)));
        // Zeilenumbrueche kaemen nur aus einem Versuch, die Ausgabe zu zerlegen.
        self::assertSame('Mozilla 5.0 Firefox', DeviceFingerprint::shortenAgent("Mozilla 5.0\nFirefox"));
    }

    public function testDieGeraetebezeichnungBleibtGrob(): void
    {
        self::assertSame('Firefox auf Android', DeviceFingerprint::label(
            'Mozilla/5.0 (Android 14; Mobile; rv:126.0) Gecko/126.0 Firefox/126.0',
        ));
        self::assertSame('Safari auf iOS', DeviceFingerprint::label(
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Version/17.5 Safari/605.1.15',
        ));
        self::assertSame('Unbekanntes Gerät', DeviceFingerprint::label(null));
    }

    public function testDieSitzungSpeichertNurDasNetz(): void
    {
        $manager = $this->manager();
        $manager->start(new Request(
            'GET',
            '/',
            clientIp: '203.0.113.42',
            headers: ['user-agent' => str_repeat('x', 400)],
        ));
        $manager->login($this->userId);
        $manager->commit();

        $zeile = $this->database->selectOne('SELECT ip_address, user_agent FROM sessions');

        self::assertNotNull($zeile);
        self::assertSame('203.0.113.0', $zeile['ip_address']);
        self::assertSame(180, mb_strlen((string) $zeile['user_agent']));
    }

    // ------------------------------------------------------ Verwaltung

    public function testEineBeendeteSitzungIstBeimNaechstenZugriffAbgemeldet(): void
    {
        $manager = $this->manager();
        $manager->start(new Request('GET', '/', clientIp: '203.0.113.42'));
        $manager->login($this->userId);
        $manager->commit();

        $id = $manager->id();

        // Von einem anderen Geraet aus beendet.
        $this->sessions->delete($id);

        // Naechster Zugriff mit demselben Cookie: keine angemeldete Sitzung.
        $zweiter = $this->manager();
        $zweiter->start(new Request('GET', '/', cookies: [SessionManager::COOKIE_NAME => $id]));

        self::assertFalse($zweiter->isAuthenticated());
        self::assertNotSame($id, $zweiter->id());
    }

    public function testAlleAndererSitzungenEndenAberDieEigeneBleibt(): void
    {
        $eigene = $this->anlegen('eigene');
        $fremdesGeraet = $this->anlegen('anderes');
        $andererNutzer = $this->anlegen('fremd', $this->createUser('zweiter@example.tld'));

        self::assertSame(1, $this->sessions->deleteForUserExcept($this->userId, $eigene));

        self::assertNotNull($this->sessions->find($eigene));
        self::assertNull($this->sessions->find($fremdesGeraet));
        // Die Sitzung eines anderen Kontos ist nicht betroffen.
        self::assertNotNull($this->sessions->find($andererNutzer));
    }

    public function testDieListeZeigtNurEigeneUndNochGueltigeSitzungen(): void
    {
        $this->anlegen('eigene');
        $this->anlegen('fremd', $this->createUser('zweiter@example.tld'));
        $this->anlegen('abgelaufen', $this->userId, new DateTimeImmutable('-1 hour'));

        $liste = $this->sessions->forUser($this->userId);

        self::assertCount(1, $liste);
        self::assertSame('eigene', $liste[0]->id);
    }

    private function manager(): SessionManager
    {
        return new SessionManager($this->sessions, $this->clock);
    }

    /**
     * Die Ablauffrage stellt die Ablage gegen die echte Uhr — wie
     * deleteExpired() im Bestand. Deshalb steht hier eine echte Zeit und nicht
     * die eingefrorene: Sonst faengt der Test irgendwann von selbst an zu
     * scheitern, sobald die Wirklichkeit an der festen Zeit vorbeizieht.
     */
    private function anlegen(string $id, ?int $userId = null, ?DateTimeImmutable $expires = null): string
    {
        $this->sessions->save(new Session(
            $id,
            $userId ?? $this->userId,
            [],
            $this->clock->now(),
            $this->clock->now(),
            $expires ?? new DateTimeImmutable('+1 day'),
            false,
            '203.0.113.0',
            'Mozilla/5.0 (Windows NT 10.0) Firefox/126.0',
        ));

        return $id;
    }
}
