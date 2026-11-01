<?php

namespace Tests\Feature\StockRecon;

use App\Grid\GridColumn;
use App\Support\Format;
use Modules\StockRecon\Grids\StockReconVarianceGrid;
use Modules\StockRecon\Grids\WeeklyCaseGrid;
use Tests\TestCase;

class QuantityDisplayTest extends TestCase
{
    public function test_quantities_are_rounded_and_trimmed_without_losing_signs_or_empty_values(): void
    {
        foreach ([
            ['35.000', '35'], ['7.500', '7.5'], ['-10.000', '-10'],
            ['1.2346', '1.235'], ['999.9996', '1 000'], ['0', '0'],
            [null, '—'], ['', '—'], ['not a number', '—'],
        ] as [$input, $expected]) {
            $this->assertSame($expected, Format::qty($input));
        }
    }

    public function test_duplicate_correction_uses_compact_quantities_only_when_requested(): void
    {
        $html = \Illuminate\Support\Facades\Blade::render(
            '<x-reports::before-after before="35.000" after="7.500" :dp="3" :compact-quantity="true" />'
        );
        $this->assertStringContainsString('>35</span>', $html);
        $this->assertStringContainsString('>7.5</span>', $html);

        $default = \Illuminate\Support\Facades\Blade::render(
            '<x-reports::before-after before="35.000" after="7.500" :dp="3" />'
        );
        $this->assertStringContainsString('>35.000</span>', $default);
        $this->assertStringContainsString('>7.500</span>', $default);
    }

    public function test_stock_grid_cells_and_totals_can_use_compact_display_without_changing_types(): void
    {
        $columns = (new StockReconVarianceGrid)->columns();
        $qty = collect($columns)->firstWhere('key', 'QtyVar');
        $money = collect($columns)->firstWhere('key', 'ValueVar');
        $weekly = collect((new WeeklyCaseGrid)->columns())->firstWhere('key', 'CountQty');

        $this->assertTrue($qty->compactQuantity);
        $this->assertTrue($qty->isNumeric());
        $this->assertSame('number', $qty->filterType());
        $this->assertSame('QtyVar', $qty->sort);
        $this->assertSame('qty', $qty->format);
        $this->assertSame('number', $weekly->format);
        $this->assertTrue($weekly->compactQuantity);
        $this->assertFalse($money->compactQuantity);

        $render = fn (GridColumn $column, mixed $value) => trim(view('grid._cell', [
            'column' => $column, 'row' => (object) [$column->key => $value],
        ])->render());
        $this->assertSame('-10', $render($qty, '-10.000'));
        $this->assertSame('7.5', $render($weekly, '7.500'));
        $this->assertSame('R7.50', $render($money, '7.500'));
        $this->assertStringContainsString('—', $render($qty, null));
        $this->assertSame('7.500', $render(new GridColumn('qty', 'Other quantity', format: 'qty'), '7.500'));
    }
}
