<?php

namespace Tests\Feature\Grid;

use App\Grid\GridColumn;
use App\Grid\GridColumnState;
use App\Grid\GridDefinition;
use App\Grid\GridFilter;
use App\Grid\GridNotFound;
use App\Grid\GridQuery;
use App\Grid\GridRegistry;
use App\Grid\Sources\EloquentSource;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use Modules\Core\Models\Branch;
use Tests\TestCase;

/**
 * The catalogue, the whitelist and the register — everything a grid decides
 * before it has touched a database.
 *
 * Read-only: nothing here writes a row, so it can run against any instance
 * without leaving anything behind. The parts that DO write — the saved layout
 * — are in GridStateTest, which cleans up after itself.
 *
 * The whitelist tests are the ones worth having. Every one of them is a bug ZP
 * shipped and feature-rules §3.6 lists: an unknown key surviving into the
 * stored JSON, a width of 5px, an empty widths map that replays the old widths
 * forever, a column brought back after a resize rendering at zero.
 */
class GridDefinitionTest extends TestCase
{
    /* ------------------------------------------------------------ catalogue */

    public function test_a_column_is_numeric_because_of_its_format(): void
    {
        $money = new GridColumn(key: 'Amount', label: 'Amount', format: 'money');
        $text = new GridColumn(key: 'Site', label: 'Site');

        $this->assertTrue($money->isNumeric(), 'money is a number and right-aligns');
        $this->assertFalse($text->isNumeric());

        // `num` is <x-table>'s own class, which the grid composes. The mockup
        // called it `n`, and `n` has no rule in this stylesheet — a money
        // column carrying it was left aligned.
        $this->assertTrue($money->cellClasses()['num']);
        $this->assertFalse($text->cellClasses()['num']);
    }

    public function test_a_format_the_cell_partial_cannot_write_is_refused_at_construction(): void
    {
        // Caught here rather than as a blank cell on a screen: the partial's
        // fallthrough would render the raw value and nobody would notice the
        // column had lost its formatting.
        $this->expectException(\InvalidArgumentException::class);

        new GridColumn(key: 'Amount', label: 'Amount', format: 'currency');
    }

    public function test_the_filter_control_matches_what_the_cell_renders(): void
    {
        $this->assertSame('number', (new GridColumn(key: 'A', label: 'A', format: 'money'))->filterType());
        $this->assertSame('date', (new GridColumn(key: 'B', label: 'B', format: 'date'))->filterType());
        $this->assertSame('set', (new GridColumn(key: 'C', label: 'C', format: 'chip'))->filterType());
        $this->assertSame('text', (new GridColumn(key: 'D', label: 'D'))->filterType());
    }

    public function test_only_columns_with_a_sort_value_are_sortable(): void
    {
        $definition = BigSetFixture::definition();

        $this->assertSame(
            ['Id', 'SiteName', 'TradingDay', 'Status', 'Amount', 'AmountText'],
            $definition->sortValues(),
            'BranchId declares no sort value, because the procedure has no key for it'
        );

        $this->assertArrayHasKey('BranchId', $definition->catalogue());
        $this->assertFalse($definition->catalogue()['BranchId']->isSortable());
    }

    public function test_the_totals_row_is_derived_from_the_catalogue(): void
    {
        $this->assertSame(['Amount'], BigSetFixture::definition()->totals());
    }

    /* --------------------------------------------------------------- filters */

    public function test_a_tick_list_is_capped_so_a_filter_cannot_become_a_query(): void
    {
        $filter = new GridFilter('Site', 'set', array_map(fn (int $i) => "site-{$i}", range(1, 900)));

        $this->assertCount(GridFilter::MAX_SET, $filter->options);
    }

    public function test_a_text_filter_defaults_to_contains_and_honours_an_exact_match(): void
    {
        $filter = new GridFilter('Site', 'text');

        $this->assertSame(
            ['column' => 'Site', 'type' => 'text', 'op' => 'contains', 'value' => 'ulundi'],
            $filter->normalise(['q' => 'ulundi']),
        );

        // The exact-match checkbox posts "1", which is the shape ZP's grid used.
        $this->assertSame('eq', $filter->normalise(['q' => 'ulundi', 'eq' => '1'])['op']);
    }

    public function test_an_operator_the_column_does_not_offer_falls_back_rather_than_being_passed_on(): void
    {
        $filter = new GridFilter('Amount', 'number');

        $this->assertSame('eq', $filter->normalise(['q' => '100', 'op' => 'sounds-like'])['op']);
        $this->assertSame('gte', $filter->normalise(['q' => '100', 'op' => 'gte'])['op']);

        // Text's `contains` is not a comparator a number offers.
        $this->assertSame('eq', $filter->normalise(['q' => '100', 'op' => 'contains'])['op']);
    }

