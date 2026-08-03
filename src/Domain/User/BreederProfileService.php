<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\User;

use Reptilienmarkt\Domain\Audit\AuditEntry;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Support\Slugger;

/**
 * Anlegen und Pflegen des oeffentlichen Zuechterprofils.
 *
 * Der Slug entsteht aus dem Anzeigenamen und bleibt danach stabil: Wer sein
 * Profil verlinkt hat, soll den Link nicht durch eine Umbenennung verlieren.
 * Aendern laesst er sich trotzdem — dann aber bewusst.
 */
final readonly class BreederProfileService
{
    public const int MAX_HEADLINE = 120;

    public const int MAX_DESCRIPTION = 4000;

    public function __construct(
        private BreederProfileRepository $profiles,
        private AuditLog $audit,
    ) {}

    public function forUser(User $user): ?BreederProfile
    {
        return $this->profiles->findByUser($user->id ?? 0);
    }

    /**
     * @param list<int> $focusSpeciesIds
     *
     * @throws AccountException
     */
    public function save(
        User $user,
        ?string $slug,
        ?string $headline,
        ?string $description,
        array $focusSpeciesIds,
        ?int $breedingSince,
        ?string $website,
        bool $isPublic,
    ): BreederProfile {
        $userId = $user->id ?? 0;
        $existing = $this->profiles->findByUser($userId);

        $slug = $this->resolveSlug($slug, $user, $existing);

        $headline = self::trimToNull($headline, self::MAX_HEADLINE);
        $description = self::trimToNull($description, self::MAX_DESCRIPTION);
        $website = $this->normalizeWebsite($website);

        if ($breedingSince !== null && ($breedingSince < 1950 || $breedingSince > (int) date('Y'))) {
            throw new AccountException('Das Jahr des Zuchtbeginns ergibt keinen Sinn.');
        }

        $focus = array_values(array_unique(array_filter(
            $focusSpeciesIds,
            static fn(int $id): bool => $id > 0,
        )));

        if (\count($focus) > BreederProfile::MAX_FOCUS_SPECIES) {
            $focus = \array_slice($focus, 0, BreederProfile::MAX_FOCUS_SPECIES);
        }

        $profile = new BreederProfile(
            $userId,
            $slug,
            $headline,
            $description,
            $focus,
            $breedingSince,
            $website,
            $isPublic,
            $existing?->createdAt,
        );

        $this->profiles->save($profile);

        $this->audit->record(new AuditEntry(
            $existing === null ? 'profile.created' : 'profile.updated',
            'user',
            $userId,
            ['slug' => $slug, 'oeffentlich' => $isPublic],
            $userId,
        ));

        return $profile;
    }

    /**
     * @return array{aktive_anzeigen: int, bewertungen: int, schnitt: ?float}
     */
    public function statistics(int $userId): array
    {
        return $this->profiles->statistics($userId);
    }

    /**
     * @throws AccountException
     */
    private function resolveSlug(?string $wanted, User $user, ?BreederProfile $existing): string
    {
        $base = $wanted !== null && trim($wanted) !== ''
            ? Slugger::slug($wanted)
            : ($existing === null ? Slugger::slug($user->displayName) : $existing->slug);

        if ($base === '') {
            $base = 'zuechter-' . ($user->id ?? 0);
        }

        if ($existing !== null && $existing->slug === $base) {
            return $base;
        }

        // Bei Kollision anhaengen statt abweisen: Der Nutzer hat nichts falsch
        // gemacht, wenn jemand anders denselben Namen fuehrt.
        $candidate = $base;
        $suffix = 2;

        while ($this->profiles->slugTaken($candidate, $user->id)) {
            $candidate = $base . '-' . $suffix;
            ++$suffix;

            if ($suffix > 50) {
                throw new AccountException('Diese Profiladresse ist nicht verfügbar. Bitte wähle eine andere.');
            }
        }

        return $candidate;
    }

    private function normalizeWebsite(?string $website): ?string
    {
        $value = self::trimToNull($website, 255);

        if ($value === null) {
            return null;
        }

        if (!str_starts_with($value, 'http://') && !str_starts_with($value, 'https://')) {
            $value = 'https://' . $value;
        }

        return filter_var($value, \FILTER_VALIDATE_URL) === false ? null : $value;
    }

    private static function trimToNull(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $maxLength);
    }
}
