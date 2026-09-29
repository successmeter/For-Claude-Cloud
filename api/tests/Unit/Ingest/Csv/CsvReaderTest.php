<?php
// api/tests/Unit/Ingest/Csv/CsvReaderTest.php

namespace Tests\Unit\Ingest\Csv;

use App\Ingest\Csv\CsvProblem;
use App\Ingest\Csv\CsvReader;
use PHPUnit\Framework\TestCase;

class CsvReaderTest extends TestCase
{
    private function reader(int $maxBytes = 2 * 1024 * 1024, int $maxRows = 5000): CsvReader
    {
        return new CsvReader($maxBytes, $maxRows);
    }

    private function problem(string $bytes, string $code, ?CsvReader $reader = null): CsvProblem
    {
        try {
            ($reader ?? $this->reader())->read($bytes);
        } catch (CsvProblem $e) {
            $this->assertSame($code, $e->problem);

            return $e;
        }
        $this->fail("expected {$code}");
    }

    public function test_plain_file(): void
    {
        $table = $this->reader()->read("date,revenue\r\n2026-01-01,100\r\n2026-01-02,200\r\n");

        $this->assertSame(['date', 'revenue'], $table->header);
        $this->assertSame([['2026-01-01', '100'], ['2026-01-02', '200']], $table->rows);
        $this->assertSame([2, 3], $table->rowNumbers);
        $this->assertSame(',', $table->delimiter);
    }

    public function test_bom_is_stripped(): void
    {
        $this->assertSame(['date', 'revenue'], $this->reader()->read("\xEF\xBB\xBFdate,revenue\n2026-01-01,1\n")->header);
    }

    public function test_semicolons_and_tabs(): void
    {
        $this->assertSame([['01.02.2026', '1.234,50']], $this->reader()->read("Datum;Umsatz\n01.02.2026;1.234,50\n")->rows);
        $this->assertSame(';', $this->reader()->read("a;b\n1;2\n")->delimiter);
        $this->assertSame([['2026-01-01', '$1,234.50']], $this->reader()->read("date\trevenue\n2026-01-01\t$1,234.50\n")->rows);
    }

    public function test_quoted_commas_and_newlines(): void
    {
        $table = $this->reader()->read("date,revenue,note\n2026-01-01,\"1,234.50\",\"two\nlines\"\n2026-01-02,5,x\n");

        $this->assertSame(['2026-01-01', '1,234.50', "two\nlines"], $table->rows[0]);
        $this->assertSame([2, 3], $table->rowNumbers);
    }

    public function test_blank_lines_are_skipped_but_keep_row_numbers(): void
    {
        $table = $this->reader()->read("date,revenue\n\n2026-01-01,1\n  \n2026-01-02,2\n");

        $this->assertSame([3, 5], $table->rowNumbers);
    }

    public function test_short_rows_are_padded_and_long_rows_refused(): void
    {
        $this->assertSame([['2026-01-01', '']], $this->reader()->read("date,revenue\n2026-01-01\n")->rows);

        $e = $this->problem("date,revenue\n2026-01-01,1,extra\n", 'row_too_long');
        $this->assertSame(2, $e->row);
    }

    public function test_invalid_utf8_is_refused(): void
    {
        $this->problem("date,revenue\n2026-01-01,1\xC3\n", 'encoding_not_utf8');
        $this->problem("caf\xE9,revenue\n", 'encoding_not_utf8'); // Windows-1252 é
        $this->problem("date,revenue\n\0\0\0", 'encoding_not_utf8');
    }

    public function test_size_and_row_limits(): void
    {
        $this->problem(str_repeat('a', 101), 'file_too_large', $this->reader(maxBytes: 100));
        $this->assertCount(3, $this->reader(maxRows: 3)->read("d,r\n1,1\n2,2\n3,3\n")->rows);
        $this->problem("d,r\n1,1\n2,2\n3,3\n4,4\n", 'too_many_rows', $this->reader(maxRows: 3));
    }

    public function test_header_problems(): void
    {
        $this->problem('', 'empty_file');
        $this->problem("\n\n", 'empty_file');
        $this->problem("date,,revenue\n", 'header_invalid');
        $this->problem("Date,date\n", 'header_invalid');
    }

    public function test_header_cells_are_trimmed_and_data_cells_kept_as_given(): void
    {
        $table = $this->reader()->read(" date , revenue \n 2026-01-01 , 1 \n");

        $this->assertSame(['date', 'revenue'], $table->header);
        $this->assertSame([' 2026-01-01 ', ' 1 '], $table->rows[0]);
    }

    public function test_formulas_are_plain_strings(): void
    {
        $this->assertSame(['=cmd|\' /C calc\'!A0', '+1'], $this->reader()->read("a,b\n\"=cmd|' /C calc'!A0\",+1\n")->rows[0]);
    }

    public function test_the_problem_says_which_status_to_answer(): void
    {
        $this->assertSame(413, $this->problem(str_repeat('a', 101), 'file_too_large', $this->reader(maxBytes: 100))->status);
        $this->assertSame(422, $this->problem('', 'empty_file')->status);
    }
}
