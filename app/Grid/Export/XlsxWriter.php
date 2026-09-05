<?php

namespace App\Grid\Export;

use App\Grid\GridColumn;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The extract as a real .xlsx, with no dependency.
 *
 * WHY THERE IS NO LIBRARY HERE. An .xlsx is a zip of a handful of XML parts,
 * and PHP ships ZipArchive. What we need of the format is the part everyone
 * needs: one sheet, a header row, typed cells and four number formats. That is
 * about two hundred lines. PhpSpreadsheet is 8 MB of vendor and a memory model
 * built around an in-memory cell collection — it is the right answer when a
 * feature needs formulas, several sheets, charts, or to READ a workbook, and
 * the day one of those is asked for is the day to add it and delete this. It
 * is the wrong answer for "the same columns as the screen, as numbers".
 *
 * WHAT MAKES IT AN EXTRACT RATHER THAN A PICTURE OF ONE. Numbers are written
 * as numbers with a number format, and dates as date serials — so a money
 * column sums, a date column sorts as a date, and neither needs cleaning
 * before it can be used. That is the entire reason somebody asks for xlsx
 * rather than looking at the screen, and a writer that emits everything as
 * text has produced a CSV with a longer file extension.
 *
 * MEMORY. The sheet's XML is written row by row to a temporary file and then
 * added to the archive from disk, so a hundred thousand rows never exist in
 * PHP at once. The ceiling that matters is the source's, not this writer's.
 */
final class XlsxWriter
{
    /** Style indexes, in the order xl/styles.xml declares them. */
    private const STYLE_TEXT = 0;

    private const STYLE_HEADER = 1;

    private const STYLE_2DP = 2;

    private const STYLE_INT = 3;

    private const STYLE_DATE = 4;

    private const STYLE_DATETIME = 5;

    private const STYLE_3DP = 6;

    private const STYLE_4DP = 7;

    /**
     * Excel's day zero is 1899-12-30 — the 1900 leap-year bug, preserved for
     * compatibility. Held as a UTC timestamp once it has been worked out; see
     * epoch().
     */
    private ?int $epoch = null;

