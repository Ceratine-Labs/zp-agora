<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Every chart type, on one page, with the mockup's own numbers.
 *
 * The point of the gallery is that "does this read in dark", "does this survive
 * 375px" and "does a negative step draw the right way round" are questions you
 * answer by looking, not by reasoning about an option tree. It is registered in
 * local and testing only, alongside /dev/theme, for the same reason that one is:
 * a page enumerating the design invites being read as documentation by people
 * who should be looking at the real screens.
 *
 * **The fixtures are the point of the fixtures.** They are the August 2026
 * consolidation out of docs/reference/agoraretailconsole.html — 31 real daily
 * rows, the five profit centres, the four steps that bridge budgeted gross
 * profit to actual, and 24 sites against budget. Tidy invented numbers would
 * hide exactly the cases this page exists to expose: a bridge whose steps are a
 * fraction of a percent of its ends, a diverging set that is nearly balanced,
 * a donut of two slices, weekends that trade two thirds of a weekday.
 *
 * A chart component never queries anything. This is a controller handing a view
 * props, which is the only way data ever reaches one.
 */
class ChartGalleryController extends Controller
{
    /**
     * Group consolidation, August 2026 month to date, from the mockup.
     *
     * Kept as constants rather than recomputed so the page and the tests agree
     * on what "the right answer" is.
     */
    private const LITRES_ACTUAL = 4539315;

    private const LITRES_BUDGET = 4556879;

    private const FUEL_GP_ACTUAL = 9139810.02;

    private const FUEL_GP_BUDGET = 9765426.19;

    private const GP_ACTUAL = 23261922.32;

    private const GP_BUDGET = 25534614.63;

    private const ULP_LITRES = 1920971;

    private const DIESEL_LITRES = 2618344;

    public function __invoke(): View
    {
        $daily = $this->daily();

        return view('core::dev.charts', [
            'daily' => $daily,
            'dailyLitres' => array_map(fn (array $r) => $r['litres'], $daily),
            'margin' => $daily,
            'marginBudget' => self::FUEL_GP_BUDGET / self::LITRES_BUDGET,
            'mix' => $this->mix(),
            'grades' => $this->grades(),
            'bridge' => $this->bridge(),
            'sites' => $this->sites(),
            'edgeCases' => $this->edgeCases(),
        ]);
    }

    /**
     * 31 days of group fuel: litres dispensed, blended margin, fuel gross profit.
     *
     * @return list<array{date: string, day: string, label: string, weekend: bool, litres: int, cpl: float, gp: int}>
     */
    private function daily(): array
    {
        $rows = [
            ['2026-08-01', 'Sat', 160998, 2.031, 326987],
            ['2026-08-02', 'Sun', 105579, 1.819, 192048],
            ['2026-08-03', 'Mon', 147185, 1.729, 254483],
            ['2026-08-04', 'Tue', 136282, 1.752, 238766],
            ['2026-08-05', 'Wed', 138222, 1.936, 267598],
            ['2026-08-06', 'Thu', 151988, 1.972, 299720],
            ['2026-08-07', 'Fri', 178541, 1.680, 299949],
            ['2026-08-08', 'Sat', 154902, 1.892, 293075],
            ['2026-08-09', 'Sun', 110611, 1.733, 191689],
            ['2026-08-10', 'Mon', 146431, 1.995, 292130],
            ['2026-08-11', 'Tue', 141488, 2.013, 284815],
            ['2026-08-12', 'Wed', 138681, 1.636, 226882],
            ['2026-08-13', 'Thu', 147886, 1.561, 230850],
            ['2026-08-14', 'Fri', 171234, 1.801, 308392],
            ['2026-08-15', 'Sat', 170396, 1.919, 326990],
            ['2026-08-16', 'Sun', 116016, 1.715, 198967],
            ['2026-08-17', 'Mon', 144285, 1.744, 251633],
            ['2026-08-18', 'Tue', 131837, 1.726, 227551],
            ['2026-08-19', 'Wed', 146083, 1.641, 239722],
            ['2026-08-20', 'Thu', 149848, 1.945, 291454],
            ['2026-08-21', 'Fri', 180733, 1.935, 349718],
            ['2026-08-22', 'Sat', 170225, 1.868, 317980],
            ['2026-08-23', 'Sun', 114014, 1.637, 186641],
            ['2026-08-24', 'Mon', 139442, 1.838, 256294],
            ['2026-08-25', 'Tue', 140156, 1.996, 279751],
            ['2026-08-26', 'Wed', 143744, 1.951, 280445],
            ['2026-08-27', 'Thu', 155175, 1.999, 310195],
            ['2026-08-28', 'Fri', 181177, 1.815, 328836],
            ['2026-08-29', 'Sat', 167516, 1.710, 286452],
            ['2026-08-30', 'Sun', 115380, 1.945, 224414],
            ['2026-08-31', 'Mon', 143259, 1.888, 270473],
        ];

        return array_map(fn (array $r) => [
            'date' => $r[0],
            'day' => $r[1],
            // The day of the month is the label. The full date is in the
            // tooltip and the table twin — 31 dates will not fit at 375px.
            'label' => substr($r[0], 8),
            'weekend' => in_array($r[1], ['Sat', 'Sun'], true),
            'litres' => $r[2],
            'cpl' => $r[3],
            'gp' => $r[4],
        ], $rows);
    }

