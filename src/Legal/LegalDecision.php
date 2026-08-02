<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal;

/**
 * Ergebnis der Rechts-Engine fuer genau eine Anzeige.
 *
 * $blocked        Veroeffentlichung nicht moeglich
 * $requiresReview Anzeige muss in den Status "pruefung" und braucht Admin-Freigabe
 * $requiredFields Feldnamen, die das Formular verlangen muss
 * $notices        Hinweise fuer den Nutzer
 * $reasons        Begruendungen fuer den Audit-Trail
 */
final readonly class LegalDecision
{
    /**
     * @param list<string>      $requiredFields
     * @param list<LegalNotice> $notices
     * @param list<LegalReason> $reasons
     */
    public function __construct(
        public bool $blocked,
        public bool $requiresReview,
        public array $requiredFields,
        public array $notices,
        public array $reasons,
    ) {}

    public static function allowed(): self
    {
        return new self(false, false, [], [], []);
    }

    /**
     * Gruende, die zur Blockade gefuehrt haben — das ist es, was dem Nutzer
     * erklaert werden muss.
     *
     * @return list<LegalReason>
     */
    public function blockingReasons(): array
    {
        return array_values(array_filter(
            $this->reasons,
            static fn(LegalReason $reason): bool => $reason->effect === LegalEffect::Blockiert,
        ));
    }

    /**
     * @return list<string>
     */
    public function triggeredRules(): array
    {
        $rules = [];
        foreach ($this->reasons as $reason) {
            $rules[$reason->rule] = true;
        }

        return array_keys($rules);
    }

    public function hasMissingLegalText(): bool
    {
        foreach ($this->notices as $notice) {
            if ($notice->textMissing) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nutzlast fuer audit_log.data_json.
     *
     * @return array{blocked: bool, requires_review: bool, required_fields: list<string>, reasons: list<array{rule: string, code: string, effect: string, data: array<string, scalar|null>}>}
     */
    public function auditPayload(): array
    {
        return [
            'blocked' => $this->blocked,
            'requires_review' => $this->requiresReview,
            'required_fields' => $this->requiredFields,
            'reasons' => array_map(static fn(LegalReason $reason): array => $reason->toArray(), $this->reasons),
        ];
    }
}