    public function download(GridExtract $extract): BinaryFileResponse
    {
        $path = $this->build($extract);

        return response()
            ->download($path, $extract->filename('xlsx'), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'no-store',
            ])
            ->deleteFileAfterSend();
    }

    /** Build the workbook and return the path to it. */
    public function build(GridExtract $extract): string
    {
        $sheet = $this->writeSheet($extract);
        $path = (string) tempnam(sys_get_temp_dir(), 'agora-xlsx-');

        $zip = new \ZipArchive;

        if ($zip->open($path, \ZipArchive::OVERWRITE | \ZipArchive::CREATE) !== true) {
            @unlink($sheet);

            throw new \RuntimeException('The extract could not be assembled — the archive would not open.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook($extract));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFile($sheet, 'xl/worksheets/sheet1.xml');
        $zip->close();

        @unlink($sheet);

        return $path;
    }

    /**
     * The sheet, written straight to disk.
     *
     * Inline strings rather than a shared-strings table: a shared table is
     * smaller when values repeat, and it also means holding every distinct
     * string in memory until the last row is known. For an extract — mostly
     * distinct references and numbers — it would cost memory to save very
     * little file.
     */
    private function writeSheet(GridExtract $extract): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'agora-sheet-');
        $out = fopen($path, 'w');

        if ($out === false) {
            throw new \RuntimeException('The extract could not be assembled — no temporary file.');
        }

        $columns = $extract->columns;
        $last = $this->letter(count($columns));

        // What each column IS, decided once instead of once per cell. On a
        // hundred-thousand-row extract the inner loop runs a million times, and
        // asking a GridColumn whether it is numeric a million times is most of
        // what the writer would otherwise spend its life doing.
        $plan = [];

        foreach ($columns as $index => $column) {
            $plan[] = [
                'letter' => $this->letter($index + 1),
                'column' => $column,
                'kind' => match (true) {
                    $column->isNumeric() => 'number',
                    $column->format === 'date' => 'date',
                    $column->format === 'datetime' => 'datetime',
                    default => 'text',
                },
                'style' => match (true) {
                    $column->isNumeric() => $this->numericStyle($column),
                    $column->format === 'date' => self::STYLE_DATE,
                    $column->format === 'datetime' => self::STYLE_DATETIME,
                    default => self::STYLE_TEXT,
                },
            ];
        }

        fwrite($out, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            // Freeze the header — a hundred thousand rows with the headings
            // scrolled off the top is a file people scroll back up in.
            .'<sheetViews><sheetView workbookViewId="0" tabSelected="1">'
            .'<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            .'</sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .$this->columnWidths($columns)
            .'<sheetData>');

        fwrite($out, '<row r="1">');

        foreach ($columns as $index => $column) {
            fwrite($out, $this->cell($this->letter($index + 1).'1', $column->label, self::STYLE_HEADER));
        }

        fwrite($out, '</row>');

        $r = 1;

        foreach ($extract->rows as $row) {
            $r++;
            // One write per ROW, not per cell. Forty thousand small writes to a
            // stream is most of the time it takes to build a ten-thousand-row
            // workbook; assembling the row first and writing it once is the
            // same bytes for a fraction of the syscalls.
            $line = '<row r="'.$r.'">';

            foreach ($plan as $cell) {
                $line .= $this->valueCell($cell, $r, $extract, $row);
            }

            fwrite($out, $line.'</row>');
        }

        fwrite($out, '</sheetData>'
            // An auto-filter over the used range, so the first thing the reader
            // wants to do — narrow it further — is already available.
            .'<autoFilter ref="A1:'.$last.$r.'"/>'
            .'</worksheet>');

        fclose($out);

        return $path;
    }

    /** @param  array{letter: string, column: GridColumn, kind: string, style: int}  $cell */
    private function valueCell(array $cell, int $r, GridExtract $extract, object $row): string
    {
        $column = $cell['column'];
        $raw = $extract->raw($row, $column);

        if ($raw === null) {
            // No cell at all, rather than an empty string. A blank in a
            // spreadsheet is "we do not have this"; "" is a value, and it
            // breaks COUNT and AVERAGE in ways nobody expects.
            return '';
        }

        $reference = $cell['letter'].$r;

        if ($cell['kind'] === 'number' && is_numeric($raw)) {
            return '<c r="'.$reference.'" s="'.$cell['style'].'">'.$this->number((float) $raw).'</c>';
        }

        if ($cell['kind'] === 'date' || $cell['kind'] === 'datetime') {
            $serial = $this->serial((string) $raw);

            if ($serial !== null) {
                return '<c r="'.$reference.'" s="'.$cell['style'].'">'.$this->number($serial).'</c>';
            }
        }

        return $this->cell($reference, (string) $extract->value($row, $column), self::STYLE_TEXT);
    }

    private function numericStyle(GridColumn $column): int
    {
        return match ($column->format) {
            'number' => self::STYLE_INT,
            'litres' => self::STYLE_3DP,
            'cpl' => self::STYLE_4DP,
            default => self::STYLE_2DP,
        };
    }

    /**
     * Excel's date serial, or null when the value is not a date at all.
     *
     * Built from the date PARTS rather than from a timestamp difference. A
     * value out of SQL Server carries no zone, Carbon gives it the
     * application's, and converting that to a timestamp can move it across
     * midnight — a trading day that arrives in the workbook as the day before
     * is the kind of defect that gets found in a meeting.
     */
    private function serial(string $value): ?float
    {
        try {
            $date = Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }

        // gmmktime rather than a second Carbon: this runs once per date cell —
        // a hundred thousand times on a full extract — and constructing two
        // more Carbon objects to subtract them was measurably the slowest thing
        // the writer did.
        $days = intdiv(
            gmmktime(0, 0, 0, $date->month, $date->day, $date->year) - $this->epoch(),
            86400,
        );

        $seconds = $date->hour * 3600 + $date->minute * 60 + $date->second;

        return round($days + $seconds / 86400, 8);
    }

    /** The epoch as a UTC timestamp, worked out once. */
    private function epoch(): int
    {
        return $this->epoch ??= (int) gmmktime(0, 0, 0, 12, 30, 1899);
    }

    /** A number as XML: no scientific notation, no locale, no thousands separator. */
    private function number(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');

        return '<v>'.($formatted === '' || $formatted === '-' ? '0' : $formatted).'</v>';
    }

    private function cell(string $reference, string $text, int $style): string
    {
        return '<c r="'.$reference.'" t="inlineStr" s="'.$style.'"><is><t xml:space="preserve">'
            .$this->escape($text).'</t></is></c>';
    }

    /**
     * XML-safe text.
     *
     * The control-character strip matters: a stray 0x00–0x08 out of a legacy
     * `ntext` column is not valid in XML 1.0 at all, and Excel does not
     * complain about a bad character — it declares the whole workbook
     * unreadable and offers to repair it.
     */
    private function escape(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);

        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * 1 → A, 26 → Z, 27 → AA.
     *
     * Memoised: it is called once per cell, which is four hundred thousand
     * times on a full extract, to answer one of about twenty questions.
     *
     * @var array<int, string>
     */
    private array $letters = [];

    private function letter(int $index): string
    {
        if (isset($this->letters[$index])) {
            return $this->letters[$index];
        }

        $number = $index;
        $letters = '';

        while ($number > 0) {
            $number--;
            $letters = chr(65 + $number % 26).$letters;
            $number = intdiv($number, 26);
        }

        return $this->letters[$index] = ($letters === '' ? 'A' : $letters);
    }

    /** @param array<int, GridColumn> $columns */
    private function columnWidths(array $columns): string
    {
        $cols = '';

        foreach ($columns as $index => $column) {
            // A width in Excel's character units, from the heading and the
            // kind of value. Wide prose gets room; a number does not need any.
            $width = match (true) {
                $column->wide => 42,
                $column->isNumeric() => max(12, mb_strlen($column->label) + 2),
                $column->format === 'datetime' => 18,
                default => min(40, max(12, mb_strlen($column->label) + 4)),
            };

            $cols .= '<col min="'.($index + 1).'" max="'.($index + 1).'" width="'.$width.'" customWidth="1"/>';
        }

        return $cols === '' ? '' : '<cols>'.$cols.'</cols>';
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private function workbook(GridExtract $extract): string
    {
        // Excel refuses a sheet name over 31 characters or carrying any of
        // : \ / ? * [ ], and it refuses the whole file rather than the name.
        $name = (string) preg_replace('#[:\\\\/?*\[\]]#', ' ', $extract->definition->title());
        $name = trim(mb_substr($name, 0, 31)) ?: 'Extract';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$this->escape($name).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    /**
     * The number formats, in the order the STYLE_* constants name them.
     *
     * Money is written as `#,##0.00` without an R: the value is a number and
     * the currency is the column's heading. A currency-formatted cell that
     * lands in a workbook alongside somebody's own R column is the sort of
     * thing that makes a total look right and be wrong.
     */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="4">'
            .'<numFmt numFmtId="164" formatCode="yyyy\\-mm\\-dd"/>'
            .'<numFmt numFmtId="165" formatCode="yyyy\\-mm\\-dd\\ hh:mm"/>'
            .'<numFmt numFmtId="166" formatCode="#,##0.0000"/>'
            .'<numFmt numFmtId="167" formatCode="#,##0.000"/>'
            .'</numFmts>'
            .'<fonts count="2">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="8">'
            .'<xf numFmtId="0"   fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0"   fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="4"   fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="3"   fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="167" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}
