<?php

/**
 * @file plugins/generic/reviewerDirectory/tests/SpreadsheetWriterTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SpreadsheetWriterTest
 *
 * @brief The export files: a CSV that Excel opens in Portuguese with the accents, with
 *        no cell read as a formula, and an XLSX package that spreadsheets accept.
 */

namespace APP\plugins\generic\reviewerDirectory\tests;

use APP\plugins\generic\reviewerDirectory\classes\SpreadsheetWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\tests\PKPTestCase;

#[CoversClass(SpreadsheetWriter::class)]
class SpreadsheetWriterTest extends PKPTestCase
{
    /** Write some rows and return what was sent. */
    protected function write(string $format, array $rows): string
    {
        ob_start();
        $writer = new SpreadsheetWriter($format);
        $writer->open('test');
        foreach ($rows as $i => $cells) {
            $writer->row($cells, $i === 1);
        }
        $writer->close('test');
        return (string) ob_get_clean();
    }

    public function testColumnNames(): void
    {
        $this->assertSame(['A', 'Z', 'AA', 'AD', 'AZ', 'BA', 'ZZ', 'AAA'], array_map(
            SpreadsheetWriter::columnName(...),
            [0, 25, 26, 29, 51, 52, 701, 702]
        ));
    }

    public function testCsvForExcelInPortuguese(): void
    {
        $csv = $this->write(SpreadsheetWriter::FORMAT_CSV, [
            ['Filtros: Situação'],
            ['ID', 'Título'],
            [12, "Avaliação; \"citação\"\nsegunda linha"],
        ]);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $rows = [];
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, substr($csv, 3));
        rewind($handle);
        while (($row = fgetcsv($handle, null, ';', '"', '')) !== false) {
            $rows[] = $row;
        }
        $this->assertSame([['Filtros: Situação'], ['ID', 'Título'], ['12', "Avaliação; \"citação\"\nsegunda linha"]], $rows);
    }

    public function testCsvCellsNeverStartAFormula(): void
    {
        $csv = $this->write(SpreadsheetWriter::FORMAT_CSV, [
            ['=HYPERLINK("http://example.com","x")', '+1+1', '@SUM(A1)', '-2', '-cmd', 'plain'],
        ]);
        $row = str_getcsv(trim(substr($csv, 3)), ';', '"', '');
        $this->assertSame(["'=HYPERLINK(\"http://example.com\",\"x\")", "'+1+1", "'@SUM(A1)", '-2', "'-cmd", 'plain'], $row);
    }

    public function testXlsxPackage(): void
    {
        if (!SpreadsheetWriter::canWriteXlsx()) {
            $this->markTestSkipped('No ZipArchive on this server: the page offers CSV only.');
        }
        $data = $this->write(SpreadsheetWriter::FORMAT_XLSX, [
            ['Filtros & período <todos>'],
            ['ID', 'Título', 'Avaliadores'],
            [12, 'Avaliação', "Ana\nBruno\x07"],
            [13, '', null],
        ]);
        $this->assertStringStartsWith('PK', $data);

        $file = tempnam(sys_get_temp_dir(), 'sqrtest');
        file_put_contents($file, $data);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($file) === true);
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $part) {
            $xml = $zip->getFromName($part);
            $this->assertNotFalse($xml, "Missing {$part}.");
            $document = new \DOMDocument();
            $this->assertTrue($document->loadXML($xml), "{$part} is not well-formed XML.");
        }
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($file);

        $this->assertStringContainsString('<pane ySplit="2" topLeftCell="A3" activePane="bottomLeft" state="frozen"/>', $sheet);
        $this->assertStringContainsString('<autoFilter ref="A2:C4"/>', $sheet);
        $this->assertStringContainsString('<c r="A3"><v>12</v></c>', $sheet);
        $this->assertStringContainsString('<c r="A2" t="inlineStr" s="1">', $sheet);
        $this->assertStringContainsString('Filtros &amp; período &lt;todos&gt;', $sheet);
        // A cell with more than one line wraps, and control characters are dropped.
        $this->assertStringContainsString('<c r="C3" t="inlineStr" s="2"><is><t xml:space="preserve">Ana' . "\nBruno</t>", $sheet);
        $this->assertSame(4, substr_count($sheet, '<row '));
    }
}
