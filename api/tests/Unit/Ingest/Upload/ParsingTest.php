<?php
// api/tests/Unit/Ingest/Upload/ParsingTest.php

namespace Tests\Unit\Ingest\Upload;

use App\Ingest\Csv\CsvReader;
use App\Ingest\Upload\DateFormats;
use App\Ingest\Upload\Mapping;
use App\Ingest\Upload\MappingDetector;
use App\Ingest\Upload\MappingInvalid;
use App\Ingest\Upload\MoneyParser;
use PHPUnit\Framework\TestCase;

class ParsingTest extends TestCase
{
    // --- dates ------------------------------------------------------------------------------

    public function test_each_format_parses_strictly(): void
    {
        $cases = [
            ['YYYY-MM-DD', '2026-02-28', '2026-02-28'],
            ['DD/MM/YYYY', '28/02/2026', '2026-02-28'],
            ['D/M/YYYY', '8/2/2026', '2026-02-08'],
            ['D/M/YYYY', '08/02/2026', '2026-02-08'],
            ['DD-MM-YYYY', '28-02-2026', '2026-02-28'],
            ['DD.MM.YYYY', '28.02.2026', '2026-02-28'],
        ];
        foreach ($cases as [$format, $value, $expected]) {
            $this->assertSame($expected, DateFormats::parse($format, $value)?->toDateString(), "{$format} {$value}");
        }

        foreach ([['YYYY-MM-DD', '2026-02-31'], ['DD/MM/YYYY', '8/2/2026'], ['DD/MM/YYYY', '31/02/2026'],
            ['YYYY-MM-DD', '2026-2-8'], ['DD/MM/YYYY', '13/13/2026'], ['YYYY-MM-DD', ' 2026-02-28x']] as [$format, $value]) {
            $this->assertNull(DateFormats::parse($format, $value), "{$format} {$value}");
        }
        $this->assertSame('2026-02-28', DateFormats::parse('YYYY-MM-DD', ' 2026-02-28 ')->toDateString(), 'surrounding spaces are trimmed');
    }