    public function test_an_empty_filter_is_no_filter(): void
    {
        $filter = new GridFilter('Site', 'text');

        $this->assertNull($filter->normalise(['q' => '   ']));
        $this->assertNull($filter->normalise([]));
        // A nested array is not a value; casting one to a string is the "Array
        // to string conversion" that 500'd the Reports branch selector.
        $this->assertNull($filter->normalise(['q' => ['a' => 'b']]));
    }

    public function test_a_set_filter_takes_only_its_in_list(): void
    {
        $filter = new GridFilter('Status', 'set', ['Closed', 'Still open']);

        $this->assertSame(
            ['column' => 'Status', 'type' => 'set', 'in' => ['Closed', 'Still open']],
            $filter->normalise(['in' => ['Closed', 'Still open', 'Closed', '']]),
        );
    }

    /* ---------------------------------------------------------- column state */

    public function test_only_the_whitelisted_keys_are_ever_stored(): void
    {
        $state = GridColumnState::sanitise([
            'column_order' => ['Amount', 'Id'],
            'hidden' => ['Status'],
            'text_size' => 'compact',
            // Every one of these is dropped. A user-controlled JSON blob written
            // straight into a column is a payload for whatever reads it, and
            // what reads this is a blade template.
            'evil' => '<script>alert(1)</script>',
            'sql' => "'; DROP TABLE agora.Branch; --",
            'page_size' => 999,
            'sort' => 'not-a-column',
            'dir' => 'sideways',
        ], BigSetFixture::definition());

        $this->assertSame(['column_order', 'hidden', 'text_size'], array_keys($state));
        $this->assertArrayNotHasKey('evil', $state);
        $this->assertArrayNotHasKey('sql', $state);
        $this->assertArrayNotHasKey('page_size', $state, '999 is not one of the offered sizes');
        $this->assertArrayNotHasKey('sort', $state, 'a sort the catalogue does not know is not a sort');
        $this->assertArrayNotHasKey('dir', $state);
    }

    public function test_a_column_the_catalogue_does_not_have_cannot_be_ordered_or_hidden(): void
    {
        $state = GridColumnState::sanitise([
            'column_order' => ['Amount', 'PasswordHash', 'Id'],
            'hidden' => ['PasswordHash'],
        ], BigSetFixture::definition());

        $this->assertSame(['Amount', 'Id'], $state['column_order']);
        $this->assertSame([], $state['hidden']);
    }

    public function test_widths_are_bounded_and_an_empty_map_is_absent(): void
    {
        $definition = BigSetFixture::definition();

        $state = GridColumnState::sanitise([
            'widths' => ['Id' => 5, 'SiteName' => 240, 'Amount' => 5000, 'Status' => 'wide'],
        ], $definition);

        $this->assertSame(['SiteName' => 240], $state['widths'], '5px and 5000px are a fiddle, not a preference');

        // Absent, not `{}`. A stored empty map is a state that keeps being
        // applied, and "reset the widths" then never reaches auto layout.
        $this->assertArrayNotHasKey(
            'widths',
            GridColumnState::sanitise(['widths' => ['Id' => 4000]], $definition),
        );
    }

    public function test_a_column_brought_back_after_a_resize_has_no_width_of_its_own(): void
    {
        // ZP's bug: the widths map is seeded from the columns visible at the
        // time, so a re-shown column has no width and collapses to zero. It has
        // to inherit auto sizing instead.
        $columns = GridColumnState::apply(
            ['widths' => ['SiteName' => 240], 'hidden' => []],
            BigSetFixture::definition(),
        );

        $byKey = collect($columns)->keyBy(fn ($c) => $c->key());

        $this->assertSame('width:240px', $byKey['SiteName']->style());
        $this->assertSame('', $byKey['Amount']->style(), 'no width means auto layout, never zero');
    }

    public function test_the_saved_order_leads_and_the_catalogue_supplies_the_rest(): void
    {
        $columns = GridColumnState::apply(
            ['column_order' => ['Amount', 'Status']],
            BigSetFixture::definition(),
        );

        $keys = array_map(fn ($c) => $c->key(), $columns);

        $this->assertSame(['Amount', 'Status'], array_slice($keys, 0, 2));
        $this->assertContains('Id', $keys, 'a column the saved order never mentioned still appears');
        $this->assertCount(count(BigSetFixture::definition()->columns()), $keys);
    }

    public function test_a_column_added_after_the_user_last_chose_keeps_its_own_default(): void
    {
        // The saved layout could not have mentioned BranchId, so its `hidden`
        // list says nothing about it. Reading absence as "shown" would switch
        // on every column shipped deliberately off, for everybody who had ever
        // opened the chooser.
        $columns = GridColumnState::apply(
            ['column_order' => ['Id', 'SiteName'], 'hidden' => ['SiteName']],
            BigSetFixture::definition(),
        );

        $byKey = collect($columns)->keyBy(fn ($c) => $c->key());

        $this->assertTrue($byKey['Id']->visible);
        $this->assertFalse($byKey['SiteName']->visible, 'the user hid this one');
        $this->assertFalse($byKey['BranchId']->visible, 'and this one is off by catalogue, still');
    }

