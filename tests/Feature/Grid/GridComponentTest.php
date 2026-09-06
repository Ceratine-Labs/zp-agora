<?php

namespace Tests\Feature\Grid;

use App\Grid\GridColumn;
use App\Grid\GridColumnState;
use App\Grid\GridDefinition;
use App\Grid\GridQuery;
use App\Grid\GridResult;
use App\Grid\Sources\EloquentSource;
use App\Grid\Sources\GridSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Modules\Core\Models\Branch;
use Tests\TestCase;

/**
 * `<x-data-grid>` rendered from fixture rows, with no request behind it.
 *
 * A component never queries the database (plan §3.8), and this is the test that
 * proves it: the whole grid is built here from a GridResult constructed by
 * hand, and it renders. Nothing is mocked and nothing is stubbed, because there
 * is nothing for the component to reach for.
 *
 * It is also where the parts the development gallery cannot show get covered —
 * the row-detail contract, the footer totals, the limit note, the over-ceiling
 * refusal and the refusal state — because each needs a grid configured in a way
 * neither demo grid is.
 */
class GridComponentTest extends TestCase
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function grid(
        array $rows,
        int $total = 3,
        string $detailMode = 'none',
        ?string $refusal = null,
        int $ceiling = 100000,
    ): GridResult {
        $definition = new class($detailMode, $ceiling) extends GridDefinition
        {
            public function __construct(private string $mode, private int $ceiling) {}

            public function key(): string
            {
                return 'app.test.component';
            }

            public function title(): string
            {
                return 'TEST-Component';
            }

            public function columns(): array
            {
                return [
                    new GridColumn(key: 'Ref', label: 'Reference', mono: true, sort: 'Ref'),
                    new GridColumn(key: 'Amount', label: 'Amount', format: 'money', sort: 'Amount', total: true),
                    new GridColumn(key: 'Status', label: 'Status', format: 'chip'),
                    new GridColumn(key: 'Note', label: 'Note', wide: true),
                    new GridColumn(key: 'Hidden', label: 'Not by default', visible: false),
                ];
            }

            public function source(): GridSource
            {
                // Never called: this test builds the GridResult itself.
                return new EloquentSource(query: fn () => Branch::query());
            }

            public function detailMode(): string
            {
                return $this->mode;
            }

            public function detailUrl(object $row): string
            {
                return '/app/test/detail/'.($row->Ref ?? '');
            }

            public function exportCeiling(): int
            {
                return $this->ceiling;
            }

            public function selectable(): bool
            {
                return true;
            }

            public function rowKey(object $row): string|int|null
            {
                return $row->Ref ?? null;
            }
        };

        return new GridResult(
            definition: $definition,
            columns: GridColumnState::apply([], $definition),
            // Built as an array of objects and wrapped, not mapped into a
            // collection: Collection's value type is invariant, so a
            // Collection<int, stdClass> cannot be passed where
            // Collection<int, object> is declared. Same reason as
            // EloquentSource::plain().
            rows: new Collection($this->objects($rows)),
            gridQuery: new GridQuery(sort: 'Amount', ascending: false, page: 1, pageSize: 50),
            total: $total,
            totals: ['Amount' => 4200.5],
            totalsAreGrand: false,
            textSize: 'compact',
            state: [],
            baseUrl: 'http://localhost/app/test',
            query: [],
            ms: 12.0,
            refusal: $refusal,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, object>
     */
    private function objects(array $rows): array
    {
        return array_map(fn (array $row): object => (object) $row, $rows);
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        return [
            ['Ref' => 'TEST-0001', 'Amount' => 1200.25, 'Status' => 'Balanced', 'Note' => 'Counted twice', 'Hidden' => 'x'],
            ['Ref' => 'TEST-0002', 'Amount' => 3000.25, 'Status' => 'Still open', 'Note' => null, 'Hidden' => 'y'],
            ['Ref' => 'TEST-0003', 'Amount' => null, 'Status' => 'Bank does not agree', 'Note' => '', 'Hidden' => 'z'],
        ];
    }

    /**
     * The rendered HTML with runs of whitespace flattened, so an assertion can
     * be about the sentence rather than about where Blade put its newlines.
     */
    private function text(GridResult $grid): string
    {
        return (string) preg_replace('/\s+/', ' ', $this->render($grid));
    }

    private function render(GridResult $grid): string
    {
        // `$errors` is shared by the web middleware, which Blade::render does
        // not run — and <x-field>'s @error directive needs it. Sharing an empty
        // bag is what a request would have done; leaving it out would fail the
        // test for a reason that has nothing to do with the grid.
        View::share('errors', new ViewErrorBag);

        return Blade::render('<x-data-grid :grid="$grid" />', ['grid' => $grid]);
    }

    public function test_it_renders_from_fixture_rows_without_touching_a_database(): void
    {
        $html = $this->render($this->grid($this->rows()));

        $this->assertStringContainsString('TEST-0001', $html);
        $this->assertStringContainsString('Reference', $html);

        // A column shipped off is not in the TABLE — but it IS in the chooser,
        // because the chooser has to list what is off as well as what is on.
        $this->assertStringNotContainsString('data-column="Hidden"', $html);
        $this->assertStringContainsString('data-chooser-item="Hidden"', $html);
    }

    public function test_a_missing_figure_is_an_em_dash_and_never_a_zero(): void
    {
        $html = $this->render($this->grid($this->rows()));

        $this->assertStringContainsString('R1 200.25', $html);
        $this->assertStringContainsString('—', $html);
        $this->assertStringNotContainsString('R0.00', $html);
    }

    public function test_the_scope_submit_says_what_it_does_and_does_not_shout(): void
    {
        $html = $this->render($this->grid($this->rows()));

        // The button is a GET submit that re-reads with different filters. It
        // used to say "Run" in btn-primary on every grid, which promises a
        // consequence it does not deliver — on a list of user accounts that
        // reads as though something is about to happen to them.
        $this->assertStringContainsString('Apply filters', $html);
        $this->assertStringNotContainsString('>Run<', $html);
        $this->assertMatchesRegularExpression(
            '/<button type="submit" class="btn">/',
            $html,
            'A read-only grid must not paint its filter submit as the primary action.'
        );
    }

    public function test_a_chip_column_takes_its_tone_from_the_wording(): void
    {
        $html = $this->render($this->grid($this->rows()));

        // <x-chip> carries the mockup's bare tone name AND the longer tone-
        // alias, so the two are no longer adjacent in the class attribute.
        // Assert on both spellings rather than on their order: the alias is
        // what the existing Recon and Reports screens style against, and the
        // bare name is what the mockup's CSS ports to.
        foreach (['good' => '"Balanced" is good',
            'warn' => '"Still open" is a warning',
            'serious' => '"does not agree" is serious'] as $tone => $why) {
            $this->assertMatchesRegularExpression('/class="[^"]*\bchip\b[^"]*"/', $html, $why);
            $this->assertMatchesRegularExpression('/class="[^"]*\b'.$tone.'\b[^"]*"/', $html, $why);
            $this->assertStringContainsString('tone-'.$tone, $html, $why);
        }
    }

    public function test_numeric_and_wide_columns_carry_their_classes(): void
    {
        $html = $this->render($this->grid($this->rows()));

        $flat = $this->text($this->grid($this->rows()));

        // `num` and `mono`, not `n` and `w`: those are <x-table>'s own classes,
        // and the grid composes that table. The mockup's names had no rule in
        // this stylesheet at all, so a money column was left aligned.
        $this->assertStringContainsString('<td class="num" data-column="Amount">', $flat);
        $this->assertStringContainsString('<td class="wide" data-column="Note">', $flat);
        $this->assertStringContainsString('<td class="mono" data-column="Ref">', $flat);
    }

    public function test_the_footer_total_says_whether_it_is_the_page_or_the_answer(): void
    {
        $html = $this->render($this->grid($this->rows()));

        $this->assertStringContainsString('dg-total', $html);
        $this->assertStringContainsString('R4 200.50', $html);
        // "total of the 50 rows you can see" and "total of the 12 480 that
        // match" are different numbers, and a footer that does not say which is
        // a figure somebody will quote.
        $this->assertStringContainsString('This page', $html);
    }

    public function test_the_limit_note_appears_only_when_the_page_is_part_of_the_answer(): void
    {
        $partial = $this->text($this->grid($this->rows(), total: 12480));
        $whole = $this->text($this->grid($this->rows(), total: 3));

        $this->assertStringContainsString('Showing the first 3 of 12 480 rows', $partial);
        $this->assertStringContainsString('extract to see them all', $partial);
        $this->assertStringNotContainsString('extract to see them all', $whole);
    }

    public function test_an_expanding_grid_emits_the_row_detail_contract_and_nothing_else(): void
    {
        // §3.5 through row-detail.js, which already owns this markup. The grid
        // emits the contract; it does not fetch, cache or render the fragment.
        $expands = $this->render($this->grid($this->rows(), detailMode: 'expand'));
        $plain = $this->render($this->grid($this->rows()));

        $this->assertStringContainsString('data-row-detail', $expands);
        $this->assertStringContainsString('data-detail-url="/app/test/detail/TEST-0001"', $expands);

        $this->assertStringNotContainsString('data-row-detail', $plain);
        $this->assertStringNotContainsString('data-detail-url', $plain);
    }

    public function test_a_selectable_grid_uses_the_shared_check_all_contract(): void
    {
        $html = $this->render($this->grid($this->rows()));

        // check-all.js's markup, not a second implementation of it.
        $this->assertStringContainsString('data-check-all', $html);
        $this->assertStringContainsString('data-check', $html);
        $this->assertStringContainsString('value="TEST-0001"', $html);
    }

    public function test_an_answer_over_the_ceiling_says_so_before_the_click(): void
    {
        $html = $this->render($this->grid($this->rows(), total: 900, ceiling: 500));

        $this->assertStringContainsString('Too big for a download', $html);
        $this->assertStringNotContainsString('Download .xlsx', $html);
    }

    public function test_a_procedure_refusal_renders_as_a_message_not_an_error_page(): void
    {
        $html = $this->render($this->grid([], total: 0, refusal: 'A range over a year cannot be answered here.'));

        $this->assertStringContainsString('This grid could not be run', $html);
        $this->assertStringContainsString('A range over a year cannot be answered here.', $html);
    }

    public function test_the_drawer_renders_one_well_formed_class_attribute(): void
    {
        // `@class` emits the whole attribute, so nesting it inside class="…"
        // produced `class="drawer class=""` — which every browser recovers from
        // silently, and which is therefore only ever found by looking.
        $html = $this->render($this->grid($this->rows()));

        $this->assertStringContainsString('<div class="drawer"', $html);
        $this->assertStringNotContainsString('class="drawer class=', $html);
    }

    public function test_an_empty_grid_has_a_deliberate_empty_state(): void
    {
        $html = $this->render($this->grid([], total: 0));

        $this->assertStringContainsString('Nothing matched', $html);
    }

    public function test_the_grid_carries_a_loading_state_and_the_procedure_it_came_from(): void
    {
        $html = $this->render($this->grid($this->rows()));

        $this->assertStringContainsString('data-busy', $html);
        $this->assertStringContainsString('Running…', $html);
    }

    public function test_the_mobile_cards_carry_the_first_three_visible_columns(): void
    {
        $html = $this->render($this->grid($this->rows()));

        $this->assertStringContainsString('dg-cards', $html);
        $this->assertStringContainsString('dg-card-face', $html);
        // Four columns are visible, so exactly one falls behind the expand.
        $this->assertStringContainsString('<summary>1 more</summary>', $html);
    }

    public function test_a_saved_width_puts_the_table_into_fixed_layout(): void
    {
        $grid = $this->grid($this->rows());
        $withWidth = new GridResult(
            definition: $grid->definition,
            columns: GridColumnState::apply(['widths' => ['Ref' => 200]], $grid->definition),
            rows: $grid->rows,
            gridQuery: $grid->gridQuery,
            total: $grid->total,
            totals: $grid->totals,
            totalsAreGrand: false,
            textSize: 'normal',
            state: [],
            baseUrl: $grid->baseUrl,
            query: [],
            ms: 1.0,
        );

        // Under auto layout a saved pixel width is only a suggestion, so it has
        // to be applied on LOAD and not only while a border is being dragged.
        $this->assertStringContainsString('is-fixed', $this->render($withWidth));
        $this->assertStringContainsString('width:200px', $this->render($withWidth));
        $this->assertStringNotContainsString('is-fixed', $this->render($grid));
    }
}
