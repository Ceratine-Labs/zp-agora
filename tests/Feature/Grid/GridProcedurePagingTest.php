<?php

namespace Tests\Feature\Grid;

use App\Grid\Export\CsvWriter;
use App\Grid\Export\GridExportTooLarge;
use App\Grid\Export\GridExtract;
use App\Grid\Export\XlsxWriter;
use App\Grid\GridDefinition;
use App\Grid\GridQuery;
use App\Grid\Sources\GridSource;
use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

/**
 * The acceptance, against ten thousand real rows.
 *
 * "A 10 000-row proc result pages, sorts numerically, and extracts to XLSX with
 * the visible columns." Every one of those is asserted here against a procedure
 * that actually returns that many, not against a fixture array.
 *
 * The procedure is created in setUpBeforeClass and dropped in
 * tearDownAfterClass. It writes nothing to any table, in any database, and it
 * is named TEST- in spirit — `usp_TEST_GridBigSet` — so a leftover object is
 * obvious in a listing. It touches only the agora schema and never dbo.
 */
class GridProcedurePagingTest extends TestCase
{
    private static bool $deployed = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$deployed) {
            BigSetFixture::deploy();
            self::$deployed = true;
        }
    }

    public static function tearDownAfterClass(): void
    {
        // The application is booted per test, not per class, so the drop needs
        // its own container to read the connection out of. Cheaper than leaving
        // a procedure behind and finding it next month.
        $app = require __DIR__.'/../../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        BigSetFixture::drop();

        self::$deployed = false;

        parent::tearDownAfterClass();
    }

    private function source(): GridSource
    {
        return BigSetFixture::definition()->source();
    }

    /* --------------------------------------------------------------- paging */

    public function test_ten_thousand_rows_come_back_one_page_at_a_time(): void
    {
        $page = $this->source()->page(new GridQuery(sort: 'Id', page: 1, pageSize: 50));

        $this->assertSame(BigSetFixture::ROWS, $page->total, 'the whole filtered set');
        $this->assertCount(50, $page->rows, 'and one page of it');
    }

    public function test_the_last_page_is_reachable_and_holds_what_is_left(): void
    {
        // 10 000 rows at 50 a page is 200 pages. The failure this guards is an
        // OFFSET computed from a 0-based page, which puts the last page one
        // short and quietly loses fifty rows off the end of every grid.
        $page = $this->source()->page(new GridQuery(sort: 'Id', page: 200, pageSize: 50));

        $this->assertCount(50, $page->rows);
        $this->assertSame(9951, (int) $page->rows->first()->Id);
        $this->assertSame(10000, (int) $page->rows->last()->Id);
    }

    public function test_the_pages_do_not_overlap_and_do_not_skip(): void
    {
        $first = $this->source()->page(new GridQuery(sort: 'Id', page: 1, pageSize: 25));
        $second = $this->source()->page(new GridQuery(sort: 'Id', page: 2, pageSize: 25));

        $this->assertSame(1, (int) $first->rows->first()->Id);
        $this->assertSame(25, (int) $first->rows->last()->Id);
        $this->assertSame(26, (int) $second->rows->first()->Id);
    }

    /* ------------------------------------------------------- numeric sorting */

    public function test_a_numeric_column_sorts_as_a_number_so_ten_comes_after_nine(): void
    {
        // The classic grid defect: a numeric column ordered as text, where 10
        // sorts before 9 and the biggest figure on the page is 999.99. The
        // acceptance names it, so it is asserted rather than assumed.
        $page = $this->source()->page(new GridQuery(sort: 'Amount', ascending: true, page: 1, pageSize: 12));

        $amounts = $page->rows->map(fn ($r) => (float) $r->Amount)->all();

        $this->assertSame(range(1, 12), array_map('intval', $amounts));
        $this->assertGreaterThan(
            array_search(9.0, $amounts, true),
            array_search(10.0, $amounts, true),
            '10 must come AFTER 9',
        );
    }

    public function test_the_same_values_as_text_sort_as_text_which_is_the_defect(): void
    {
        // The control for the test above. Same numbers, declared as text, and
        // the procedure orders them as text — proving the numeric result is a
        // property of the CONVERT in the ORDER BY and not of the data happening
        // to come out right.
        $page = $this->source()->page(new GridQuery(sort: 'AmountText', ascending: true, page: 1, pageSize: 5));

        $this->assertSame(['1', '10', '100', '1000', '10000'], $page->rows->pluck('AmountText')->all());
    }

    public function test_descending_returns_the_largest_first(): void
    {
        $page = $this->source()->page(new GridQuery(sort: 'Amount', ascending: false, page: 1, pageSize: 3));

        $this->assertSame([10000, 9999, 9998], $page->rows->map(fn ($r) => (int) $r->Amount)->all());
    }

    public function test_a_sort_the_procedure_does_not_know_falls_back_to_its_natural_order(): void
    {
        // An unrecognised @SortColumn is silently ignored by the CASE. That is
        // why GridService whitelists it — but the procedure has to be safe on
        // its own too, because the customer calls it from SSMS.
        $page = $this->source()->page(new GridQuery(sort: 'DROP TABLE', page: 1, pageSize: 3));

        $this->assertSame([1, 2, 3], $page->rows->map(fn ($r) => (int) $r->Id)->all());
    }

    /* ------------------------------------------------------------ narrowing */

    public function test_the_search_narrows_the_set_and_the_total_follows_it(): void
    {
        $page = $this->source()->page(new GridQuery(search: 'TEST-Site 07', page: 1, pageSize: 10));

        $this->assertLessThan(BigSetFixture::ROWS, $page->total);
        $this->assertGreaterThan(0, $page->total);
        $this->assertSame(['TEST-Site 07'], $page->rows->pluck('SiteName')->unique()->values()->all());
    }

    public function test_a_branch_list_narrows_it_and_an_empty_one_does_not(): void
    {
        $scoped = $this->source()->page(new GridQuery(branchIds: [5, 6], page: 1, pageSize: 5));
        $all = $this->source()->page(new GridQuery(page: 1, pageSize: 5));

        // `map('intval')` would be a bug here: Collection::map passes the KEY
        // as intval's second argument, so intval('6', 1) is base 1 and 0.
        $this->assertSame([5, 6], $scoped->rows->pluck('BranchId')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all());
        $this->assertSame(BigSetFixture::ROWS, $all->total, 'no branches means every branch, not none');
    }

    /* -------------------------------------------------------------- extract */

    public function test_the_extract_carries_the_whole_answer_not_the_page(): void
    {
        $extract = GridExtract::run(
            BigSetFixture::definition(),
            (new GridQuery(sort: 'Amount', page: 4, pageSize: 50))->forExport(100000),
        );

        $this->assertSame(BigSetFixture::ROWS, $extract->total);
        $this->assertCount(BigSetFixture::ROWS, $extract->rows);
    }

    public function test_the_extract_carries_the_visible_columns_in_the_users_order(): void
    {
        $extract = GridExtract::run(
            BigSetFixture::definition(),
            new GridQuery(sort: 'Id', page: 1, pageSize: 20),
            ['Amount', 'SiteName', 'Id'],
        );

        $this->assertSame(['Amount', 'SiteName', 'Id'], array_map(fn ($c) => $c->key, $extract->columns));
    }

    public function test_a_column_the_catalogue_does_not_have_is_dropped_from_the_extract(): void
    {
        // A malformed state degrades to an extract rather than failing the
        // download (feature-rules §3.2).
        $extract = GridExtract::run(
            BigSetFixture::definition(),
            new GridQuery(page: 1, pageSize: 5),
            ['Amount', 'PasswordHash'],
        );

        $this->assertSame(['Amount'], array_map(fn ($c) => $c->key, $extract->columns));
    }

    public function test_an_answer_over_the_ceiling_is_refused_rather_than_streamed(): void
    {
        $definition = new class(BigSetFixture::definition()) extends GridDefinition
        {
            public function __construct(private GridDefinition $inner) {}

            public function key(): string
            {
                return 'app.test.tiny-ceiling';
            }

            public function title(): string
            {
                return 'TEST-Tiny ceiling';
            }

            public function columns(): array
            {
                return $this->inner->columns();
            }

            public function source(): GridSource
            {
                return $this->inner->source();
            }

            public function exportCeiling(): int
            {
                return 500;
            }
        };

        $this->expectException(GridExportTooLarge::class);

        GridExtract::run($definition, new GridQuery(page: 1, pageSize: 50));
    }

    public function test_the_csv_is_the_columns_asked_for_with_numbers_as_numbers(): void
    {
        $extract = GridExtract::run(
            BigSetFixture::definition(),
            new GridQuery(sort: 'Id', page: 1, pageSize: 3),
            ['Id', 'SiteName', 'Amount', 'TradingDay'],
        );

        $response = (new CsvWriter)->stream($extract);

        ob_start();
        $response->sendContent();
        $csv = (string) ob_get_clean();

        $lines = explode("\n", trim($csv));

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'a BOM, or Excel on Windows reads it as 1252');
        // fputcsv quotes any field carrying a space, which "Trading day" does.
        $this->assertSame('Id,Site,Amount,"Trading day"', ltrim($lines[0], "\xEF\xBB\xBF"));
        // 1.00 rather than "R1.00": the point of an extract is that the column
        // adds up in the spreadsheet it lands in.
        $this->assertSame('1,"TEST-Site 03",1,2026-09-04', trim($lines[1]));
    }

    public function test_the_xlsx_is_a_real_workbook_with_the_visible_columns(): void
    {
        $extract = GridExtract::run(
            BigSetFixture::definition(),
            new GridQuery(sort: 'Amount', ascending: false, page: 1, pageSize: 100),
            ['SiteName', 'Amount', 'TradingDay'],
        );

        $path = (new XlsxWriter)->build($extract);

        try {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path) === true, 'the file opens as an archive');

            foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $part) {
                $this->assertNotFalse($zip->locateName($part), "the workbook carries {$part}");
            }

            $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();

            // `false`, not null, is what a malformed document produces — and a
            // workbook Excel declares unreadable is exactly what a stray control
            // character out of a legacy ntext column would give us.
            $this->assertNotFalse(simplexml_load_string($sheet), 'the sheet is well-formed XML');

            // The header row, in the order asked for.
            $this->assertStringContainsString('<t xml:space="preserve">Site</t>', $sheet);
            $this->assertStringContainsString('<t xml:space="preserve">Amount</t>', $sheet);
            $this->assertStringNotContainsString('<t xml:space="preserve">Id</t>', $sheet, 'Id was not visible');

            // Numbers as numbers, not as inline strings — so the column sums.
            $this->assertStringContainsString('<v>10000</v>', $sheet);
            // And a date as a serial, so the column sorts as a date.
            $this->assertMatchesRegularExpression('/s="4"><v>4\d{4}(\.\d+)?<\/v>/', $sheet);
        } finally {
            @unlink($path);
        }
    }
}
