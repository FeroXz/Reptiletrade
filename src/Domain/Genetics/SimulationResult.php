<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

use DateTimeImmutable;
use Exception;
use Reptilienmarkt\Domain\Listing\Sex;

/**
 * Das Ergebnis einer Verpaarungssimulation.
 *
 * Es ist bewusst vollstaendig serialisierbar und laesst sich aus dem
 * gespeicherten JSON wieder aufbauen, ohne Merkmalskatalog und ohne Datenbank.
 * Ein Bericht, den man nur mit dem heutigen Katalog lesen kann, waere in einem
 * Jahr wertlos — und genau dann will ihn jemand vorlegen.
 *
 * Alle Anteile beziehen sich auf die lebensfaehigen Nachkommen; was nicht
 * lebensfaehig ist, steht getrennt in lethalShare(). Andernfalls muesste jede
 * Anzeige der Verteilung mit einer Fussnote versehen werden.
 */
final readonly class SimulationResult
{
    public const int VERSION = 1;

    /**
     * @param array<string, Punnett> $punnetts        Genort (bei geschlechtsgebundenen mit Geschlecht) => Feld
     * @param list<GeneticWarning>   $warnings
     * @param array<string, float>   $phenotypes      Morph-String => Anteil
     * @param array<string, array<string, float>> $phenotypesBySex Geschlecht => Verteilung
     * @param array<string, float>   $genotypes       Genotyp-Kurzform => Anteil
     */
    public function __construct(
        private ParentSummary $parentA,
        private ParentSummary $parentB,
        private string $speciesName,
        private array $punnetts,
        private array $warnings,
        private array $phenotypes,
        private array $phenotypesBySex,
        private array $genotypes,
        private float $lethalShare,
        private ClutchProfile $clutch,
        private DateTimeImmutable $createdAt,
    ) {}

    /**
     * @return array{ParentSummary, ParentSummary}
     */
    public function parentage(): array
    {
        return [$this->parentA, $this->parentB];
    }

    public function speciesName(): string
    {
        return $this->speciesName;
    }

    /**
     * Nach Haeufigkeit sortiert.
     *
     * @return array<string, float>
     */
    public function offspringPhenotypes(): array
    {
        return $this->phenotypes;
    }

    /**
     * Getrennt nach Geschlecht der Nachkommen. Bei rein autosomalen Merkmalen
     * stehen in beiden Spalten dieselben Zahlen — bei geschlechtsgebundenen ist
     * genau dieser Unterschied die Auskunft.
     *
     * @return array<string, array<string, float>>
     */
    public function offspringPhenotypesBySex(): array
    {
        return $this->phenotypesBySex;
    }

    /**
     * @return array<string, float>
     */
    public function offspringGenotypes(): array
    {
        return $this->genotypes;
    }

    /**
     * @return array<string, Punnett>
     */
    public function punnettFields(): array
    {
        return $this->punnetts;
    }

    /**
     * @return list<GeneticWarning>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function hasWarningOfType(string $type): bool
    {
        foreach ($this->warnings as $warning) {
            if ($warning->type === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * Anteil der Nachkommen, die rechnerisch nicht lebensfaehig sind.
     */
    public function lethalShare(): float
    {
        return $this->lethalShare;
    }

    public function viabilityRate(): float
    {
        return 1.0 - $this->lethalShare;
    }

    public function clutch(): ClutchProfile
    {
        return $this->clutch;
    }

    public function expectedClutchSize(): int
    {
        return $this->clutch->size;
    }

    /**
     * Erwartete lebensfaehige Schluepflinge: Gelegegroesse mal Schlupfquote mal
     * dem Anteil, der die Verpaarung genetisch uebersteht.
     */
    public function expectedOffspringCount(): int
    {
        return (int) round($this->clutch->expectedHatchlings() * $this->viabilityRate());
    }

    /**
     * Wie viele Tiere eines Phaenotyps sind aus einem Gelege zu erwarten?
     */
    public function expectedCountFor(string $phenotype): float
    {
        return ($this->phenotypes[$phenotype] ?? 0.0) * $this->expectedOffspringCount();
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $punnetts = [];
        foreach ($this->punnetts as $key => $punnett) {
            $punnetts[$key] = $punnett->toArray();
        }

        return [
            'version' => self::VERSION,
            'art' => $this->speciesName,
            'eltern' => [$this->parentA->toArray(), $this->parentB->toArray()],
            'phaenotypen' => $this->phenotypes,
            'phaenotypen_nach_geschlecht' => $this->phenotypesBySex,
            'genotypen' => $this->genotypes,
            'punnett' => $punnetts,
            'warnungen' => array_map(static fn(GeneticWarning $w): array => $w->toArray(), $this->warnings),
            'letal_anteil' => $this->lethalShare,
            'gelege' => ['groesse' => $this->clutch->size, 'schlupfquote' => $this->clutch->hatchRate],
            'erstellt_am' => $this->createdAt->format(DATE_ATOM),
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $parents = $data['eltern'] ?? [];
        $first = \is_array($parents) && \is_array($parents[0] ?? null) ? $parents[0] : [];
        $second = \is_array($parents) && \is_array($parents[1] ?? null) ? $parents[1] : [];

        $punnetts = [];
        $storedFields = $data['punnett'] ?? [];
        if (\is_array($storedFields)) {
            foreach ($storedFields as $key => $field) {
                if (\is_string($key) && \is_array($field)) {
                    $punnetts[$key] = Punnett::fromArray($field);
                }
            }
        }

        $warnings = [];
        $storedWarnings = $data['warnungen'] ?? [];
        if (\is_array($storedWarnings)) {
            foreach ($storedWarnings as $warning) {
                if (\is_array($warning)) {
                    $warnings[] = GeneticWarning::fromArray($warning);
                }
            }
        }

        $bySex = [];
        $storedBySex = $data['phaenotypen_nach_geschlecht'] ?? [];
        if (\is_array($storedBySex)) {
            foreach ($storedBySex as $sex => $distribution) {
                if (\is_string($sex) && \is_array($distribution)) {
                    $bySex[$sex] = self::floatMap($distribution);
                }
            }
        }

        $clutch = $data['gelege'] ?? [];
        $size = \is_array($clutch) ? ($clutch['groesse'] ?? 10) : 10;
        $rate = \is_array($clutch) ? ($clutch['schlupfquote'] ?? 0.8) : 0.8;
        $created = $data['erstellt_am'] ?? null;
        $lethal = $data['letal_anteil'] ?? 0.0;

        return new self(
            ParentSummary::fromArray($first),
            ParentSummary::fromArray($second),
            \is_string($data['art'] ?? null) ? $data['art'] : '',
            $punnetts,
            $warnings,
            self::floatMap(\is_array($data['phaenotypen'] ?? null) ? $data['phaenotypen'] : []),
            $bySex,
            self::floatMap(\is_array($data['genotypen'] ?? null) ? $data['genotypen'] : []),
            is_numeric($lethal) ? (float) $lethal : 0.0,
            new ClutchProfile(
                is_numeric($size) ? (int) $size : 10,
                is_numeric($rate) ? (float) $rate : 0.8,
            ),
            self::timestamp($created),
        );
    }

    /**
     * Ein unlesbarer Zeitstempel darf keinen gespeicherten Bericht unbrauchbar
     * machen — die Zahlen darin stimmen ja weiterhin.
     */
    private static function timestamp(mixed $value): DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return new DateTimeImmutable('@0');
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return new DateTimeImmutable('@0');
        }
    }

    /**
     * Bezeichnung eines Geschlechts fuer die Ausgabe der Aufstellung.
     */
    public static function offspringLabel(Sex $sex): string
    {
        return match ($sex) {
            Sex::Maennlich => 'Söhne',
            Sex::Weiblich => 'Töchter',
            Sex::Unbekannt => 'Nachzucht',
        };
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, float>
     */
    private static function floatMap(array $values): array
    {
        $map = [];

        foreach ($values as $key => $value) {
            if (\is_string($key) && is_numeric($value)) {
                $map[$key] = (float) $value;
            }
        }

        return $map;
    }
}
