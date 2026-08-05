<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

/**
 * Welche Regel gilt fuer welchen Genort?
 *
 * Die Zuordnung folgt allein dem Erbgang aus dem Merkmalskatalog. Genorte mit
 * nicht lebensfaehiger homozygoter Form bekommen zusaetzlich die Bewertung aus
 * LethalComboRule umgelegt — sie ersetzt den Erbgang nicht, sie ergaenzt ihn.
 */
final readonly class RuleRegistry
{
    /**
     * @param list<InheritanceRule> $rules
     */
    public function __construct(private array $rules) {}

    public static function default(): self
    {
        return new self([
            new DominantRule(),
            new RecessiveRule(),
            new CodominantRule(),
            new SexLinkedRule(),
            new PolygenicRule(),
            new NonHeritableRule(),
        ]);
    }

    public function ruleFor(Locus $locus): InheritanceRule
    {
        foreach ($this->rules as $rule) {
            if (!$rule->supports($locus->inheritance)) {
                continue;
            }

            return LethalComboRule::applies($locus) ? new LethalComboRule($rule) : $rule;
        }

        throw new GeneticsException(\sprintf(
            'Für den Erbgang "%s" ist keine Vererbungsregel hinterlegt.',
            $locus->inheritance->value,
        ));
    }
}
