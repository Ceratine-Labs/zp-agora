<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Format;
use Illuminate\Contracts\View\View;
use Modules\Core\Support\ComponentCatalogue;

/**
 * The one development surface: the component library, the theme it is built
 * from, and the number formats underneath both.
 *
 * Served at /dev/components and, still, at /dev/theme. One controller, one
 * view, two URLs — deliberately not two galleries. `tests/e2e/format.spec.js`
 * loads /dev/theme five times and is the only guard on `App\Support\Format`
 * agreeing character for character with `resources/js/format.js`; retiring
 * that URL would mean editing that guard in a session that cannot run a
 * browser to prove the edit is clean.
 *
 * Everything on the page is rendered rather than described, because "does this
 * read in dark at 375px" is a question answered by looking. And it carries the
 * parity table: each formatting case shows what PHP produced, and the page's
 * own JavaScript fills in what the twin produces from the same input. Two
 * separate test suites would each pass while disagreeing with each other.
 *
 * **A component never queries the database** (plan §3.8), so every figure
 * below is a fixture held here. They are deliberately not tidy: a null, a
 * negative, an empty list, a step that leads nowhere, a movement too small to
 * be a trend. A gallery of well-behaved data proves only that the happy path
 * draws.
 */
class StyleguideController extends Controller
{
    /** The four token families, in the order the design sheet lists them. */
    private const TOKENS = [
        'Surfaces' => ['paper', 'surface', 'surface-2', 'surface-3'],
        'Ink' => ['ink', 'ink-2', 'muted', 'line', 'line-soft'],
        'Brand' => ['brand', 'brand-soft', 'brand-ink', 'sand'],
        'Series' => ['s1', 's2', 's3', 's4', 's5'],
        'Status' => ['good', 'warn', 'serious', 'crit'],
        'Status backgrounds' => ['good-bg', 'warn-bg', 'serious-bg', 'crit-bg'],
        'Status ink' => ['good-ink', 'warn-ink', 'serious-ink', 'crit-ink'],
        'Chrome' => ['chrome', 'chrome-2', 'chrome-3', 'chrome-ink', 'chrome-muted'],
        'Chart' => ['grid', 'axis'],
    ];

    public function __invoke(): View
    {
        return view('core::dev.styleguide', [
            // Validated, not trusted. The cookie is deliberately unencrypted
            // (it is written by JavaScript and carries no secret), so it can
            // hold anything at all — and <x-app-shell> already checked it
            // while this page did not, stamping the document with whatever
            // arrived. Three states, and only three.
            'theme' => in_array(request()->cookie('agora_theme'), ['light', 'dark'], true)
                ? request()->cookie('agora_theme')
                : null,
            'tokens' => self::TOKENS,
            'cases' => $this->formatCases(),
            'catalogue' => ComponentCatalogue::all(),
            'groups' => ComponentCatalogue::GROUPS,
            'pending' => ComponentCatalogue::pending(),
            'fixtures' => $this->fixtures(),
        ]);
    }