    public function test_a_layout_with_no_hidden_list_at_all_uses_the_catalogue(): void
    {
        $columns = GridColumnState::apply([], BigSetFixture::definition());
        $byKey = collect($columns)->keyBy(fn ($c) => $c->key());

        $this->assertTrue($byKey['Amount']->visible);
        $this->assertFalse($byKey['BranchId']->visible);
    }

    /* --------------------------------------------------------------- query */

    public function test_the_query_becomes_the_eight_parameters_of_the_procedure_contract(): void
    {
        $query = new GridQuery(
            branchIds: [2, 8],
            from: '2026-08-01',
            to: '2026-09-01',
            search: 'ulundi',
            sort: 'Amount',
            ascending: false,
            page: 3,
            pageSize: 50,
        );

        $this->assertSame([
            'BranchIds' => '2,8',
            'DateFrom' => '2026-08-01',
            'DateTo' => '2026-09-01',
            'Search' => 'ulundi',
            'SortColumn' => 'Amount',
            'SortAsc' => 0,
            'Page' => 3,
            'PageSize' => 50,
        ], $query->procedureParameters());
    }

    public function test_no_branches_means_every_branch_rather_than_an_empty_string(): void
    {
        // STRING_SPLIT('', ',') returns one row holding an empty string and
        // TRY_CONVERT(int, '') is 0 — so an empty CSV would ask every grid
        // procedure for branch 0 and every report would come back empty.
        $this->assertNull((new GridQuery)->procedureParameters()['BranchIds']);
    }

    public function test_the_export_asks_the_same_question_with_the_page_opened_up(): void
    {
        $query = new GridQuery(search: 'ulundi', sort: 'Amount', ascending: false, page: 7, pageSize: 50);
        $export = $query->forExport(100000);

        $this->assertSame(1, $export->page);
        $this->assertSame(100000, $export->pageSize);
        $this->assertSame('ulundi', $export->search, 'the filters travel');
        $this->assertSame('Amount', $export->sort, 'and so does the sort');
        $this->assertFalse($export->ascending);
        $this->assertSame(7, $query->page, 'and the original is untouched');
    }

    /* ------------------------------------------------------------- register */

    public function test_every_registered_grid_resolves_and_agrees_with_its_key(): void
    {
        $registry = app(GridRegistry::class);

        $this->assertNotEmpty($registry->all());

        foreach (array_keys($registry->all()) as $key) {
            // findOrFail throws when the definition's own key() disagrees with
            // the line it is registered on, which is what would silently orphan
            // a saved layout.
            $this->assertSame($key, $registry->findOrFail($key)->key());
        }
    }

    public function test_an_unregistered_key_is_a_named_failure_not_a_null(): void
    {
        $this->expectException(GridNotFound::class);

        app(GridRegistry::class)->findOrFail('app.nothing.here');
    }

    /* --------------------------------------------------------------- sources */

    public function test_a_procedure_without_a_filters_parameter_refuses_header_filters(): void
    {
        // Rather than filtering in PHP, which would put the matching semantics
        // in two places — the exact trap feature-rules §3.2 describes.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/FiltersJson/');

        (new ProcedureSource('usp_Reports_GridDayClose'))->page(
            new GridQuery(filters: [['column' => 'Status', 'type' => 'text', 'op' => 'contains', 'value' => 'x']]),
        );
    }

    public function test_a_procedure_source_names_the_object_the_customer_can_open(): void
    {
        // feature-rules §3.4. Schema-qualified, because that is what they type
        // into SSMS.
        $this->assertSame('agora.usp_Reports_GridDayClose', (new ProcedureSource('usp_Reports_GridDayClose'))->name());
    }

    public function test_an_eloquent_source_names_nothing_because_there_is_nothing_to_open(): void
    {
        $source = new EloquentSource(query: fn () => Branch::query());

        $this->assertNull($source->name());
        $this->assertTrue($source->supportsFilters(), 'the query builder is the semantics, so it can answer them');
    }

    public function test_a_definition_derives_its_filters_from_its_columns(): void
    {
        $filters = (new class extends GridDefinition
        {
            public function key(): string
            {
                return 'app.test.filters';
            }

            public function title(): string
            {
                return 'TEST-Filters';
            }

            public function columns(): array
            {
                return [
                    new GridColumn(key: 'Site', label: 'Site'),
                    new GridColumn(key: 'Amount', label: 'Amount', format: 'money'),
                    new GridColumn(key: 'Note', label: 'Note', filter: 'none'),
                ];
            }

            public function source(): GridSource
            {
                return new EloquentSource(query: fn () => Branch::query());
            }
        })->filters();

        $this->assertSame(['Site', 'Amount'], array_keys($filters));
        $this->assertSame('text', $filters['Site']->type);
        $this->assertSame('number', $filters['Amount']->type);
    }
}
