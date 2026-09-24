<?php

namespace Tests\Unit;

use App\Wholesale\Import\PurchaseFileReader;
use App\Wholesale\Import\PurchaseImportException;
use App\Wholesale\Import\PurchaseListExport;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class PurchaseFileReaderTest extends TestCase
{
    private array $paths = [];

    private function file(string $contents = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'purchase-test-');
        $this->paths[] = $path;
        file_put_contents($path, $contents);

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_csv_preserves_codes_and_reads_localized_headers_and_quoted_comments(): void
    {
        $path = $this->file("\xEF\xBB\xBFЕурокод;Саны;Өндіруші;Ескерту\r\n00123;2;FYG;\"Два; слова\"\r\n");
        $row = (new PurchaseFileReader)->read($path, 'csv')['rows'][2];
        $this->assertSame('00123', $row['article']);
        $this->assertSame('2', $row['quantity']);
        $this->assertSame('FYG', $row['manufacturer']);
        $this->assertSame('Два; слова', $row['comment']);
    }

    public function test_comma_csv_and_russian_aliases_are_supported(): void
    {
        $path = $this->file("Артикул,Кол-во\nDEMO-1,100\n");
        $this->assertSame('100', (new PurchaseFileReader)->read($path, 'csv')['rows'][2]['quantity']);
    }

    public function test_duplicate_headers_are_rejected(): void
    {
        $this->expectExceptionMessage('file_headers');
        (new PurchaseFileReader)->read($this->file("article;quantity;код\nA;1;B"), 'csv');
    }

    public function test_non_utf8_csv_is_rejected(): void
    {
        $this->expectExceptionMessage('file_encoding');
        (new PurchaseFileReader)->read($this->file("article;quantity\n\xFF;1"), 'csv');
    }

    public function test_more_than_1000_rows_is_a_whole_file_error(): void
    {
        $this->expectExceptionMessage('file_rows');
        (new PurchaseFileReader)->read($this->file("article;quantity\n".str_repeat("A;1\n", 1001)), 'csv');
    }

    public function test_file_limit_is_checked_before_parsing(): void
    {
        $path = $this->file();
        $stream = fopen($path, 'w');
        ftruncate($stream, PurchaseFileReader::MAX_BYTES + 1);
        fclose($stream);
        $this->expectExceptionMessage('file_size');
        (new PurchaseFileReader)->read($path, 'csv');
    }

    public function test_xls_and_corrupt_xlsx_are_rejected(): void
    {
        foreach (['xls' => 'file_type', 'xlsx' => 'file_invalid'] as $extension => $reason) {
            try {
                (new PurchaseFileReader)->read($this->file('not a workbook'), $extension);
                $this->fail('Invalid file was accepted');
            } catch (PurchaseImportException $exception) {
                $this->assertSame($reason, $exception->reason);
            }
        }
    }

    public function test_both_downloadable_templates_can_be_read(): void
    {
        foreach (['csv', 'xlsx'] as $format) {
            $result = (new PurchaseFileReader)->read($this->file((new PurchaseListExport)->template($format)), $format);
            $this->assertCount(2, $result['rows']);
            $this->assertSame('DEMO-CAMRY', $result['rows'][2]['article']);
            $this->assertSame('FYG', $result['rows'][2]['manufacturer']);
        }
    }

    public function test_formula_cells_are_reported_without_evaluating_or_using_cached_values(): void
    {
        $path = $this->file();
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['article', 'quantity']));
        $writer->addRow(Row::fromValues(['DEMO-1', '=1+1']));
        $writer->close();
        $row = (new PurchaseFileReader)->read($path, 'xlsx')['rows'][2];
        $this->assertSame('formula', $row['issue']);
        $this->assertSame('=1+1', $row['quantity']);
    }

    public function test_dtd_and_large_dimensions_are_rejected_before_opening_reader(): void
    {
        foreach ([
            '<!DOCTYPE foo [<!ENTITY x SYSTEM "file:///unused">]><foo>&x;</foo>' => 'file_unsafe',
            '<worksheet><dimension ref="A1:XFD1048576"/></worksheet>' => 'file_archive_limit',
            '<worksheet><sheetData><row r="1000000000"><c><v>1</v></c></row></sheetData></worksheet>' => 'file_archive_limit',
        ] as $xml => $reason) {
            $path = $this->file((new PurchaseListExport)->template('xlsx'));
            $zip = new ZipArchive;
            $zip->open($path);
            $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
            $zip->close();
            try {
                (new PurchaseFileReader)->read($path, 'xlsx');
                $this->fail('Unsafe structure was accepted');
            } catch (PurchaseImportException $exception) {
                $this->assertSame($reason, $exception->reason);
            }
        }
    }

    public function test_zip_unpacked_size_is_bounded(): void
    {
        $path = $this->file((new PurchaseListExport)->template('xlsx'));
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('xl/oversized.xml', str_repeat(' ', 8 * 1024 * 1024 + 1));
        $zip->close();
        $this->expectExceptionMessage('file_archive_limit');
        (new PurchaseFileReader)->read($path, 'xlsx');
    }

    public function test_exported_report_neutralizes_spreadsheet_formulas(): void
    {
        $csv = (new PurchaseListExport)->csv([['=1+1', ' +SUM(A1)', '@cmd', '-1', 'normal']]);
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString("' +SUM(A1)", $csv);
        $this->assertStringContainsString("'@cmd", $csv);
        $this->assertStringContainsString("'-1", $csv);
    }

    public function test_source_row_numbers_survive_blank_rows_and_multiline_comments(): void
    {
        $csv = "article;quantity;comment\nA;1;\"two\nlines\"\n\nB;2;done\n";
        $this->assertSame([2, 5], array_keys((new PurchaseFileReader)->read($this->file($csv), 'csv')['rows']));
        $path = $this->file();
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['article', 'quantity']));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['DEMO-1', 2]));
        $writer->close();
        $this->assertSame([3], array_keys((new PurchaseFileReader)->read($path, 'xlsx')['rows']));
    }
}
