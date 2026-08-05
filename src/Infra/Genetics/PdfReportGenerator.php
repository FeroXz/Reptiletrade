<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Genetics;

use Reptilienmarkt\Domain\Genetics\SimulationResult;
use Reptilienmarkt\Domain\Listing\Sex;
use Twig\Environment;

/**
 * Der Bericht zu einer Verpaarung — als Seite im Browser und als PDF zum
 * Ablegen.
 *
 * Beide Fassungen entstehen aus demselben Ergebnisobjekt und nicht die eine aus
 * der anderen. Der uebliche Weg waere, das HTML durch einen Wandler zu
 * schicken; das hiesse, einen Browser auf dem Server vorauszusetzen, damit ein
 * Bericht mit zwei Tabellen ein PDF wird. Der Preis dieser Entscheidung ist,
 * dass beide Fassungen gepflegt werden muessen — dafuer haengt der Download an
 * keiner Systemabhaengigkeit und laeuft in jeder Umgebung gleich.
 */
final readonly class PdfReportGenerator
{
    public function __construct(private Environment $twig) {}

    /**
     * Die Bildschirm- und Druckfassung.
     */
    public function generateHtml(SimulationResult $result): string
    {
        [$first, $second] = $result->parentage();

        return $this->twig->render('genetik/bericht.html.twig', [
            'ergebnis' => $result,
            'eltern_a' => $first,
            'eltern_b' => $second,
            'phaenotypen' => $result->offspringPhenotypes(),
            'nach_geschlecht' => $result->offspringPhenotypesBySex(),
            'genotypen' => $result->offspringGenotypes(),
            'punnett' => $result->punnettFields(),
            'warnungen' => $result->warnings(),
            'erstellt_am' => $result->createdAt(),
        ]);
    }

    /**
     * Dasselbe als PDF.
     */
    public function generatePdf(SimulationResult $result): string
    {
        [$first, $second] = $result->parentage();

        $document = new PdfDocument('Genetik-Bericht — ' . $result->speciesName());

        $document->heading('Genetik-Bericht', 20.0);
        $document->paragraph($result->speciesName(), 11.0, true);
        $document->paragraph('Erstellt am ' . $result->createdAt()->format('d.m.Y H:i') . ' Uhr');
        $document->rule();

        $document->heading('Elterntiere', 13.0);
        foreach ([$first, $second] as $parent) {
            $document->keyValue($parent->sex->label(), $parent->morphString);
            if ($parent->genotypeString !== '') {
                $document->keyValue('Genotyp', $parent->genotypeString, 9.0);
            }
        }

        $document->spacer();
        $document->heading('Erwartete Nachzucht', 13.0);
        $document->paragraph(\sprintf(
            'Aus einem Gelege von etwa %d Eiern sind rund %d lebensfähige Schlüpflinge zu erwarten. '
            . 'Die Anteile beziehen sich auf diese Tiere.',
            $result->expectedClutchSize(),
            $result->expectedOffspringCount(),
        ), 9.0);
        $document->spacer(4.0);

        foreach ($result->offspringPhenotypes() as $phenotype => $share) {
            $document->distributionRow($phenotype, $share);
        }

        if ($result->lethalShare() > 0.0) {
            $document->spacer(4.0);
            $document->paragraph(\sprintf(
                'Nicht lebensfähig: %s der Nachkommen aus dieser Verpaarung.',
                number_format($result->lethalShare() * 100, 1, ',', '.') . ' %',
            ), 10.0, true);
        }

        $this->appendSexTables($document, $result);
        $this->appendPunnetts($document, $result);
        $this->appendWarnings($document, $result);

        $document->spacer();
        $document->rule();
        $document->paragraph(
            'Die Angaben sind eine Rechnung nach den Mendelschen Regeln auf Grundlage der angegebenen Merkmale. '
            . 'Sie sagen voraus, was zu erwarten ist — nicht, was in einem einzelnen Gelege eintritt. '
            . 'Unbekannte Anlagen der Elterntiere sind darin nicht enthalten.',
            8.0,
        );

        return $document->render();
    }

    private function appendSexTables(PdfDocument $document, SimulationResult $result): void
    {
        $bySex = $result->offspringPhenotypesBySex();
        $male = $bySex[Sex::Maennlich->value] ?? [];
        $female = $bySex[Sex::Weiblich->value] ?? [];

        // Nur abbilden, wenn sich Soehne und Toechter tatsaechlich
        // unterscheiden — sonst stuende dieselbe Tabelle dreimal im Bericht.
        if ($male === $female) {
            return;
        }

        $document->spacer();
        $document->heading('Nach Geschlecht', 13.0);

        foreach ([Sex::Maennlich, Sex::Weiblich] as $sex) {
            $distribution = $bySex[$sex->value] ?? [];

            if ($distribution === []) {
                continue;
            }

            $document->paragraph(SimulationResult::offspringLabel($sex), 10.0, true);
            foreach ($distribution as $phenotype => $share) {
                $document->distributionRow($phenotype, $share, 9.0);
            }
            $document->spacer(4.0);
        }
    }

    private function appendPunnetts(PdfDocument $document, SimulationResult $result): void
    {
        $fields = $result->punnettFields();

        if ($fields === []) {
            return;
        }

        $document->spacer();
        $document->heading('Punnett-Quadrate', 13.0);
        $document->paragraph(
            'Ein Feld je Genort: oben die Allele des ersten Elterntiers, links die des zweiten.',
            9.0,
        );
        $document->spacer(4.0);

        $hasLethal = false;

        foreach ($fields as $punnett) {
            $document->paragraph($punnett->label, 10.0, true);
            $document->punnett($punnett->rows(), $punnett->columns(), $punnett->grid(), $punnett->lethalGenotypes());

            $hasLethal = $hasLethal || $punnett->lethalGenotypes() !== [];
        }

        if ($hasLethal) {
            $document->paragraph('† nicht lebensfähig', 8.0);
        }
    }

    private function appendWarnings(PdfDocument $document, SimulationResult $result): void
    {
        if ($result->warnings() === []) {
            return;
        }

        $document->spacer();
        $document->heading('Hinweise', 13.0);

        foreach ($result->warnings() as $warning) {
            $document->bullet(\sprintf('%s: %s', $warning->severity->label(), $warning->message), 9.0);
            $document->spacer(2.0);
        }
    }
}
