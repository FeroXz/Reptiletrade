<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Genetics;

/**
 * Ein sehr kleiner PDF-Schreiber: Ueberschriften, Absaetze, Aufzaehlungen,
 * Tabellen und Punnett-Gitter — mehr braucht ein Genetik-Bericht nicht.
 *
 * Warum selbst geschrieben und kein Fremdpaket? Aus demselben Grund wie beim
 * Router und beim .env-Leser (siehe docs/ARCHITEKTUR.md): Der gaengige Weg
 * waere ein HTML-nach-PDF-Wandler, also ein vollstaendiger Browser als
 * Abhaengigkeit — fuer eine Seite mit zwei Tabellen. Der Bericht besteht aus
 * Text in fester Reihenfolge; das laesst sich mit den Standardschriften des
 * PDF-Formats unmittelbar setzen, ohne Layout-Engine und ohne
 * Systemabhaengigkeit auf dem Server.
 *
 * Umlaute: Die Base-14-Schriften werden mit WinAnsiEncoding eingebunden, der
 * Text wird nach Windows-1252 gewandelt. Damit stehen "ä", "ö", "ü", "ß" und
 * der Gedankenstrich richtig im Dokument.
 */
final class PdfDocument
{
    private const string FONT_REGULAR = 'F1';

    private const string FONT_BOLD = 'F2';

    /** @var list<string> Fertige Inhaltsstroeme, je Seite einer */
    private array $pages = [];

    private string $current = '';

    private float $cursor;

    public function __construct(
        private readonly string $title = 'Bericht',
        private readonly float $width = 595.28,
        private readonly float $height = 841.89,
        private readonly float $margin = 56.0,
    ) {
        $this->cursor = $this->height - $this->margin;
    }

    public function heading(string $text, float $size = 16.0): void
    {
        $this->ensureSpace($size * 2.2);
        $this->cursor -= $size * 0.6;
        $this->write($text, $size, true);
        $this->cursor -= $size * 0.5;
    }

    public function paragraph(string $text, float $size = 10.0, bool $bold = false): void
    {
        foreach ($this->wrap($text, $size, $this->contentWidth(), $bold) as $line) {
            $this->ensureSpace($size * 1.4);
            $this->write($line, $size, $bold);
        }
    }

    public function bullet(string $text, float $size = 10.0): void
    {
        $indent = 12.0;
        $lines = $this->wrap($text, $size, $this->contentWidth() - $indent, false);

        foreach ($lines as $index => $line) {
            $this->ensureSpace($size * 1.4);
            $this->write($index === 0 ? '•  ' . $line : '   ' . $line, $size, false, $index === 0 ? 0.0 : $indent);
        }
    }

    /**
     * Eine zweispaltige Zeile: links die Bezeichnung, rechts der Wert.
     */
    public function keyValue(string $label, string $value, float $size = 10.0): void
    {
        $this->ensureSpace($size * 1.4);
        $y = $this->cursor;
        $this->text($label, $this->margin, $y, $size, true);
        $this->text($value, $this->margin + 150.0, $y, $size, false);
        $this->cursor -= $size * 1.4;
    }

    /**
     * Eine Zeile aus Anteil und Bezeichnung, mit Balken. Der Balken macht aus
     * einer Zahlenkolonne auf einen Blick eine Verteilung.
     */
    public function distributionRow(string $label, float $share, float $size = 10.0): void
    {
        $this->ensureSpace($size * 1.6);
        $y = $this->cursor;

        $this->text($label, $this->margin, $y, $size, false);

        $barLeft = $this->margin + 250.0;
        $barWidth = 160.0;
        $this->rectangle($barLeft, $y - 1.0, $barWidth, $size * 0.8, fill: false);
        if ($share > 0.0) {
            $this->rectangle($barLeft, $y - 1.0, $barWidth * min($share, 1.0), $size * 0.8, fill: true);
        }

        $this->text($this->percent($share), $barLeft + $barWidth + 10.0, $y, $size, true);
        $this->cursor -= $size * 1.8;
    }

    /**
     * Ein Punnett-Gitter: Kopfzeile mit den Gameten des einen Elterntiers,
     * Kopfspalte mit denen des anderen.
     *
     * @param list<string>       $rows
     * @param list<string>       $columns
     * @param list<list<string>> $cells
     * @param list<string>       $lethal Felder, die nicht lebensfaehig sind
     */
    public function punnett(array $rows, array $columns, array $cells, array $lethal = []): void
    {
        $size = 8.0;
        $cellWidth = min(110.0, ($this->contentWidth() - 60.0) / max(\count($columns), 1));
        $cellHeight = 22.0;
        $left = $this->margin + 60.0;

        $this->ensureSpace($cellHeight * (\count($rows) + 1) + 10.0);

        $top = $this->cursor;

        foreach ($columns as $index => $column) {
            $this->text($column, $left + $index * $cellWidth + 4.0, $top - 12.0, $size, true);
        }

        $top -= $cellHeight;

        foreach ($rows as $rowIndex => $row) {
            $y = $top - $rowIndex * $cellHeight;
            $this->text($row, $this->margin, $y - 14.0, $size, true);

            foreach ($columns as $columnIndex => $column) {
                $value = $cells[$rowIndex][$columnIndex] ?? '';
                $x = $left + $columnIndex * $cellWidth;

                $this->rectangle($x, $y - $cellHeight, $cellWidth, $cellHeight, fill: false);
                // Das Kreuz steht fuer "nicht lebensfaehig"; die Legende dazu
                // setzt der Berichtsgenerator unter das Gitter.
                $this->text(
                    \in_array($value, $lethal, true) ? $value . ' †' : $value,
                    $x + 4.0,
                    $y - 14.0,
                    $size,
                    false,
                );
            }
        }

        $this->cursor = $top - \count($rows) * $cellHeight - 12.0;
    }

