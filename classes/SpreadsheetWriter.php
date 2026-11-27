<?php

/**
 * @file plugins/generic/reviewerDirectory/classes/SpreadsheetWriter.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SpreadsheetWriter
 *
 * @brief Writes the export row by row, as CSV or as XLSX, without keeping the rows in
 *  memory: the CSV goes straight to the output, the XLSX sheet to a temporary file that
 *  is zipped at the end.
 *
 *  The CSV is what Excel opens directly in Portuguese and Spanish: UTF-8 with a byte
 *  order mark and ";" between the cells. The XLSX has the header in bold, frozen, with
 *  the filter buttons, and wraps the cells with more than one line (the reviewers).
 */

namespace APP\plugins\generic\reviewerDirectory\classes;

class SpreadsheetWriter
{
    public const FORMAT_CSV = 'csv';
    public const FORMAT_XLSX = 'xlsx';

    /** Characters that make a spreadsheet read a cell as a formula. */
    protected const FORMULA_START = ['=', '+', '-', '@', "\t", "\r"];

    /** @var resource|null */
    protected $handle = null;

    protected ?string $sheetFile = null;

    protected int $rowNumber = 0;

    protected int $columnCount = 0;

    protected ?int $headerRow = null;

    public function __construct(public string $format)
    {
    }

    /**
     * Whether XLSX files can be made on this server.
     */
    public static function canWriteXlsx(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    /**
     * Start the file: the CSV starts its download, the XLSX its temporary sheet.
     */
    public function open(string $fileName): void
    {
        if ($this->format === self::FORMAT_XLSX) {
            $this->sheetFile = tempnam(sys_get_temp_dir(), 'ojsbrrd');
            $this->handle = fopen($this->sheetFile, 'wb');
            fwrite($this->handle, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '%%VIEWS%%<sheetData>');
            return;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '.csv"');
        header('Cache-Control: private, no-store');
        $this->handle = fopen('php://output', 'wb');
        fwrite($this->handle, "\xEF\xBB\xBF");
    }

    /**
     * Write a row. A header row is shown in bold, frozen and with filter buttons in the
     * XLSX file.
     *
     * @param array<int,string|int|null> $cells
     */
    public function row(array $cells, bool $isHeader = false): void
    {
        $this->rowNumber++;
        $this->columnCount = max($this->columnCount, count($cells));
        if ($isHeader) {
            $this->headerRow = $this->rowNumber;
        }

        if ($this->format !== self::FORMAT_XLSX) {
            fputcsv($this->handle, array_map(fn ($cell) => $this->safeText($cell), $cells), ';', '"', '');
            return;
        }

        $xml = '<row r="' . $this->rowNumber . '">';
        foreach (array_values($cells) as $i => $cell) {
            $ref = self::columnName($i) . $this->rowNumber;
            if (is_int($cell)) {
                $xml .= '<c r="' . $ref . '"' . ($isHeader ? ' s="1"' : '') . '><v>' . $cell . '</v></c>';
                continue;
            }
            $text = $this->cleanText((string) $cell);
            if ($text === '') {
                continue;
            }
            $style = $isHeader ? 1 : (str_contains($text, "\n") ? 2 : 0);
            $xml .= '<c r="' . $ref . '" t="inlineStr"' . ($style ? ' s="' . $style . '"' : '') . '><is><t xml:space="preserve">'
                . htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
        }
        fwrite($this->handle, $xml . '</row>');
    }

    /**
     * Finish the file: the XLSX is zipped and sent.
     */
    public function close(string $fileName): void
    {
        if ($this->format !== self::FORMAT_XLSX) {
            fclose($this->handle);
            return;
        }

        $lastColumn = self::columnName(max(0, $this->columnCount - 1));
        $tail = '</sheetData>';
        if ($this->headerRow !== null && $this->rowNumber >= $this->headerRow) {
            $tail .= '<autoFilter ref="A' . $this->headerRow . ':' . $lastColumn . $this->rowNumber . '"/>';
        }
        fwrite($this->handle, $tail . '</worksheet>');
        fclose($this->handle);

        // Freeze everything up to the header, now that its row is known.
        $views = '';
        if ($this->headerRow !== null) {
            $views = '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $this->headerRow
                . '" topLeftCell="A' . ($this->headerRow + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        }
        $sheet = $this->sheetFile . '.xml';
        $in = fopen($this->sheetFile, 'rb');
        $out = fopen($sheet, 'wb');
        $head = fread($in, 512);
        fwrite($out, str_replace('%%VIEWS%%', $views, $head));
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        unlink($this->sheetFile);

        $zipFile = $this->sheetFile . '.xlsx';
        $zip = new \ZipArchive();
        $zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($this->packageParts() as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->addFile($sheet, 'xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($sheet);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '.xlsx"');
        header('Content-Length: ' . filesize($zipFile));
        header('Cache-Control: private, no-store');
        readfile($zipFile);
        unlink($zipFile);
    }

    /**
     * The name of a column of a spreadsheet: A, B, ..., Z, AA...
     */
    public static function columnName(int $index): string
    {
        $name = '';
        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $name = chr(65 + ($index - 1) % 26) . $name;
        }
        return $name;
    }

    /**
     * A cell of the CSV: text that would start a formula is kept as text.
     */
    protected function safeText(string|int|null $cell): string|int
    {
        if (is_int($cell)) {
            return $cell;
        }
        $text = $this->cleanText((string) $cell);
        if ($text !== '' && in_array($text[0], self::FORMULA_START, true) && !is_numeric($text)) {
            return "'" . $text;
        }
        return $text;
    }

    /**
     * Text without the control characters a spreadsheet cannot hold.
     */
    protected function cleanText(string $text): string
    {
        return (string) preg_replace('/[^\P{C}\n\t]/u', '', str_replace("\r\n", "\n", $text));
    }

    /**
     * The fixed parts of the XLSX package.
     *
     * @return array<string,string>
     */
    protected function packageParts(): array
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        return [
            '[Content_Types].xml' => $xml . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . '</Types>',
            '_rels/.rels' => $xml . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' => $xml . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets>'
                . ($this->headerRow !== null ? '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">Report!$A$' . $this->headerRow . ':$' . self::columnName(max(0, $this->columnCount - 1)) . '$' . $this->rowNumber . '</definedName></definedNames>' : '')
                . '</workbook>',
            'xl/_rels/workbook.xml.rels' => $xml . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                . '</Relationships>',
            'xl/styles.xml' => $xml . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
                . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
                . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                . '<cellXfs count="3">'
                . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
                . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>'
                . '</cellXfs>'
                . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
                . '</styleSheet>',
        ];
    }
}
