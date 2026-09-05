<?php

namespace Tests\Feature\Charts;

use App\Support\Chart\ChartSpec;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * What a chart is handed, before ApexCharts sees it.
 *
 * Read-only and database-free: a chart spec is arithmetic over an array the
 * controller passed in, which is the whole point of "a component never queries".
 *
 * The visual half of T015's acceptance — the right series colours in both
 * themes, and the response to a container resize — cannot be proved from here
 * and is not claimed to be. What IS provable without a browser is everything
 * that decides what the browser will draw: the type, the series shape, the
 * token references, the absence of a colour literal, the empty state, and the
 * figures in the table twin. That is what this file asserts.
 */
class ChartSpecTest extends TestCase
{
    private const DAILY = [
        ['label' => '01', 'value' => 160998, 'quiet' => true, 'note' => 'Sat'],
        ['label' => '02', 'value' => 105579, 'quiet' => true, 'note' => 'Sun'],
        ['label' => '03', 'value' => 147185, 'quiet' => false, 'note' => 'Mon'],
    ];

    private const BRIDGE = [
        ['label' => 'Budget GP', 'value' => 25534614.63, 'total' => true],
        ['label' => 'Fuel volume', 'value' => -37639.78],
        ['label' => 'Fuel margin', 'value' => -587976.39],
        ['label' => 'Non-fuel', 'value' => -1647076.14],
        ['label' => 'Actual GP', 'value' => 23261922.32, 'total' => true],
    ];

    public function test_an_unknown_type_is_refused_by_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown chart type [pie]');