    /**
     * Sample data for the gallery. Fixtures, not queries — and every figure
     * goes through App\Support\Format, even here. A gallery that hard-codes
     * "R2.21m" is a gallery that keeps looking right on the day the formatter
     * changes.
     *
     * @return array<string, mixed>
     */
    private function fixtures(): array
    {
        return [
            /*
             * A plain result set, and the two sides of a manual match.
             *
             * Deliberately not tidy — 125 and 200 appear on both sides and are
             * coloured; 300 is bank-only and 400 is deposit-only and neither
             * is. That asymmetry IS the component: a colour means "there is
             * something over there carrying this", and a fixture where
             * everything pairs would prove nothing about it.
             */
            'tableRows' => [
                ['site' => 'Elephant Coast', 'ref' => 'CCB69744', 'declared' => 33320.00, 'banked' => 33320.00],
                ['site' => 'Nyala One Stop', 'ref' => 'CCB69801', 'declared' => 18240.55, 'banked' => 18190.55],
                ['site' => 'Total Mkuze', 'ref' => 'CCB69812', 'declared' => 7415.00, 'banked' => 0.00],
            ],

            'recon' => [
                'bank' => collect([
                    (object) ['BankStatementLineID' => 4101, 'LineDate' => '2026-08-08', 'Description' => 'CF NPF CREDIT ABSA BANK CCB125', 'Amount' => 14937.40, 'PairKey' => '125', 'ColourIndex' => 1],
                    (object) ['BankStatementLineID' => 4102, 'LineDate' => '2026-08-08', 'Description' => 'CF NPF CREDIT ABSA BANK CCB125', 'Amount' => 200.00, 'PairKey' => '125', 'ColourIndex' => 1],
                    (object) ['BankStatementLineID' => 4108, 'LineDate' => '2026-08-09', 'Description' => 'CF NPF CREDIT ABSA BANK CCB200', 'Amount' => 500.00, 'PairKey' => '200', 'ColourIndex' => 2],
                    (object) ['BankStatementLineID' => 4115, 'LineDate' => '2026-08-10', 'Description' => 'CF NPF CREDIT ABSA BANK CCB300', 'Amount' => 900.00, 'PairKey' => '300', 'ColourIndex' => 0],
                    // No rule reached this one at all — the row the manual
                    // workbench exists for.
                    (object) ['BankStatementLineID' => 4121, 'LineDate' => '2026-08-11', 'Description' => 'TRANSFER FROM PETTY CASH', 'Amount' => 1250.00, 'PairKey' => null, 'ColourIndex' => 0],
                ]),
                'mops' => collect([
                    (object) ['SourceId' => null, 'SourceKey' => '125', 'SourceRef' => '125', 'SourceRef2' => '4512001', 'SourceDate' => '2026-08-08', 'Amount' => 15137.40, 'PairKey' => '125', 'ColourIndex' => 1],
                    (object) ['SourceId' => null, 'SourceKey' => '200', 'SourceRef' => '200', 'SourceRef2' => '4512001', 'SourceDate' => '2026-08-09', 'Amount' => 450.00, 'PairKey' => '200', 'ColourIndex' => 2],
                    (object) ['SourceId' => null, 'SourceKey' => '400', 'SourceRef' => '400', 'SourceRef2' => null, 'SourceDate' => '2026-08-11', 'Amount' => 750.00, 'PairKey' => '400', 'ColourIndex' => 0],
                ]),
                'summary' => (object) ['BankTotal' => 17787.40, 'MopsTotal' => 16337.40, 'ColouredKeys' => 2, 'HasCriteria' => 1],
            ],

            'crumb' => [
                ['label' => 'Zululand Retail & Petroleum', 'href' => '/app'],
                ['label' => 'Control', 'href' => '/app'],
                ['label' => 'What does not reconcile'],
            ],

            'workspaces' => ['ho' => 'Head office', 'branch' => 'Branch'],

            'kpis' => [
                [
                    'label' => 'Fuel volume',
                    'value' => Format::lk(847300),
                    'compare' => ['7 days', 'budget '.Format::lk(869000)],
                    'stripe' => 's1',
                ],
                [
                    'label' => 'Shop turnover',
                    'value' => Format::rk(2208437),
                    'compare' => ['against '.Format::rk(2081900)],
                    'tone' => 'good',
                    'stripe' => 'good',
                ],
                [
                    'label' => 'Unallocated Z-reads',
                    'value' => Format::n(12),
                    'compare' => ['oldest 4 days'],
                    'tone' => 'warn',
                    'stripe' => 'warn',
                ],
                [
                    // The case that matters: a figure the system does not have.
                    // It must render as an em dash, never as R0.00.
                    'label' => 'Bank unmatched',
                    'value' => Format::rk(null),
                    'compare' => ['nothing imported for 4 September'],
                    'stripe' => 'crit',
                ],
            ],

            'stats' => [
                ['label' => 'Rows', 'value' => Format::n(394)],
                ['label' => 'Captured', 'value' => Format::r(1284310.55), 'note' => '168 cashups'],
                ['label' => 'Bank', 'value' => Format::r(1284984.10), 'note' => 'ABSA MarkOff'],
                ['label' => 'Difference', 'value' => Format::r(-673.55), 'note' => '31 lines unmatched', 'tone' => 'crit'],
            ],

            'tabs' => [
                ['key' => 'raw', 'label' => 'Raw file', 'count' => Format::n(2411)],
                ['key' => 'stripped', 'label' => 'Stripped file', 'count' => Format::n(2388)],
                ['key' => 'imported', 'label' => 'Imported', 'count' => Format::n(2388)],
            ],

            'regions' => ['' => '<<ALL>>', 'zululand' => 'Zululand', 'north' => 'North Coast'],

            'checklist' => [
                ['title' => 'Import POS files', 'detail' => 'All tills loaded for 31 August', 'done' => true, 'href' => '#g-queues'],
                ['title' => 'Pump readings', 'detail' => 'Mechanical, electronic and POS captured', 'done' => true, 'href' => '#g-queues'],
                ['title' => 'Z-read allocation', 'detail' => '12 Z-reads not allocated to a cashup', 'done' => false, 'href' => '#g-queues'],
                // No href: a step that leads nowhere is not rendered as a link
                // (feature-rules §3.7).
                ['title' => 'Drop safe', 'detail' => '3 bags not collected', 'done' => false],
            ],

            'exceptions' => [
                [
                    'severity' => 'critical',
                    'title' => 'Ngwelezane declared cash is R4 210 under the Z-read total',
                    'detail' => 'Four cashups on 3 September declare less than the tills rang up. The shortfall is concentrated on till 2, which one operator ran for all four shifts.',
                    'category' => 'Banking', 'site' => 'Ngwelezane Convenience Centre', 'age' => '2 days',
                    'owner' => 'Finance',
                    'value' => Format::r(-4210), 'unit' => 'declared vs Z-read',
                    'href' => '#g-queues',
                ],
                [
                    'severity' => 'serious',
                    'title' => 'ULP 95 dip is 1 480 litres below the meter at Caltex Ulundi',
                    'detail' => 'The variance has run in the same direction for six days, which is the shape of a meter drift rather than of a delivery not captured.',
                    'category' => 'Fuel', 'site' => 'Caltex Ulundi', 'age' => '6 days',
                    'owner' => 'Operations',
                    'value' => Format::litres(-1480), 'unit' => 'dip vs meter',
                    'href' => '#g-queues',
                ],
                [
                    'severity' => 'warning',
                    'title' => 'Two POS categories have no GL mapping',
                    'detail' => 'Sales against them land in the suspense account until somebody maps them.',
                    'category' => 'Masters', 'site' => 'Group', 'age' => 'today',
                    'owner' => 'IT & Masters',
                    'value' => Format::n(2), 'unit' => 'categories',
                ],
                [
                    'severity' => 'good',
                    'title' => 'Every overnight load landed',
                    'category' => 'Imports', 'site' => 'Group', 'age' => '05:13',
                    'owner' => 'Operations',
                ],
            ],

            'decisions' => [
                ['who' => 'Thandeka Mkhize', 'what' => 'Staff short', 'detail' => 'Till 2, night shift', 'amount' => Format::r(310.50), 'href' => '#g-queues'],
                ['who' => 'Bidvest Steiner', 'what' => 'Purchase request', 'detail' => 'CA001241 · sanitary services', 'amount' => Format::r(2480), 'href' => '#g-queues'],
                // No amount: not every decision carries a rand value, and the
                // row must not render an empty figure.
                ['who' => 'Sipho Zulu', 'what' => 'Leave request', 'detail' => '12–16 September', 'href' => '#g-queues'],
            ],

            'library' => [
                [
                    'name' => 'Daily Banking Reconciliation',
                    'desc' => 'Every cashup for a day beside what the bank actually received, with the difference and who owns it.',
                    'scope' => 'Branch · one day',
                    'was' => ['BANKREC01', 'Daily Cash Recon', 'Cash Up Summary'],
                    'tags' => [['tone' => 'good', 'label' => 'Runnable'], ['tone' => 'warn', 'label' => 'Feeds a work queue']],
                    'href' => '#g-queues',
                ],
                [
                    'name' => 'Pump Variance by Grade',
                    'desc' => 'Dip against meter against POS, per grade, per day.',
                    'scope' => 'Branch · date range',
                    'was' => ['PUMPVAR'],
                    'tags' => [['tone' => 'neutral', 'label' => 'Renamed on purpose']],
                ],
            ],

            'state' => [
                ['tone' => 'good', 'text' => 'All overnight loads clean', 'href' => '#g-queues'],
                ['tone' => 'warn', 'text' => '12 Z-reads unallocated', 'href' => '#g-queues'],
                ['tone' => 'crit', 'text' => '9 exceptions open', 'href' => '#g-queues'],
            ],

            // Every case the delta has: a rise, a fall, one inverted, one too
            // small to be a trend, and one it does not have at all.
            'deltas' => [
                ['value' => 4.23, 'invert' => false, 'what' => 'Turnover against last year — up is good'],
                ['value' => -4.23, 'invert' => false, 'what' => 'Turnover against last year — down is not'],
                ['value' => 4.23, 'invert' => true, 'what' => 'Shrinkage against last month — up is bad, so invert'],
                ['value' => 0.02, 'invert' => false, 'what' => 'Under 0.05 reads flat and carries no arrow'],
                ['value' => null, 'invert' => false, 'what' => 'No comparable period — an em dash, not a zero'],
            ],

            'sql' => "CREATE OR ALTER PROCEDURE agora.usp_Cash_GridDailyBanking\n"
                ."    @BranchIds  NVARCHAR(MAX) = NULL,   -- CSV of branch ids; NULL = every branch in scope\n"
                ."    @DateFrom   DATE          = NULL,\n"
                ."    @DateTo     DATE          = NULL\n"
                ."AS\n"
                ."BEGIN\n"
                ."    SET NOCOUNT ON;\n\n"
                ."    -- Result set 1: the page of rows.\n"
                ."    -- Result set 2: one row, (TotalRows BIGINT), so the grid can say\n"
                ."    --               \"showing 50 of 12 480\".\n"
                .'END',
        ];
    }

