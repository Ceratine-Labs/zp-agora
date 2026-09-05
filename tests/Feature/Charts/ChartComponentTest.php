<?php

namespace Tests\Feature\Charts;

use App\Support\Chart\ChartSpec;
use Tests\TestCase;

/**
 * What `<x-chart>` actually puts on the page.
 *
 * ChartSpecTest proves the payload is right. This proves the component emits
 * it, in a holder the JavaScript will find, with the empty state and the table
 * twin where they belong — and, over the whole gallery, that no chart on a real
 * page carries a colour literal.
 *
 * What it deliberately does NOT claim: that the colours are right in either
 * theme, or that a chart re-lays itself out when its container narrows. Both
 * need a browser. tests/e2e/charts.spec.js is written for that and is Ryan's to
 * run.
 *
 * Read-only. The gallery route is registered in local and testing only and is
 * outside the auth stack, exactly like /dev/theme.
 */
class ChartComponentTest extends TestCase
{
    private const DAILY = [
        ['label' => '01', 'value' => 160998, 'quiet' => true],
        ['label' => '02', 'value' => 105579, 'quiet' => true],
        ['label' => '03', 'value' => 147185],
    ];

    public function test_a_chart_emits_a_holder_carrying_its_payload(): void
    {
        $html = (string) $this->blade(
            '<x-chart type="daily-bars" :series="$rows" :options="$options" title="Fuel volume" />',
            ['rows' => self::DAILY, 'options' => ['format' => 'n', 'axisFormat' => 'Lk', 'name' => 'Litres']]
        );

        $this->assertStringContainsString('class="chart-figure chart-daily-bars"', $html);
        $this->assertStringContainsString('data-chart=', $html);
        $this->assertStringContainsString('role="img"', $html);
        $this->assertStringContainsString('chart-skeleton', $html);

        $payload = $this->payloadFrom($html);
        $this->assertSame('daily-bars', $payload['type']);
        $this->assertCount(3, $payload['rows']);
        $this->assertSame('Litres', $payload['name']);
    }

    /**
     * The property that makes the sign-in screen cheap: no rows, no holder, so
     * `charts()` finds nothing and the 900 KB dynamic import never happens.
     */
    public function test_a_chart_with_no_rows_emits_an_empty_state_and_no_holder_at_all(): void
    {
        $html = (string) $this->blade(
            '<x-chart type="daily-bars" :series="[]" empty="No fuel movement for this branch." />'
        );

        $this->assertStringContainsString('No fuel movement for this branch.', $html);
        $this->assertStringContainsString('empty-state', $html);
        $this->assertStringNotContainsString('data-chart=', $html);
        $this->assertStringNotContainsString('chart-skeleton', $html);
    }

    public function test_every_chart_carries_a_table_of_the_same_figures(): void
    {
        $html = (string) $this->blade(
            '<x-chart type="donut" :series="$rows" :options="$options" />',
            [
                'rows' => [['label' => 'ULP 95', 'value' => 1920971], ['label' => 'Diesel', 'value' => 2618344]],
                'options' => ['format' => 'Lk'],
            ]
        );

        $this->assertStringContainsString('chart-table', $html);
        $this->assertStringContainsString('The figures behind this chart', $html);

        // Formatted through App\Support\Format, not number_format: a space
        // groups thousands and the volumes are shortened.
        $this->assertStringContainsString('1.92m L', $html);
        $this->assertStringContainsString('2.62m L', $html);
        $this->assertStringContainsString('42.3%', $html);
    }

    public function test_the_table_twin_can_be_suppressed_where_the_screen_already_carries_one(): void
    {
        $html = (string) $this->blade(
            '<x-chart type="donut" :series="$rows" :table="false" />',
            ['rows' => [['label' => 'ULP 95', 'value' => 1]]]
        );

        $this->assertStringNotContainsString('chart-table', $html);
    }

    public function test_a_bridge_prints_the_sentence_that_makes_its_truncated_axis_honest(): void
    {
        $html = (string) $this->blade(
            '<x-chart type="bridge" :series="$rows" :options="$options" />',
            [
                'rows' => [
                    ['label' => 'Budget GP', 'value' => 25534614.63, 'total' => true],
                    ['label' => 'Fuel margin', 'value' => -587976.39],
                    ['label' => 'Actual GP', 'value' => 24946638.24, 'total' => true],
                ],
                'options' => ['format' => 'Rk'],
            ]
        );

        $this->assertStringContainsString('chart-caption', $html);
        $this->assertStringContainsString('does not start at zero', $html);
    }

    public function test_a_chart_says_what_it_plots_rather_than_that_it_is_a_chart(): void
    {
        // SVG has no alt text, and "chart" is not a description.
        $html = (string) $this->blade(
            '<x-chart type="diverging" :series="$rows" :options="$options" />',
            [
                'rows' => [['label' => 'Nyala', 'value' => 4.77]],
                'options' => ['format' => ['pct', 2], 'label' => 'Turnover against budget by site'],
            ]
        );

        $this->assertStringContainsString('aria-label="Turnover against budget by site"', $html);
    }

    // ------------------------------------------------------------- gallery

    public function test_the_gallery_renders_every_type(): void
    {
        $response = $this->get('/dev/charts');

        $response->assertOk();

        foreach (['daily-bars', 'line', 'donut', 'mix', 'bridge', 'diverging'] as $type) {
            $response->assertSee('chart-'.$type, false);
        }

        // The sparkline and the mini bar are on the same page and are NOT
        // ApexCharts: they are server-rendered SVG in the markup itself.
        $response->assertSee('class="spark spark-s1"', false);
        $response->assertSee('mini-bar tone-good', false);
    }

    /**
     * The rule, asserted on a real page rather than on a unit.
     *
     * Every chart on the gallery goes out with token references and no colour
     * values. If a future chart type hard-codes one, this fails on the page it
     * would have shipped on.
     */
    public function test_no_chart_on_the_gallery_page_carries_a_colour_literal(): void
    {
        $html = $this->get('/dev/charts')->assertOk()->getContent();

        preg_match_all('/data-chart="([^"]*)"/', (string) $html, $matches);

        $this->assertGreaterThanOrEqual(6, count($matches[1]), 'The gallery should carry one holder per ApexCharts type.');

        foreach ($matches[1] as $index => $payload) {
            $decoded = html_entity_decode($payload, ENT_QUOTES);

            $this->assertDoesNotMatchRegularExpression(
                '/#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})\b/i',
                $decoded,
                "Chart holder #{$index} on /dev/charts carries a colour literal."
            );

            // And it is valid JSON, or chart.js would draw the "could not be
            // drawn" message instead of a chart.
            $this->assertIsArray(json_decode($decoded, true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function test_the_gallery_charts_all_declare_a_known_format(): void
    {
        $html = (string) $this->get('/dev/charts')->assertOk()->getContent();

        preg_match_all('/data-chart="([^"]*)"/', $html, $matches);

        foreach ($matches[1] as $payload) {
            $decoded = json_decode(html_entity_decode($payload, ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);

            $this->assertContains($decoded['format'][0], ChartSpec::FORMATS);
            $this->assertContains($decoded['axisFormat'][0], ChartSpec::FORMATS);
        }
    }

    /** @return array<string, mixed> */
    private function payloadFrom(string $html): array
    {
        preg_match('/data-chart="([^"]*)"/', $html, $match);

        $this->assertNotEmpty($match, 'The chart emitted no data-chart attribute.');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(html_entity_decode($match[1], ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