        ChartSpec::make('pie', []);
    }

    public function test_an_unknown_number_format_is_refused_and_says_what_to_do(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // The point of the message: adding to the pair is a reviewed change to
        // BOTH halves, not something a screen invents on the way past.
        $this->expectExceptionMessage('resources/js/format.js');

        ChartSpec::make('donut', [['label' => 'ULP', 'value' => 1]], ['format' => 'currency'])->payload();
    }

    public function test_a_colour_literal_in_a_callers_overrides_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('token:--s1');

        ChartSpec::make('donut', [['label' => 'ULP', 'value' => 1]], [
            'apex' => ['colors' => ['#2a78d6']],
        ]);
    }

    public function test_a_colour_literal_nested_deep_in_the_overrides_is_still_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ChartSpec::make('line', [['name' => 'a', 'points' => [['label' => '1', 'value' => 1]]]], [
            'apex' => ['annotations' => ['yaxis' => [['borderColor' => '#fff']]]],
        ]);
    }

    /**
     * The rule that is most likely to be broken quietly, asserted over every
     * type at once: whatever a chart puts on the wire, it is not a colour.
     */
    public function test_no_type_emits_a_colour_literal(): void
    {
        foreach ($this->oneOfEach() as $type => $spec) {
            $this->assertDoesNotMatchRegularExpression(
                '/#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})\b/i',
                $spec->json(),
                "The [{$type}] payload carries a colour literal. Charts take their colours from the tokens at draw time."
            );
        }
    }

    public function test_every_type_serialises_to_valid_json_carrying_its_own_type(): void
    {
        foreach ($this->oneOfEach() as $type => $spec) {
            $decoded = json_decode($spec->json(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame($type, $decoded['type']);
            $this->assertArrayHasKey('rows', $decoded);
            $this->assertArrayHasKey('format', $decoded);
            $this->assertArrayHasKey('axisFormat', $decoded);
        }
    }

    public function test_daily_bars_keeps_the_quiet_flag_that_dims_a_weekend(): void
    {
        $rows = ChartSpec::make('daily-bars', self::DAILY, ['format' => 'n'])->payload()['rows'];

        $this->assertTrue($rows[0]['quiet']);
        $this->assertFalse($rows[2]['quiet']);
        $this->assertSame(160998.0, $rows[0]['value']);
    }

    public function test_a_non_numeric_value_survives_as_null_rather_than_becoming_a_zero(): void
    {
        $payload = ChartSpec::make('daily-bars', [
            ['label' => '01', 'value' => 1000],
            ['label' => '02', 'value' => null],
            ['label' => '03', 'value' => ''],
        ], ['format' => 'R'])->payload();

        $this->assertSame(1000.0, $payload['rows'][0]['value']);
        $this->assertNull($payload['rows'][1]['value'], 'A day with no reading is not a day with zero litres.');
        $this->assertNull($payload['rows'][2]['value']);

        // …and it reaches the reader as an em dash, never as R0.00.
        $table = ChartSpec::make('daily-bars', [['label' => '02', 'value' => null]], ['format' => 'R'])->table();
        $this->assertSame('—', $table['rows'][0][1]);
    }

    public function test_axis_format_falls_back_to_the_value_format_when_it_is_not_given(): void
    {
        $payload = ChartSpec::make('donut', [['label' => 'ULP', 'value' => 1]], ['format' => 'Lk'])->payload();

        $this->assertSame(['Lk', null], $payload['format']);
        $this->assertSame(['Lk', null], $payload['axisFormat']);
    }

    public function test_a_format_may_carry_its_decimal_places(): void
    {
        $spec = ChartSpec::make('line', [
            ['name' => 'Actual', 'points' => [['label' => '01', 'value' => 2.0134778]]],
        ], ['format' => ['cpl', 3]]);

        $this->assertSame(['cpl', 3], $spec->payload()['format']);
        $this->assertSame('2.013 c/ℓ', $spec->table()['rows'][0][1]);
    }

    public function test_an_empty_series_is_empty_and_a_line_with_no_points_is_too(): void
    {
        $this->assertTrue(ChartSpec::make('daily-bars', [])->isEmpty());
        $this->assertTrue(ChartSpec::make('line', [['name' => 'Actual', 'points' => []]])->isEmpty());
        $this->assertFalse(ChartSpec::make('line', [['name' => 'Actual', 'points' => [['label' => '1', 'value' => 1]]]])->isEmpty());
    }

    /**
     * A waterfall's axis is zoomed to the range the steps occupy, and a
     * truncated axis that does not say so is a lie. The caller cannot switch
     * the sentence off, only add to it.
     */
    public function test_a_bridge_always_declares_that_its_axis_is_zoomed(): void
    {
        $spec = ChartSpec::make('bridge', self::BRIDGE, ['format' => 'Rk']);
        $this->assertStringContainsString('does not start at zero', (string) $spec->caption());

        $withOwn = ChartSpec::make('bridge', self::BRIDGE, ['format' => 'Rk', 'caption' => 'Group consolidated.']);
        $this->assertStringContainsString('Group consolidated.', (string) $withOwn->caption());
        $this->assertStringContainsString('does not start at zero', (string) $withOwn->caption());

        $this->assertNull(ChartSpec::make('daily-bars', self::DAILY)->caption());
    }

    public function test_the_bridge_table_carries_the_running_total_the_chart_only_implies(): void
    {
        $table = ChartSpec::make('bridge', self::BRIDGE, ['format' => 'Rk'])->table();

        $this->assertSame(['Step', 'Effect', 'Running total'], $table['columns']);

        // A total bar has no step of its own — the em dash says so rather than
        // repeating the value in both columns.
        $this->assertSame(['Budget GP', '—', 'R25.53m'], $table['rows'][0]);
        // Rk is the tile format, so a small step against a R25m end reads as
        // -R38k. That is the format doing what it is for; a bridge that wants
        // the exact rand passes 'R' instead, which is why format is a prop.
        $this->assertSame('-R38k', $table['rows'][1][1]);

        // The walk closes on the last row. A bridge whose arithmetic does not
        // close is worse than no bridge.
        $this->assertSame('R23.26m', $table['rows'][4][2]);
    }

    public function test_the_donut_table_states_a_share_and_refuses_to_invent_one(): void
    {
        $table = ChartSpec::make('donut', [
            ['label' => 'ULP 95', 'value' => 1920971],
            ['label' => 'Diesel', 'value' => 2618344],
        ], ['format' => 'Lk'])->table();

        $this->assertSame(['Slice', 'Value', 'Share'], $table['columns']);
        $this->assertSame(['ULP 95', '1.92m L', '42.3%'], $table['rows'][0]);
        $this->assertSame('57.7%', $table['rows'][1][2]);

        // Nothing to be a share of. Not 0%.
        $zero = ChartSpec::make('donut', [['label' => 'ULP 95', 'value' => 0]], ['format' => 'Lk'])->table();
        $this->assertSame('—', $zero['rows'][0][2]);
    }

    public function test_the_mix_table_keeps_the_measures_in_the_order_they_are_read(): void
    {
        $table = ChartSpec::make('mix', [
            ['label' => 'Fuel', 'values' => ['Last year' => 118278196.80, 'Budget' => 124739481.58, 'Actual' => 124256504.82]],
        ], ['format' => 'Rk', 'measures' => ['Last year', 'Budget', 'Actual']])->table();

        $this->assertSame(['Profit centre', 'Last year', 'Budget', 'Actual'], $table['columns']);
        $this->assertSame(['Fuel', 'R118.28m', 'R124.74m', 'R124.26m'], $table['rows'][0]);
    }

    public function test_the_line_table_puts_one_column_per_series_against_a_shared_label(): void
    {
        $table = ChartSpec::make('line', [
            ['name' => 'ULP 95', 'points' => [['label' => '01', 'value' => 2.1], ['label' => '02', 'value' => 2.2]]],
            ['name' => 'Diesel', 'points' => [['label' => '01', 'value' => 1.9], ['label' => '02', 'value' => null]]],
        ], ['format' => ['cpl', 2]])->table();

        $this->assertSame(['Period', 'ULP 95', 'Diesel'], $table['columns']);
        $this->assertSame(['01', '2.10 c/ℓ', '1.90 c/ℓ'], $table['rows'][0]);
        $this->assertSame(['02', '2.20 c/ℓ', '—'], $table['rows'][1]);
    }

    public function test_numeric_columns_are_marked_so_they_can_be_right_aligned(): void
    {
        $table = ChartSpec::make('diverging', [['label' => 'Nyala', 'value' => 4.77]], ['format' => ['pct', 2]])->table();

        $this->assertSame(['text', 'num'], $table['aligns']);
        $this->assertSame(['Nyala', '4.77%'], $table['rows'][0]);
    }

    /** @return array<string, ChartSpec> */
    private function oneOfEach(): array
    {
        return [
            'daily-bars' => ChartSpec::make('daily-bars', self::DAILY, ['format' => 'n', 'axisFormat' => 'Lk', 'average' => 7]),
            'line' => ChartSpec::make('line', [
                ['name' => 'Actual', 'points' => [['label' => '01', 'value' => 2.031]]],
            ], ['format' => ['cpl', 3], 'reference' => ['value' => 2.143, 'label' => 'Budget']]),
            'donut' => ChartSpec::make('donut', [['label' => 'ULP 95', 'value' => 1920971]], ['format' => 'Lk']),
            'mix' => ChartSpec::make('mix', [
                ['label' => 'Fuel', 'values' => ['Last year' => 1.0, 'Budget' => 2.0, 'Actual' => 3.0]],
            ], ['format' => 'Rk']),
            'bridge' => ChartSpec::make('bridge', self::BRIDGE, ['format' => 'Rk']),
            'diverging' => ChartSpec::make('diverging', [['label' => 'Nyala', 'value' => 4.77]], ['format' => ['pct', 2]]),
        ];
    }
}