    /**
     * Turnover by profit centre — last year, budget, actual, in reading order.
     *
     * @return list<array{label: string, values: array<string, float>}>
     */
    private function mix(): array
    {
        $rows = [
            ['Fuel', 118278196.80, 124739481.58, 124256504.82],
            ['Convenience shop', 17062229.90, 18268569.95, 17968272.13],
            ['OK Department', 22533207.37, 23030423.56, 22727846.44],
            ['QSR / Franchise', 7009773.21, 7267605.69, 7088861.92],
            ['Liquor', 4039991.53, 4029380.65, 4159341.71],
        ];

        return array_map(fn (array $r) => [
            'label' => $r[0],
            'values' => ['Last year' => $r[1], 'Budget' => $r[2], 'Actual' => $r[3]],
        ], $rows);
    }

    /**
     * The grade split — two slices, which is the honest shape of it.
     *
     * @return list<array{label: string, value: int}>
     */
    private function grades(): array
    {
        return [
            ['label' => 'ULP 95', 'value' => self::ULP_LITRES],
            ['label' => 'Diesel', 'value' => self::DIESEL_LITRES],
        ];
    }

    /**
     * Budgeted gross profit bridged to actual.
     *
     * Fuel is split into the two effects that are separately actionable — the
     * litres against budget valued at the budgeted margin, and the margin
     * against budget on the litres actually sold. Everything non-fuel is one
     * step, because at group level it moves together.
     *
     * The steps sum to the ends exactly; that is asserted in the tests, because
     * a bridge whose arithmetic does not close is worse than no bridge.
     *
     * @return list<array{label: string, value: float, total: bool, note: string}>
     */
    private function bridge(): array
    {
        $volume = (self::LITRES_ACTUAL - self::LITRES_BUDGET) * (self::FUEL_GP_BUDGET / self::LITRES_BUDGET);
        $margin = (self::FUEL_GP_ACTUAL / self::LITRES_ACTUAL - self::FUEL_GP_BUDGET / self::LITRES_BUDGET) * self::LITRES_ACTUAL;
        $other = (self::GP_ACTUAL - self::FUEL_GP_ACTUAL) - (self::GP_BUDGET - self::FUEL_GP_BUDGET);

        return [
            ['label' => 'Budget GP', 'value' => self::GP_BUDGET, 'total' => true, 'note' => 'FY2027 phased budget'],
            ['label' => 'Fuel volume', 'value' => $volume, 'total' => false, 'note' => 'Litres against budget, at budget margin'],
            ['label' => 'Fuel margin', 'value' => $margin, 'total' => false, 'note' => 'Margin against budget, on actual litres'],
            ['label' => 'Shop, OK, QSR, liquor', 'value' => $other, 'total' => false, 'note' => 'Every non-fuel profit centre'],
            ['label' => 'Actual GP', 'value' => self::GP_ACTUAL, 'total' => true, 'note' => 'As loaded'],
        ];
    }

    /**
     * Every trading site's turnover against budget, as a percentage.
     *
     * Sorted, because a diverging chart that is not sorted is a bar chart with
     * some negatives in it. Deliberately a set that straddles zero — the
     * one-sided case is in the edge cases below.
     *
     * @return list<array{label: string, value: float}>
     */
    private function sites(): array
    {
        $rows = [
            ['Engen Bethlehem One Plus', 6.36], ['Esikhawini OK Liquor', 4.79],
            ['Nyala One Stop', 4.77], ['Baobab Inn', 4.31],
            ['Bullion Convenience Centre', 3.24], ['Inkwazi Convenience Centre', 2.89],
            ['Wimpy Ladysmith', 2.10], ['Ngwelezane OK', 0.79],
            ['Caltex Ulundi', 0.77], ['Total Hluhluwe', 0.05],
            ['Esikhawini Convenience', 0.01], ['Teds Convenience Centre', -0.11],
            ['Nseleni Ok', -0.16], ['Pongola Convenience Centre', -0.25],
            ['Elephant Coast', -1.79], ['Theku Plaza (Cygnisol)', -2.43],
            ['Nseleni Convenience Centre', -4.00], ['Total Mkuze', -4.30],
            ['Ngwenya Service Station', -4.38], ['Baobab Convenience Centre', -4.90],
            ['Engen Manguzi', -5.25], ['Munchies', -5.56],
            ['Ngwelezane Convenience Centre', -5.72], ['Captains Convenience Centre', -6.82],
        ];

        return array_map(fn (array $r) => ['label' => $r[0], 'value' => $r[1]], $rows);
    }

    /**
     * The cases that break a hand-rolled SVG, on the page rather than only in a
     * test — the four are asserted in tests/Feature/Charts, but a picture of a
     * single point sitting in the middle of its box is what tells you the
     * arithmetic did the right thing rather than merely not dividing by zero.
     *
     * @return array<string, array{blurb: string, values: list<mixed>}>
     */
    private function edgeCases(): array
    {
        return [
            'A flat series' => [
                'blurb' => 'Every reading identical. Drawn through the vertical centre, not along the floor — a flat month is not a collapse to zero.',
                'values' => array_fill(0, 12, 141000),
            ],
            'A single point' => [
                'blurb' => 'One reading. The naive x step divides by (n − 1); this one is a dot in the middle.',
                'values' => [141000],
            ],
            'A series with a negative' => [
                'blurb' => 'The baseline is the series minimum, not zero, so the shape survives. A sparkline has no zero line.',
                'values' => [4, 9, -3, 6, -8, 2, 11, -1, 7, 3],
            ],
            'An empty series' => [
                'blurb' => 'Nothing to draw. An em dash, because a missing trend is missing — not flat, and not zero.',
                'values' => [],
            ],
            'A series with a gap' => [
                'blurb' => 'A day nobody read the meter. The line breaks rather than crossing it, and the area wash is withheld because it would have no honest boundary.',
                'values' => [120, 131, 128, null, null, 139, 144, 141, 152],
            ],
        ];
    }
}
