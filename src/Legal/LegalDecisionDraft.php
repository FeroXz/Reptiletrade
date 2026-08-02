<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal;

/**
 * Sammelbecken, das die Regeln waehrend der Auswertung fuellen. Erst am Ende
 * entsteht daraus die unveraenderliche LegalDecision.
 */
final class LegalDecisionDraft
{
    private bool $blocked = false;

    private bool $requiresReview = false;

    /** @var list<string> */
    private array $requiredFields = [];

    /** @var list<LegalNotice> */
    private array $notices = [];

    /** @var list<LegalReason> */
    private array $reasons = [];

    public function __construct(private readonly LegalTextResolver $texts) {}

    /**
     * @param array<string, scalar|null> $data
     */
    public function block(string $rule, string $code, array $data = []): void
    {
        $this->blocked = true;
        $this->reasons[] = new LegalReason($rule, $code, LegalEffect::Blockiert, $data);
    }

    /**
     * @param array<string, scalar|null> $data
     */
    public function requireReview(string $rule, string $code, array $data = []): void
    {
        $this->requiresReview = true;
        $this->reasons[] = new LegalReason($rule, $code, LegalEffect::Pruefung, $data);
    }

    /**
     * Meldet ein Pflichtfeld an. Ob es fehlt, entscheidet die Regel selbst —
     * requiredFields beschreibt, was das Formular ueberhaupt abfragen muss.
     *
     * @param array<string, scalar|null> $data
     */
    public function requireField(string $field, string $rule, string $code = 'pflichtfeld', array $data = []): void
    {
        if (!\in_array($field, $this->requiredFields, true)) {
            $this->requiredFields[] = $field;
        }

        $this->reasons[] = new LegalReason($rule, $code, LegalEffect::Pflichtfeld, $data + ['field' => $field]);
    }

    /**
     * @param list<string> $fields
     */
    public function requireFields(array $fields, string $rule): void
    {
        foreach ($fields as $field) {
            $this->requireField($field, $rule);
        }
    }

    public function notice(
        string $textKey,
        string $jurisdiction = 'DE',
        NoticeSeverity $severity = NoticeSeverity::Info,
    ): void {
        $notice = $this->texts->resolve($textKey, $jurisdiction, $severity);

        foreach ($this->notices as $existing) {
            if ($existing->key === $notice->key) {
                return;
            }
        }

        $this->notices[] = $notice;
    }

    /**
     * @param array<string, scalar|null> $data
     */
    public function note(string $rule, string $code, array $data = []): void
    {
        $this->reasons[] = new LegalReason($rule, $code, LegalEffect::Hinweis, $data);
    }

    public function build(): LegalDecision
    {
        return new LegalDecision(
            $this->blocked,
            $this->requiresReview,
            $this->requiredFields,
            $this->notices,
            $this->reasons,
        );
    }
}
