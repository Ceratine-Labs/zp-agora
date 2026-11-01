<?php

namespace Tests\Feature\StockRecon;

use App\Grid\GridColumn;
use Modules\StockRecon\Grids\WaterMeterGrid;
use Modules\StockRecon\Support\ShiftLabel;
use Tests\TestCase;

class ShiftLabelTest extends TestCase
{
    public function test_known_and_unknown_shift_numbers_remain_identifiable(): void
    {
        $this->assertSame('1 (Day)', ShiftLabel::show(1));
        $this->assertSame('2 (Afternoon)', ShiftLabel::show('2'));
        $this->assertSame('3 (Night)', ShiftLabel::show(3));
        $this->assertSame('4', ShiftLabel::show(4));
        $this->assertSame('—', ShiftLabel::show(null));
    }

    public function test_stock_grid_cells_show_labels_without_changing_numeric_filters(): void
    {
        $column = new GridColumn('ShiftNo', 'Shift', format: 'number');
        foreach (['stockrecon::grid._cells', 'stockrecon::grid._variance-cells', 'stockrecon::grid._water-cells'] as $partial) {
            $render = fn ($value) => trim(view($partial, ['column' => $column, 'row' => (object) ['ShiftNo' => $value]])->render());
            $this->assertSame('2 (Afternoon)', $render(2));
            $this->assertSame('4', $render(4));
            $this->assertSame('—', $render(null));
        }
        $this->assertSame('number', $column->filterType());
        $this->assertSame('stockrecon::grid._water-cells', app(WaterMeterGrid::class)->cellsPartial());
        $previous = new GridColumn('PreviousShift', 'Previous shift', format: 'number');
        $this->assertSame('3 (Night)', trim(view('stockrecon::grid._water-cells', [
            'column' => $previous, 'row' => (object) ['PreviousShift' => 3],
        ])->render()));
    }
}