    public function test_unknown_formats_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DateFormats::parse('MM/DD/YYYY', '02/28/2026');
    }

    // --- money ------------------------------------------------------------------------------

    public function test_money_to_cents(): void
    {
        foreach (['1,234.50' => 123450, '$0.5' => 50, '$ 1 234' => 123400, '1234' => 123400, '0' => 0, '12.3' => 1230,
            '-5.00' => -500, '  99.99 ' => 9999, '$1,000,000.00' => 100000000] as $in => $cents) {
            $this->assertSame($cents, MoneyParser::toCents((string) $in), (string) $in);
        }
        foreach (['12.345', '(12.00)', '€12', 'A$12', '1.234,50', '', '.5', 'abc', '12..3', '1e3', '99999999999999'] as $bad) {
            $this->assertNull(MoneyParser::toCents($bad), $bad);
        }
    }

    // --- detection --------------------------------------------------------------------------

    private function propose(string $csv, bool $gstDefault = true): array
    {
        return (new MappingDetector)->propose((new CsvReader(1 << 20, 5000))->read($csv), $gstDefault);
    }

    public function test_the_template(): void
    {
        $this->assertSame([
            'date_column' => 'date', 'date_format' => 'YYYY-MM-DD', 'date_ambiguous' => false,
            'revenue_column' => 'revenue', 'tx_count_column' => 'transactions', 'gst_inclusive' => true,
            'covers_column' => null, 'food_column' => null, 'drinks_column' => null,
        ], $this->propose("date,revenue,transactions\n2026-01-01,100.00,12\n2026-01-02,120.50,15\n"));
    }

    public function test_a_square_style_export(): void
    {
        $proposal = $this->propose("Date,Gross Sales,Discounts,Net Sales,Tax,Total Collected,Transactions\n15/01/2026,\"\$1,200.00\",\$0.00,\"\$1,100.00\",\$100.00,\"\$1,200.00\",40\n16/01/2026,\$900.00,\$0.00,\$800.00,\$80.00,\$900.00,30\n");

        $this->assertSame(['Date', 'DD/MM/YYYY', false, 'Net Sales', 'Transactions'],
            [$proposal['date_column'], $proposal['date_format'], $proposal['date_ambiguous'], $proposal['revenue_column'], $proposal['tx_count_column']]);
    }

    public function test_a_semicolon_file_with_dotted_dates_and_a_gst_hint(): void
    {
        $proposal = $this->propose("Trading Date;Takings (ex GST);Covers\n25.01.2026;1000.00;50\n26.01.2026;900.00;45\n");

        $this->assertSame(['Trading Date', 'DD.MM.YYYY', 'Takings (ex GST)', null, 'Covers', false],
            [$proposal['date_column'], $proposal['date_format'], $proposal['revenue_column'], $proposal['tx_count_column'], $proposal['covers_column'], $proposal['gst_inclusive']]);
    }

    public function test_covers_food_and_drinks_columns(): void
    {
        $proposal = $this->propose("Date,Food Sales,Beverage Sales,Total Sales,Pax,Receipts\n2026-01-01,600,400,1000,50,30\n");

        $this->assertSame(['Total Sales', 'Receipts', 'Pax', 'Food Sales', 'Beverage Sales'],
            [$proposal['revenue_column'], $proposal['tx_count_column'], $proposal['covers_column'], $proposal['food_column'], $proposal['drinks_column']]);
        $this->assertSame(['Guests', 'Kitchen', 'Bar'], array_values(array_intersect_key(
            $this->propose("date,revenue,Guests,Kitchen,Bar\n2026-01-01,1,1,1,0\n"), array_flip(['covers_column', 'food_column', 'drinks_column']))));
    }

    public function test_the_venue_default_applies_without_a_hint(): void
    {
        $this->assertFalse($this->propose("date,sales\n2026-01-01,1\n", gstDefault: false)['gst_inclusive']);
        $this->assertTrue($this->propose("date,Sales inc GST\n2026-01-01,1\n", gstDefault: false)['gst_inclusive']);
    }

    public function test_a_file_that_could_be_month_first_is_ambiguous(): void
    {
        // Days and months all <= 12: a US export would parse silently as day-first. Make the user choose.
        $proposal = $this->propose("date,revenue\n01/02/2026,1\n02/02/2026,1\n12/02/2026,1\n");

        $this->assertSame([null, true], [$proposal['date_format'], $proposal['date_ambiguous']]);
        $this->assertFalse($this->propose("date,revenue\n13/02/2026,1\n")['date_ambiguous']);
        $this->assertFalse($this->propose("date,revenue\n2026-02-01,1\n")['date_ambiguous'], 'ISO dates are never ambiguous');
    }

    public function test_an_unnamed_date_column_is_found_by_its_values(): void
    {
        $this->assertSame('When', $this->propose("Venue,When,Amount\nCafe,2026-01-01,5\n")['date_column']);
    }

    public function test_nothing_recognisable(): void
    {
        $this->assertSame([null, null, null, null], array_values(array_intersect_key(
            $this->propose("a,b\nx,y\n"), array_flip(['date_column', 'date_format', 'revenue_column', 'tx_count_column']))));
    }

    // --- confirmed mapping ------------------------------------------------------------------

    public function test_a_mapping_must_name_existing_distinct_columns(): void
    {
        $table = (new CsvReader(1 << 20, 5000))->read("date,revenue,tx\n2026-01-01,1,1\n");
        $mapping = Mapping::fromInput(['date_column' => 'date', 'date_format' => 'YYYY-MM-DD', 'revenue_column' => 'revenue', 'tx_count_column' => 'tx', 'gst_inclusive' => '0'], $table);
        $this->assertSame([0, 1, 2, false], [$mapping->dateIndex, $mapping->revenueIndex, $mapping->txIndex, $mapping->gstInclusive]);

        foreach ([
            ['revenue_column' => 'Revenue'],        // case matters: it is the header as written
            ['date_format' => 'MM/DD/YYYY'],
            ['tx_count_column' => 'revenue'],
            ['gst_inclusive' => 'maybe'],
            ['date_column' => null],
        ] as $bad) {
            try {
                Mapping::fromInput($bad + ['date_column' => 'date', 'date_format' => 'YYYY-MM-DD', 'revenue_column' => 'revenue', 'gst_inclusive' => true], $table);
                $this->fail('accepted '.json_encode($bad));
            } catch (MappingInvalid) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
