<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Trust;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Domain\Trust\AutoModerationPolicy;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;

#[CoversClass(AutoModerationPolicy::class)]
final class AutoModerationPolicyTest extends TestCase
{
    private const string JETZT = '2026-08-03T12:00:00+00:00';

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::JETZT);
    }

    private function user(
        string $created = '2026-08-01T12:00:00+00:00',
        Role $role = Role::Seller,
        bool $identityVerified = false,
    ): User {
        $moment = new DateTimeImmutable($created);

        return new User(
            1,
            'zuechter@example.tld',
            'Testzüchter',
            $role,
            emailVerifiedAt: $moment,
            phoneVerifiedAt: $identityVerified ? $moment : null,
            identityVerifiedAt: $identityVerified ? $moment : null,
            createdAt: $moment,
        );
    }

    public function testDieErstenDreiAnzeigenGehenInDiePruefung(): void
    {
        $policy = new AutoModerationPolicy(true, 3, 30);

        foreach ([0, 1, 2] as $bereitsVeroeffentlicht) {
            self::assertTrue(
                $policy->requiresReview($this->user(), $bereitsVeroeffentlicht, $this->now()),
                'Anzeige Nummer ' . ($bereitsVeroeffentlicht + 1),
            );
        }
    }

    public function testAbDerViertenAnzeigeGehtEsDirektOnline(): void
    {
        self::assertFalse((new AutoModerationPolicy(true, 3, 30))->requiresReview($this->user(), 3, $this->now()));
    }

    /**
     * Wer seit Monaten dabei ist, ist kein Wegwerfkonto — auch wenn er
     * bisher nichts eingestellt hat.
     */
    public function testAelterekontenFallenNichtInDiePruefung(): void
    {
        $alt = $this->user('2026-01-01T12:00:00+00:00');

        self::assertFalse((new AutoModerationPolicy(true, 3, 30))->requiresReview($alt, 0, $this->now()));
    }

    public function testGepruefteIdentitaetUeberspringtDiePruefung(): void
    {
        $geprueft = $this->user(identityVerified: true);

        self::assertFalse((new AutoModerationPolicy(true, 3, 30))->requiresReview($geprueft, 0, $this->now()));
    }

    public function testModerationUndAdministrationStellenDirektOnline(): void
    {
        $policy = new AutoModerationPolicy(true, 3, 30);

        self::assertFalse($policy->requiresReview($this->user(role: Role::Moderator), 0, $this->now()));
        self::assertFalse($policy->requiresReview($this->user(role: Role::Admin), 0, $this->now()));
    }

    public function testAbgeschalteteRegelGreiftNie(): void
    {
        self::assertFalse((new AutoModerationPolicy(false, 3, 30))->requiresReview($this->user(), 0, $this->now()));
    }

    public function testDerGrundNenntDieAnzahl(): void
    {
        self::assertStringContainsString('3', (new AutoModerationPolicy(true, 3, 30))->reason());
    }
}
