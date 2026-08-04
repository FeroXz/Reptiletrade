<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Contact;

use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Contact\ContactException;
use Reptilienmarkt\Domain\Contact\ContactMessage;
use Reptilienmarkt\Domain\Contact\ContactService;
use Reptilienmarkt\Domain\Contact\ContactTopic;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoContactRepository;
use Reptilienmarkt\Support\Translator;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\CollectingMailer;

#[CoversClass(ContactService::class)]
#[CoversClass(ContactMessage::class)]
#[CoversClass(ContactTopic::class)]
#[CoversClass(PdoContactRepository::class)]
final class ContactServiceTest extends DatabaseTestCase
{
    private ContactService $contact;

    private PdoContactRepository $messages;

    private CollectingMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->messages = new PdoContactRepository($this->database);
        $this->mailer = new CollectingMailer();

        $this->contact = new ContactService(
            $this->messages,
            $this->mailer,
            new Translator(\dirname(__DIR__, 3) . '/lang'),
            new PdoAuditLog($this->database),
            'verwaltung@example.tld',
        );
    }

    public function testEineAnfrageWirdGespeichertUndGemeldet(): void
    {
        $id = $this->contact->submit($this->eingaben(), null, '203.0.113.7');

        $nachricht = $this->messages->find($id);

        self::assertNotNull($nachricht);
        self::assertSame('anke@example.tld', $nachricht->email);
        self::assertSame(ContactTopic::Konto, $nachricht->topic);
        self::assertFalse($nachricht->handled);

        // Nur Datenbank waere zu wenig — niemand schaut ohne Anlass in die
        // Warteschlange.
        self::assertCount(1, $this->mailer->messages());
        self::assertSame('verwaltung@example.tld', $this->mailer->messages()[0]->to);
    }

    public function testOhneAnmeldungGehtEsAuch(): void
    {
        $id = $this->contact->submit($this->eingaben(), null, null);

        self::assertNull($this->messages->find($id)?->userId);
    }

    public function testAngemeldeteKoennenDenAbsenderNichtFaelschen(): void
    {
        $userId = $this->createUser('echt@example.tld');
        $user = new User($userId, 'echt@example.tld', 'Echter Name');

        $id = $this->contact->submit(
            ['name' => 'Jemand anderes', 'email' => 'fremd@example.tld'] + $this->eingaben(),
            $user,
            null,
        );

        $nachricht = $this->messages->find($id);

        self::assertNotNull($nachricht);
        self::assertSame('echt@example.tld', $nachricht->email);
        self::assertSame('Echter Name', $nachricht->name);
        self::assertSame($userId, $nachricht->userId);
    }

    public function testEineUnbrauchbareAdresseWirdAbgelehnt(): void
    {
        $this->expectException(ContactException::class);
        $this->expectExceptionMessageMatches('/E-Mail/');

        $this->contact->submit(['email' => 'keine-adresse'] + $this->eingaben(), null, null);
    }

    public function testOhneNamenKeineAnfrage(): void
    {
        $this->expectException(ContactException::class);

        $this->contact->submit(['name' => '   '] + $this->eingaben(), null, null);
    }

    public function testEinZuKurzerTextWirdAbgelehnt(): void
    {
        $this->expectException(ContactException::class);
        $this->expectExceptionMessageMatches('/Anliegen/');

        $this->contact->submit(['nachricht' => 'Hilfe!'] + $this->eingaben(), null, null);
    }

    public function testEinUnbekanntesThemaWirdZurFrage(): void
    {
        $id = $this->contact->submit(['thema' => 'gibt-es-nicht'] + $this->eingaben(), null, null);

        self::assertSame(ContactTopic::Frage, $this->messages->find($id)?->topic);
    }

    public function testUeberlangeEingabenWerdenGekuerztStattAbgelehnt(): void
    {
        $id = $this->contact->submit(
            [
                'betreff' => str_repeat('A', ContactService::MAX_SUBJECT + 50),
                'nachricht' => str_repeat('B', ContactService::MAX_BODY + 500),
            ] + $this->eingaben(),
            null,
            null,
        );

        $nachricht = $this->messages->find($id);

        self::assertSame(ContactService::MAX_SUBJECT, mb_strlen((string) $nachricht?->subject));
        self::assertSame(ContactService::MAX_BODY, mb_strlen((string) $nachricht?->body));
    }

    public function testDerInhaltLandetNichtImAuditTrail(): void
    {
        $this->contact->submit(
            ['nachricht' => 'Mein Tier ist krank, ich brauche dringend Hilfe bei der Anzeige.'] + $this->eingaben(),
            null,
            null,
        );

        $eintrag = $this->database->selectOne("SELECT data_json FROM audit_log WHERE action = 'contact.received'");

        self::assertNotNull($eintrag);
        // Der Trail wird nie geloescht — was hineingeschrieben wird, bleibt.
        self::assertStringNotContainsString('krank', (string) $eintrag['data_json']);
    }

    public function testOhneAdminadresseWirdKeineMailVerschickt(): void
    {
        $ohneMail = new ContactService(
            $this->messages,
            $this->mailer,
            new Translator(\dirname(__DIR__, 3) . '/lang'),
            new PdoAuditLog($this->database),
            '',
        );

        $id = $ohneMail->submit($this->eingaben(), null, null);

        // Die Nachricht ist trotzdem da: Der fehlende Postausgang darf sie
        // nicht verschlucken.
        self::assertNotNull($this->messages->find($id));
        self::assertSame([], $this->mailer->messages());
    }

    public function testDieVerwaltungHaktAnfragenAb(): void
    {
        $adminId = $this->createUser('admin@example.tld');
        $id = $this->contact->submit($this->eingaben(), null, null);

        self::assertSame(1, $this->messages->openCount());
        self::assertCount(1, $this->messages->recent());

        $this->messages->markHandled($id, $adminId, 'Per Mail beantwortet.');

        self::assertSame(0, $this->messages->openCount());
        self::assertSame([], $this->messages->recent());
        self::assertCount(1, $this->messages->recent(false));

        $nachricht = $this->messages->find($id);

        self::assertNotNull($nachricht);
        self::assertTrue($nachricht->handled);
        self::assertSame('Per Mail beantwortet.', $nachricht->handledNote);
    }

    /**
     * @return array<string, string>
     */
    private function eingaben(): array
    {
        return [
            'name' => 'Anke Beispiel',
            'email' => 'anke@example.tld',
            'thema' => 'konto',
            'betreff' => 'Frage zur Freischaltung',
            'nachricht' => 'Mein Konto ist seit gestern gesperrt und ich weiß nicht warum.',
        ];
    }
}