    public function spacer(float $height = 8.0): void
    {
        $this->cursor -= $height;
    }

    public function rule(): void
    {
        $this->ensureSpace(10.0);
        $this->current .= \sprintf(
            "0.75 w 0.7 0.7 0.7 RG %.2f %.2f m %.2f %.2f l S\n",
            $this->margin,
            $this->cursor,
            $this->width - $this->margin,
            $this->cursor,
        );
        $this->cursor -= 10.0;
    }

    /**
     * Das fertige Dokument als Bytefolge.
     */
    public function render(): string
    {
        $this->closePage();

        $objects = [];

        // 1 Katalog, 2 Seitenbaum, 3/4 Schriften, danach je Seite ein
        // Seitenobjekt und ein Inhaltsstrom.
        $pageCount = \count($this->pages);
        $firstPageObject = 5;
        $kids = [];

        for ($index = 0; $index < $pageCount; ++$index) {
            $kids[] = ($firstPageObject + $index * 2) . ' 0 R';
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $pageCount . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        foreach ($this->pages as $index => $content) {
            $pageObject = $firstPageObject + $index * 2;
            $contentObject = $pageObject + 1;

            $objects[$pageObject] = \sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /%s 3 0 R /%s 4 0 R >> >> /Contents %d 0 R >>',
                $this->width,
                $this->height,
                self::FONT_REGULAR,
                self::FONT_BOLD,
                $contentObject,
            );

            $objects[$contentObject] = '<< /Length ' . \strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        }

        $infoObject = $firstPageObject + $pageCount * 2;
        $objects[$infoObject] = \sprintf(
            '<< /Title (%s) /Producer (Reptilienmarkt) /CreationDate (D:%s) >>',
            $this->escape($this->title),
            gmdate('YmdHis') . 'Z',
        );

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        ksort($objects);
        foreach ($objects as $number => $body) {
            $offsets[$number] = \strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefOffset = \strlen($pdf);
        $count = \count($objects) + 1;

        $pdf .= "xref\n0 " . $count . "\n0000000000 65535 f \n";
        for ($number = 1; $number < $count; ++$number) {
            $pdf .= \sprintf("%010d 00000 n \n", $offsets[$number] ?? 0);
        }

        $pdf .= \sprintf(
            "trailer\n<< /Size %d /Root 1 0 R /Info %d 0 R >>\nstartxref\n%d\n%%%%EOF\n",
            $count,
            $infoObject,
            $xrefOffset,
        );

        return $pdf;
    }

    private function contentWidth(): float
    {
        return $this->width - 2 * $this->margin;
    }

    private function write(string $text, float $size, bool $bold, float $indent = 0.0): void
    {
        $this->text($text, $this->margin + $indent, $this->cursor, $size, $bold);
        $this->cursor -= $size * 1.4;
    }

    private function text(string $text, float $x, float $y, float $size, bool $bold): void
    {
        $this->current .= \sprintf(
            "BT /%s %.1f Tf 0 0 0 rg %.2f %.2f Td (%s) Tj ET\n",
            $bold ? self::FONT_BOLD : self::FONT_REGULAR,
            $size,
            $x,
            $y,
            $this->escape($text),
        );
    }

    private function rectangle(float $x, float $y, float $width, float $height, bool $fill): void
    {
        $this->current .= $fill
            ? \sprintf("0.25 0.45 0.35 rg %.2f %.2f %.2f %.2f re f\n", $x, $y, $width, $height)
            : \sprintf("0.5 w 0.6 0.6 0.6 RG %.2f %.2f %.2f %.2f re S\n", $x, $y, $width, $height);
    }

    private function ensureSpace(float $needed): void
    {
        if ($this->cursor - $needed < $this->margin) {
            $this->closePage();
        }
    }

    private function closePage(): void
    {
        if ($this->current !== '') {
            $this->pages[] = $this->current;
        }

        $this->current = '';
        $this->cursor = $this->height - $this->margin;
    }

    /**
     * Zeilenumbruch nach geschaetzter Breite. Die Base-14-Schriften haben feste
     * Zeichenbreiten, die exakte Tabelle waere aber 200 Zeilen — fuer einen
     * Fliesstext in einer Spalte genuegt der Mittelwert.
     *
     * @return list<string>
     */
    private function wrap(string $text, float $size, float $width, bool $bold): array
    {
        $perCharacter = $size * ($bold ? 0.55 : 0.5);
        $maxCharacters = max(20, (int) floor($width / $perCharacter));

        $lines = [];
        foreach (explode("\n", $text) as $paragraph) {
            $wrapped = wordwrap($paragraph, $maxCharacters, "\n", true);
            foreach (explode("\n", $wrapped) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Text fuer den Inhaltsstrom: nach Windows-1252 wandeln und die drei
     * Zeichen schuetzen, die in einer PDF-Zeichenkette eine Bedeutung haben.
     */
    private function escape(string $text): string
    {
        $converted = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $converted);
    }

    private function percent(float $share): string
    {
        return number_format($share * 100, 1, ',', '.') . ' %';
    }
}
