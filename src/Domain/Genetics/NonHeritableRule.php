<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Species\Inheritance;

/**
 * Merkmale ohne Erbgang — bei Reptilien vor allem Paradox-Zeichnungen.
 *
 * Ein Paradox entsteht waehrend der Entwicklung, nicht aus einer Anlage. Es
 * laesst sich nicht vererben und nicht vorhersagen. Der Simulator gibt hier
 * deshalb bewusst nichts aus ausser dem Hinweis darauf: Eine Prozentzahl
 * waere frei erfunden, und im Verkauf wuerde sie zu einem Versprechen.
 */
final readonly class NonHeritableRule extends AutosomalRule
{
    public function supports(Inheritance $inheritance): bool
    {
        return $inheritance === Inheritance::Paradox;
    }

    public function express(Locus $locus, array $alleles, Sex $sex): array
    {
        return [];
    }

    public function warnings(Locus $locus, CrossContext $context): array
    {
        if ($context->first->isWildtypeAt($locus->id) && $context->second->isWildtypeAt($locus->id)) {
            return [];
        }

        return [
            new GeneticWarning(
                GeneticWarning::TYPE_NOT_HERITABLE,
                \sprintf(
                    '"%s" ist keine Erbanlage, sondern entsteht während der Entwicklung. Die Nachzucht lässt sich '
                    . 'dafür nicht berechnen — das Merkmal bleibt in der Verteilung unberücksichtigt.',
                    $locus->label(),
                ),
                WarningSeverity::Warnung,
            ),
        ];
    }
}