    /**
     * The formatting cases, with the PHP answer already computed.
     *
     * Deliberately includes the awkward ones: a negative, a zero, a null, a
     * value on each side of every threshold, and a movement small enough to be
     * flat. A parity table of tidy numbers proves very little.
     *
     * @return array<int, array{fn: string, args: array<int, mixed>, php: string}>
     */
    private function formatCases(): array
    {
        $cases = [
            ['n', [1234567.891, 2]],
            ['n', [0, 0]],
            ['n', [-42.5, 1]],
            ['n', [null, 0]],
            ['R', [1234.5]],
            ['R', [-1234.5]],
            ['R', [0]],
            ['R', [null]],
            ['Rk', [2208437]],
            ['Rk', [999]],
            ['Rk', [1000]],
            ['Rk', [999999]],
            ['Rk', [1000000]],
            ['Rk', [-2208437]],
            ['Rk', [1500000000]],
            ['Lk', [1240000]],
            ['Lk', [847300]],
            ['Lk', [312]],
            ['litres', [12480.5]],
            ['pct', [12.44]],
            ['pct', [-3.06, 2]],
            ['cpl', [175.25]],
            ['delta', [4.23]],
            ['delta', [-4.23]],
            ['delta', [0.02]],
            ['delta', [0]],
            ['delta', [null]],
        ];

        return array_map(fn (array $case) => [
            'fn' => $case[0],
            'args' => $case[1],
            'php' => Format::{$case[0]}(...$case[1]),
        ], $cases);
    }
}
