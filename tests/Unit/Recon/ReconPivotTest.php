<?php

namespace Tests\Unit\Recon;

use App\Exceptions\AgoraProcException;
use App\Grid\GridColumn;
use App\Support\Format;
use App\Support\ProcedureService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Modules\Recon\Services\ReconDashboardService;
use Modules\Recon\Services\ReconPivot;
use RuntimeException;
use Tests\TestCase;

/**
 * The bank recon pivot's arithmetic, statuses, glyphs, rows, links and
 * periods (16 Nov 2026), on fixtures shaped like the live sites the build
 * spec names: Teds (no bank feed), Theku Plaza and Elephant Coast (PumpIT
 * stopped), Nseleni Ok (backlog), Total Hluhluwe (current, short), a
 * transit-only site, a clear one and an unused one.
 *
 * No database: ReconPivot is pure arithmetic, and the service's cache is
 * tested with a fake procedure layer and a fixed ledger.
 */
class ReconPivotTest extends TestCase
{
    private const AS_AT = '2026-11-16';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_keys(config('database.connections')) as $connection) {
            DB::connection($connection)->beforeExecuting(function (): void {
                throw new RuntimeException('The pivot must not query the database.');
            });
        }
        config(['cache.default' => 'array']);
    }

    /* ================================================================ status */

    public function test_every_status_rule_on_sites_shaped_like_live(): void
    {
        $pivot = $this->pivot();

        $this->assertSame('Bank feed', $pivot->statusOf(30, 'ABSA'), 'Teds: deposits waiting, no bank line ever.');
        $this->assertSame('Bank feed', $pivot->statusOf(30, 'FNB'));
        $this->assertSame('Bank feed', $pivot->statusOf(30, 'CashMachine'));
        $this->assertSame('Bank feed', $pivot->statusOf(30));
        $this->assertSame('Clear', $pivot->statusOf(30, 'SmartATM'), 'An area nobody uses is clear, not a problem.');

        $this->assertSame('Not declared', $pivot->statusOf(7, 'SmartATM'), 'Theku Plaza: PumpIT stopped in May 2024, bank lines continue.');
        $this->assertSame('Not declared', $pivot->statusOf(18, 'FNB'), 'Elephant Coast: stopped wins even under the tolerance.');
        $this->assertSame('Not declared', $pivot->statusOf(12), 'Ngwelezane OK: mothballed, the bank still receives.');
        $this->assertSame('Backlog', $pivot->statusOf(11), 'Nseleni Ok: only old items remain.');
        $this->assertSame('Not in bank', $pivot->statusOf(26, 'ABSA'), 'Total Hluhluwe ABSA: R50 000 declared and not banked.');
        $this->assertSame('To match', $pivot->statusOf(26, 'FNB'), 'Total Hluhluwe FNB: current on both sides, within tolerance.');
        $this->assertSame('Backlog', $pivot->statusOf(26, 'CashMachine'));
        $this->assertSame('Not in bank', $pivot->statusOf(26), 'A site takes its worst area.');
        $this->assertSame('In transit', $pivot->statusOf(5), 'Every deposit in transit, no bank line.');
        $this->assertSame('Clear', $pivot->statusOf(6), 'Used and clear.');
        $this->assertSame('Clear', $pivot->statusOf(16), 'Never used.');

        // Stopped bank side with deposits beyond transit reads Not in bank.
        $f = $this->facts(bankStopped: true);
        $m = ReconPivot::measures(null, $this->norm(['Age365' => [10, 1000.0]], 'PumpIT'));
        $this->assertSame('Not in bank', ReconPivot::status($m, $f));
        // A PumpIT side never used, with bank money, reads Not declared.
        $m = ReconPivot::measures($this->norm(['AgeOver' => [3, 900.0]], 'Bank'), null);
        $this->assertSame('Not declared', ReconPivot::status($m, $this->facts(pumpitNever: true)));
        $this->assertSame('Backlog', ReconPivot::status($m, $this->facts()), 'Old bank money alone, feeds fine, is backlog.');
    }

    public function test_transit_excuses_pumpit_money_only_so_early_bank_lines_are_not_not_declared(): void
    {
        // B ≤ P but B > P′: the bank line for a transit-window deposit arrived early.
        $bank = $this->norm(['Age7' => [9, 900.0]], 'Bank');
        $pumpit = $this->norm(['Age7' => [10, 1000.0]], 'PumpIT', transitRows: 3, transitAmount: 300.0);
        $m = ReconPivot::measures($bank, $pumpit);

        $this->assertSame(0, $m['over'], 'over = max(0, B − P), never B − P′.');
        $this->assertSame(0, $m['over30']);
        $this->assertSame(0, $m['short']);
        $this->assertSame(10000, $m['wait'], 'R100 of the transit is still waiting for its bank line.');
        $this->assertSame('To match', ReconPivot::status($m, $this->facts()));

        // And on screen: no amber on the bank cell.
        $pivot = $this->pivot($this->figures([
            self::site(26, 'Total Hluhluwe'),
        ], [
            self::out(26, 'Total Hluhluwe', 'FNB', 'Bank', ['Age7' => [9, 90000.0]]),
            self::out(26, 'Total Hluhluwe', 'FNB', 'PumpIT', ['Age7' => [10, 100000.0]], transitRows: 3, transitAmount: 30000.0),
        ]), $this->feeds([], [26]));
        $row = $this->row($pivot->areas('amount'), 26);
        $this->assertNull($row['cells']['FNB.Bank']['tone']);
        $this->assertNull($row['cells']['FNB.PumpIT']['tone']);
        $this->assertSame('To match', $row['status']['label']);
    }

    public function test_the_tolerance_boundary_is_max_r100_or_one_percent_and_exclusive(): void
    {
        $at = fn (float $bank, float $pumpit) => ReconPivot::status(ReconPivot::measures(
            $this->norm(['Age7' => [5, $bank]], 'Bank'),
            $this->norm(['Age7' => [5, $pumpit]], 'PumpIT'),
            ['tolerance_rand' => 100, 'tolerance_pct' => 1],
        ), $this->facts());

        // R10 000 a side: 1% is R100, the same as the floor.
        $this->assertSame('To match', $at(9900.00, 10000.00), 'Exactly the tolerance is inside it.');
        $this->assertSame('Not in bank', $at(9899.99, 10000.00), 'A cent beyond it is not.');
        $this->assertSame('To match', $at(10000.00, 9900.00));
        $this->assertSame('Not declared', $at(10000.00, 9899.99));

        // R1m a side: 1% (R10 000) is the tolerance, not R100.
        $this->assertSame('To match', $at(990000.00, 1000000.00));
        $this->assertSame('Not in bank', $at(989999.99, 1000000.00));

        // Below R10 000 the R100 floor holds.
        $this->assertSame('To match', $at(400.00, 500.00));
        $this->assertSame('Not in bank', $at(399.99, 500.00));

        // The tolerance colours; it never moves a sum.
        $m = ReconPivot::measures($this->norm(['Age7' => [5, 9950.00]], 'Bank'), $this->norm(['Age7' => [5, 10000.00]], 'PumpIT'));
        $this->assertSame(5000, $m['short']);
        $this->assertSame(995000, $m['both']);
    }

    public function test_the_identity_holds_to_the_cent_including_negative_rows(): void
    {
        // A negative bank line, and a negative deposit inside a transit window.
        $cases = [
            [['Age30' => [1, -7265.20]], []],
            [['Age7' => [2, -3181.99]], ['Age7' => [4, 100.00]]],
            [['Age7' => [3, 500.00]], ['Age7' => [4, 480.00], 'AgeOver' => [1, -11.32]]],
            [[], ['Age7' => [2, -11.32]]],
            [['Age365' => [7, 1234.56]], ['Age30' => [9, 1234.57]]],
        ];
        foreach ($cases as [$b, $p]) {
            foreach ([0.0, -11.32, 50.00] as $transit) {
                $m = ReconPivot::measures($b ? $this->norm($b, 'Bank') : null,
                    $p ? $this->norm($p, 'PumpIT', transitRows: 1, transitAmount: $transit) : null);
                $this->assertSame($m['B'], $m['both'] + $m['over'], 'B = both + over');
                $this->assertSame($m['P'], $m['both'] + $m['short'] + $m['wait'], 'P = both + short + wait');
            }
        }

        // Over the whole estate fixture, which carries -R7 265.20 and -R11.32.
        $t = $this->pivot()->totals();
        $this->assertSame(self::c($t['bank_amount']), self::c($t['both']) + self::c($t['over']));
        $this->assertSame(self::c($t['pumpit_amount']), self::c($t['both']) + self::c($t['short']) + self::c($t['wait']));
        $this->assertSame(515574780, self::c($t['bank_amount']), 'R5 155 747.80: the fixture\'s bank lines, signs kept.');
        $this->assertSame(587048868, self::c($t['pumpit_amount']));
    }

    public function test_a_net_debit_stays_on_its_own_side_and_never_reads_as_not_in_bank(): void
    {
        // A bank reversal with nothing declared is the bank's own item, not a missing deposit.
        $m = ReconPivot::measures($this->norm(['Age7' => [1, -6000.00]], 'Bank'), null);
        $this->assertSame([0, -600000, 0, 0], [$m['both'], $m['over'], $m['short'], $m['short30']]);
        $this->assertSame('To match', ReconPivot::status($m, $this->facts()), 'not Not in bank');

        // It does not offset deposits either: R4 000 declared is still not in the bank.
        $m = ReconPivot::measures($this->norm(['Age7' => [1, -5000.00]], 'Bank'), $this->norm(['Age30' => [2, 4000.00]], 'PumpIT'));
        $this->assertSame([0, -500000, 400000, 0], [$m['both'], $m['over'], $m['short'], $m['wait']]);
        $this->assertSame([400000, 0], [$m['short30'], $m['over30']], 'never more than PumpIT holds');

        // A net negative deposit stays on PumpIT's side and does not widen the bank's gap.
        $m = ReconPivot::measures($this->norm(['Age7' => [1, 5000.00]], 'Bank'), $this->norm(['Age30' => [1, -1000.00]], 'PumpIT'));
        $this->assertSame([0, 500000, -100000, 0], [$m['both'], $m['over'], $m['short'], $m['wait']]);
        $this->assertSame(500000, $m['over30']);

        // A negative transit waits for nothing.
        $m = ReconPivot::measures(null, $this->norm(['Age7' => [3, 800.00]], 'PumpIT', transitRows: 1, transitAmount: -200.0));
        $this->assertSame([0, 80000, 0], [$m['both'], $m['short'], $m['wait']]);

        // With every amount at zero or above, the §1.4 arithmetic is unchanged.
        foreach ([[900.0, 1000.0, 300.0], [1000.0, 900.0, 0.0], [0.0, 500.0, 500.0], [250.0, 1000.0, 900.0]] as [$b, $p, $t]) {
            $m = ReconPivot::measures($this->norm(['Age7' => [1, $b]], 'Bank'), $this->norm(['Age7' => [1, $p]], 'PumpIT', transitRows: 1, transitAmount: $t));
            [$B, $P, $T] = [(int) round($b * 100), (int) round($p * 100), (int) round($t * 100)];
            $this->assertSame([min($B, $P), max(0, $B - $P), max(0, $P - $T - $B)], [$m['both'], $m['over'], $m['short']]);
            $this->assertSame($P - min($B, $P) - max(0, $P - $T - $B), $m['wait']);
        }
    }

    /* ================================================================ glyphs */

    public function test_glyphs_say_clear_never_stopped_unused_and_flag_a_stopped_side_with_rows(): void
    {
        $areas = $this->pivot()->areas('amount');

        $teds = $this->row($areas, 30)['cells'];
        $this->assertSame(['never', 'never', ''], [$teds['ABSA.Bank']['state'], $teds['ABSA.Bank']['text'], $teds['ABSA.Bank']['sort']]);
        $this->assertNull($teds['ABSA.Bank']['href'], 'A glyph is never a link.');
        $this->assertStringContainsString('214 unclassified bank lines; they may be these', $teds['ABSA.Bank']['tip']);
        $this->assertStringContainsString('R100 000.00 of deposits are waiting', $teds['ABSA.Bank']['tip']);
        $this->assertSame('crit', $teds['ABSA.PumpIT']['tone'], 'The deposits with no bank feed are red.');
        $this->assertSame(['·', 'unused'], [$teds['SmartATM.Bank']['text'], $teds['SmartATM.Bank']['state']]);
        $this->assertSame(['·', 'unused'], [$teds['SmartATM.PumpIT']['text'], $teds['SmartATM.PumpIT']['state']]);
        $this->assertSame([null, ReconPivot::UNUSED], [$teds['SmartATM.Bank']['tip'], $teds['SmartATM.Bank']['aria']],
            '· says what the key says: no tooltip to repeat it, so no tab stop, and a short name.');
        $html = Blade::render('<table><tr><x-recon::cell :cell="$cell" /></tr></table>', ['cell' => $teds['SmartATM.Bank']]);
        $this->assertStringContainsString('class="rp-unused"', $html);
        $this->assertStringContainsString('role="img"', $html, 'A named span needs a role.');
        $this->assertStringNotContainsString('tabindex', $html);
        $this->assertStringNotContainsString('data-tip', $html);

        $theku = $this->row($areas, 7)['cells'];
        $this->assertStringContainsString('rp-flag', $theku['SmartATM.PumpIT']['class'], 'Stopped, but still has rows: a corner flag.');
        $this->assertSame('crit', $theku['SmartATM.PumpIT']['tone']);
        $this->assertStringContainsString('PumpIT: nothing since 27 May 2024 while bank lines continue', $theku['SmartATM.PumpIT']['tip']);
        // Never colour alone: the sentence names the gap the wash stands for, and the stopped side.
        $this->assertStringContainsString('. Not in bank R850 000.00. PumpIT: nothing since 27 May 2024 while bank lines continue.',
            (string) $theku['SmartATM.PumpIT']['aria']);
        $this->assertStringNotContainsString('rp-flag', $theku['SmartATM.Bank']['class']);
        $this->assertNull($theku['SmartATM.Bank']['tone'], 'Over is 0 at Theku: the bank is less than PumpIT.');

        $elephant = $this->row($areas, 18)['cells'];
        $this->assertSame(['stopped', 'stopped', ''], [$elephant['FNB.PumpIT']['text'], $elephant['FNB.PumpIT']['state'], $elephant['FNB.PumpIT']['sort']]);
        $this->assertNull($elephant['FNB.Bank']['tone'], 'R50 is inside the tolerance: no wash, yet the status is Not declared.');

        $baobab = $this->row($areas, 5)['cells'];
        $this->assertSame(['~R30k', 'transit'], [$baobab['CashBags.PumpIT']['text'], $baobab['CashBags.PumpIT']['state']]);
        $this->assertNull($baobab['CashBags.PumpIT']['tone']);
        $this->assertSame(['✓', 'clear', '0'], [$baobab['CashBags.Bank']['text'], $baobab['CashBags.Bank']['state'], $baobab['CashBags.Bank']['sort']]);

        $nyala = $this->row($areas, 6)['cells'];
        $this->assertSame(['✓', '✓'], [$nyala['ABSA.Bank']['text'], $nyala['ABSA.PumpIT']['text']]);
        $this->assertSame(['·', '·'], [$nyala['FNB.Bank']['text'], $nyala['FNB.PumpIT']['text']]);

        $ngwelezane = $this->row($areas, 12);
        $this->assertSame('warn', $ngwelezane['cells']['ABSA.Bank']['tone']);
        $this->assertSame('stopped', $ngwelezane['cells']['ABSA.PumpIT']['text']);
    }

    public function test_with_the_feed_history_unknown_nothing_is_drawn_as_clear_or_blamed_on_a_feed(): void
    {
        $pivot = $this->pivot(feeds: null);
        $areas = $pivot->areas('amount');

        foreach ($areas['rows'] as $row) {
            foreach ($row['cells'] as $key => $cell) {
                $this->assertNotSame('✓', $cell['text'], "Row {$row['id']} {$key}: an unknown is never a tick.");
                $this->assertNotContains($cell['state'], ['never', 'stopped', 'unused', 'clear'], "Row {$row['id']} {$key}");
                $this->assertStringNotContainsString('rp-flag', $cell['class']);
            }
            $this->assertNotContains('manual only', array_column($row['chips'], 'label'));
        }

        $teds = $this->row($areas, 30)['cells'];
        $this->assertSame(['—', 'unknown', ''], [$teds['ABSA.Bank']['text'], $teds['ABSA.Bank']['state'], $teds['ABSA.Bank']['sort']]);
        $this->assertNotSame('Bank feed', $pivot->statusOf(30), 'Without the history nobody can say the feed is missing.');
        $this->assertSame('Not in bank', $pivot->statusOf(30), 'The deposits are still short beyond the tolerance.');
        $this->assertSame('—', $this->row($areas, 16)['cells']['ABSA.PumpIT']['text'], 'Unused reads unknown, not ·.');
        $this->assertSame('Not declared', $pivot->statusOf(12), 'Tolerance still decides; only the feed clauses fall away.');
        $this->assertSame('To match', $pivot->statusOf(18), 'R50 under the tolerance, PumpIT stopped unknown: current, not Not declared.');
        $this->assertSame(['info'], array_column(array_filter($pivot->notices(), fn ($n) => $n['tone'] === 'info'), 'tone'));
        $this->assertStringContainsString('cells with nothing outstanding show —', $pivot->notices()[0]['lines'][0]);
    }

    /* ================================================================== rows */

    public function test_a_clear_trading_site_is_listed_and_an_entity_goes_to_the_entity_row(): void
    {
        $areas = $this->pivot()->areas('amount', $this->link());
        $ids = array_column($areas['rows'], 'id');

        $this->assertContains(6, $ids, 'Nyala: trading, nothing outstanding, still a row.');
        $this->assertContains(16, $ids, 'Inkwazi: trading, never used, still a row.');
        $this->assertNotContains(1, $ids);
        $this->assertNotContains(2, $ids);
        $this->assertSame(9, $areas['count']);

        $entities = $areas['entities'];
        $this->assertSame([1, 2], $entities['ids']);
        $this->assertSame('2 other entities · unclassified lines only', $entities['label']);
        $this->assertStringContainsString('AJLG Properties and Zululand Petroleum', $entities['tip']);
        $this->assertSame('AJLG Properties and Zululand Petroleum', $entities['detail'], 'Said beside the label too: the tooltip is aria-hidden.');
        $this->assertSame('15', $entities['cells']['Unclassified']['sort']);
        $this->assertSame('', $entities['cells']['ABSA.Bank']['text'], 'Every other cell of the entity row is blank.');
        $this->assertEquals(['view' => 'items', 'area' => 'Unclassified', 'branches' => ['1', '2']], $this->params($entities['cells']['Unclassified']['href']));

        // Chips: mothballed, manual only, and the question a mothballed site with bank money raises.
        $chips = fn (int $id) => array_column($this->row($areas, $id)['chips'], 'tip', 'label');
        $this->assertSame(['not trading'], array_keys($chips(11)));
        $this->assertStringNotContainsString('whose money', $chips(11)['not trading']);
        $this->assertStringContainsString('The bank received R200 000.00 in the last 30 days: whose money is this?', $chips(12)['not trading']);
        $this->assertStringContainsString('Mothballed', $chips(12)['not trading']);
        $this->assertSame(['manual only'], array_keys($chips(30)));
        $this->assertStringContainsString('No extraction rule for ABSA, FNB and Cash machine: automatic recon refuses (NO_CRITERIA); match by hand, 500 rows a side.',
            $chips(30)['manual only'], 'Teds: the areas it uses; Smart ATM and cash bags it never touches.');
        $this->assertSame([], $this->row($areas, 6)['chips'], 'Rules everywhere: no chip.');

        // What the tooltips say is also plain text beside the chip, for a screen reader.
        $details = fn (int $id) => array_column($this->row($areas, $id)['chips'], 'detail', 'label');
        $this->assertSame('Branch status: Mothballed. Pivot status: '.$this->row($areas, 12)['status']['label']
            .'. The bank received R200 000.00 in the last 30 days: whose money is this?', $details(12)['not trading']);
        $this->assertStringStartsWith('No extraction rule for ABSA, FNB and Cash machine', $details(30)['manual only']);
        $this->assertSame('Nothing outstanding in this window.', $this->row($areas, 6)['status']['detail']);
        $this->assertStringContainsString('ABSA: Not declared', $this->row($areas, 12)['status']['detail']);

        // Bullion: no rule anywhere and no area ever used — every area is named.
        $bullion = $this->pivot($this->figures([self::site(29, 'Bullion Convenience Centre')], [
            self::out(29, 'Bullion Convenience Centre', 'Unclassified', 'Bank', ['AgeOver' => [20, -400.00]]),
        ]), $this->feeds([], [], [29]))->areas();
        $this->assertSame('Clear', $bullion['rows'][0]['status']['label']);
        $this->assertStringContainsString('No extraction rule in any area', $bullion['rows'][0]['chips'][0]['tip']);
        $this->assertSame('20', $bullion['rows'][0]['cells']['Unclassified']['sort'], 'A trading site with only Unclassified lines is a row, not an entity.');

        // An admin entity picked by hand is a row, with its chip, and there is no foot.
        $one = $this->pivot(selected: [2])->areas('amount');
        $this->assertSame([2], array_column($one['rows'], 'id'));
        $this->assertSame(['not trading', 'admin entity'], array_column($one['rows'][0]['chips'], 'label'));
        $this->assertTrue($one['single']);
        $this->assertNull($one['total']);
        $this->assertNull($one['entities']);
    }

    public function test_the_default_order_is_status_then_current_gap_then_gross_then_name(): void
    {
        $this->assertSame([26, 12, 7, 18, 30, 11, 5, 16, 6], array_column($this->pivot()->areas()['rows'], 'id'));

        // Same status, same current gap: the bigger gross first; then the name.
        $pivot = $this->pivot($this->figures([
            self::site(40, 'Alpha'), self::site(41, 'Bravo'), self::site(42, 'Charlie'),
        ], [
            self::out(40, 'Alpha', 'FNB', 'Bank', ['Age7' => [1, 100.00]]),
            self::out(40, 'Alpha', 'FNB', 'PumpIT', ['Age7' => [1, 100.00]]),
            self::out(41, 'Bravo', 'FNB', 'Bank', ['Age7' => [1, 900.00]]),
            self::out(41, 'Bravo', 'FNB', 'PumpIT', ['Age7' => [1, 900.00]]),
            self::out(42, 'Charlie', 'FNB', 'Bank', ['Age7' => [1, 100.00]]),
            self::out(42, 'Charlie', 'FNB', 'PumpIT', ['Age7' => [1, 100.00]]),
        ]), $this->feeds([], [40, 41, 42]));
        $this->assertSame([41, 40, 42], array_column($pivot->areas()['rows'], 'id'));
    }

    /* =========================================================== tiles, foot */

    public function test_the_tiles_add_up_and_the_totals_row_equals_them(): void
    {
        $pivot = $this->pivot();
        $t = $pivot->totals();
        $tiles = array_column($pivot->kpis($this->link()), null, 'key');

        $this->assertSame(['bank', 'pumpit', 'short', 'over', 'both', 'unclassified'], array_keys($tiles));
        $this->assertSame('3 343', $tiles['bank']['value']);
        $this->assertSame('R5.16m · oldest 10 Jun 2023', $tiles['bank']['note']);
        $this->assertSame('7 257', $tiles['pumpit']['value']);
        $this->assertStringEndsWith(' in transit, not yet in the bank', $tiles['pumpit']['note']);
        $this->assertSame(['crit', 'crit'], [$tiles['short']['tone'], $tiles['short']['stripe']]);
        $this->assertSame(['warn', 'warn'], [$tiles['over']['tone'], $tiles['over']['stripe']]);
        $this->assertSame('each side · probably the same money, not yet matched', $tiles['both']['note']);
        $this->assertSame('232', $tiles['unclassified']['value']);
        $this->assertSame('in R14k · out -R20k · in no total', $tiles['unclassified']['note']);
        $this->assertEquals(['view' => 'items', 'side' => 'Bank'], $this->params($tiles['bank']['href']));
        // Tiles 3 to 5 open the Difference lens on the whole scope, whose totals
        // row is their figures; never a Status pill, whose sites do not add up
        // to the tile.
        foreach (['short', 'over', 'both'] as $key) {
            $this->assertEquals(['view' => 'pivot', 'lens' => 'difference'], $this->params($tiles[$key]['href']), $key);
        }

        $amount = $pivot->areas('amount', $this->link())['total']['cells'];
        $this->assertSame(self::c($t['bank_amount']), self::c((float) $amount['AllBank']['sort']), 'All bank = tile 1');
        $this->assertSame(self::c($t['pumpit_amount']), self::c((float) $amount['AllPumpIT']['sort']), 'All PumpIT = tile 2');
        $count = $pivot->areas('count')['total']['cells'];
        $this->assertSame([(string) $t['bank_rows'], (string) $t['pumpit_rows']], [$count['AllBank']['sort'], $count['AllPumpIT']['sort']]);
        $this->assertSame((string) $t['unclassified_rows'], $amount['Unclassified']['sort'], 'Unclassified, body and entities = tile 6');

        $difference = $pivot->areas('difference', $this->link())['total']['cells'];
        $this->assertSame(self::c($t['short']), self::c((float) $difference['NotInBank']['sort']), 'Not in bank = tile 3');
        $this->assertSame(self::c($t['over']), self::c((float) $difference['NotDeclared']['sort']), 'Not declared = tile 4');
        $this->assertSame(self::c($t['both']), self::c((float) $difference['Both']['sort']), 'On both sides = tile 5');
        $this->assertSame(self::c($t['wait']), self::c((float) $difference['Transit']['sort']), 'In transit = tile 2\'s note');
        $this->assertSame(self::c($t['short30']), self::c((float) $difference['NotInBank30']['sort']));
        $this->assertSame(self::c($t['unclassified_in']), self::c((float) $difference['Credits']['sort']));

        // Each column of the totals row is the sum of the rows above it.
        $areas = $pivot->areas('amount');
        foreach (array_keys($amount) as $key) {
            if ($key === 'Over90' || $key === 'Unclassified') {
                continue;
            }
            $sum = array_sum(array_map(fn ($row) => self::c((float) $row['cells'][$key]['sort']), $areas['rows']));
            $this->assertSame($sum, self::c((float) $amount[$key]['sort']), $key);
        }

        $info = $pivot->info();
        $this->assertSame('Bank R5 155 747.80 = on both sides '.Format::r($t['both']).' + not declared '.Format::r($t['over']), $info['identity'][0]);
        $this->assertSame('Top 5 hold '.$this->top5($areas).' of the amount', $areas['subtitle']);
    }

    public function test_links_open_exactly_the_rows_behind_each_figure(): void
    {
        $pivot = $this->pivot(from: '2026-10-01', selected: []);
        $scope = ['from' => '2026-10-01'];
        $areas = $pivot->areas('amount', $this->link($scope));
        $hluhluwe = $this->row($areas, 26);

        $this->assertEquals(['view' => 'sites', 'branches' => ['26'], 'from' => '2026-10-01'], $this->params($hluhluwe['href']));
        $this->assertEquals(['view' => 'items', 'branches' => ['26'], 'area' => 'FNB', 'side' => 'PumpIT', 'from' => '2026-10-01'],
            $this->params($hluhluwe['cells']['FNB.PumpIT']['href']));
        // Label in name: the link reads R1.00m, its sentence R1 000 500.00, so the name starts with what it shows.
        $this->assertStringStartsWith('R1.00m — Total Hluhluwe, FNB, PumpIT: R1 000 500.00 in 900 deposits', (string) $hluhluwe['cells']['FNB.PumpIT']['aria']);
        $this->assertEquals(['view' => 'items', 'branches' => ['26'], 'side' => 'Bank', 'from' => '2026-10-01'],
            $this->params($hluhluwe['cells']['AllBank']['href']));
        $this->assertEquals(['view' => 'items', 'branches' => ['26'], 'area' => 'Unclassified', 'from' => '2026-10-01'],
            $this->params($hluhluwe['cells']['Unclassified']['href']));

        $nseleni = $this->row($pivot->areas('amount', $this->link($scope)), 11)['cells']['Over90'];
        $this->assertEquals(['view' => 'items', 'branches' => ['11'], 'to' => '2026-08-17', 'from' => '2026-10-01'], $this->params($nseleni['href']),
            'Over 90 days: to asAt − 91, the window\'s From kept.');

        // The totals row: the selection, and with none, every granted site.
        $total = $areas['total']['cells'];
        $this->assertEquals(['view' => 'items', 'area' => 'ABSA', 'side' => 'Bank', 'from' => '2026-10-01'], $this->params($total['ABSA.Bank']['href']));
        $picked = $this->pivot(selected: [26, 12])->areas('amount', $this->link())['total']['cells'];
        $this->assertEquals(['view' => 'items', 'branches' => ['26', '12'], 'area' => 'ABSA', 'side' => 'Bank'], $this->params($picked['ABSA.Bank']['href']));

        // Difference: per area both sides; the 30-day and transit figures from their dates.
        $difference = $this->row($this->pivot()->areas('difference', $this->link()), 26)['cells'];
        $this->assertEquals(['view' => 'items', 'branches' => ['26'], 'area' => 'ABSA'], $this->params($difference['ABSA']['href']));
        $this->assertEquals(['view' => 'items', 'branches' => ['26'], 'from' => '2026-10-17'], $this->params($difference['NotInBank30']['href']));
        // In transit: the one area that carries it, from that area's own window.
        $this->assertEquals(['view' => 'items', 'branches' => ['26'], 'side' => 'PumpIT', 'area' => 'FNB', 'from' => '2026-11-13'],
            $this->params($difference['Transit']['href']));
        $this->assertSame('500.00', $difference['Transit']['sort']);
        $this->assertStringContainsString('FNB: R500.00 not yet in the bank, of R100 000.00 in transit from 13 Nov', (string) $difference['Transit']['tip']);
        $this->assertStringContainsString('The link opens the 50 FNB deposits in transit, R100 000.00.', (string) $difference['Transit']['tip']);
        $this->assertSame('-R50k', $difference['ABSA']['text']);
        $this->assertSame('crit', $difference['ABSA']['tone']);
        $this->assertSame(['R0', null, '0.00'], [$difference['FNB']['text'], $difference['FNB']['tone'], $difference['FNB']['sort']],
            'R1m bank against R1.0005m PumpIT with R100k in transit: neither short nor over.');
        $this->assertStringContainsString('In transit, not yet in the bank R500.00 of R100 000.00 dated from 13 Nov', (string) $difference['FNB']['tip'],
            'wait = P − both − short, beside T.');

        // Transit in two areas (Baobab's cash bags from 9 Nov, Hluhluwe's FNB
        // from 13 Nov): no one `from` opens both, so the figure has no link
        // and its tooltip gives each area.
        $transit = $this->pivot()->areas('difference', $this->link())['total']['cells']['Transit'];
        $this->assertSame('30500.00', $transit['sort']);
        $this->assertNull($transit['href']);
        $this->assertStringContainsString('FNB: R500.00 not yet in the bank, of R100 000.00 in transit from 13 Nov', (string) $transit['tip']);
        $this->assertStringContainsString('Cash bags: R30 000.00 not yet in the bank, of R30 000.00 in transit from 9 Nov', (string) $transit['tip']);

        // Transit in two areas, but the bank already covers one: the other
        // holds all of the figure, so the link opens its deposits.
        $delta = $this->pivot($this->figures([self::site(40, 'Delta')], [
            self::out(40, 'Delta', 'FNB', 'PumpIT', ['Age7' => [10, 1000.00]], transitRows: 10, transitAmount: 1000.00),
            self::out(40, 'Delta', 'ABSA', 'PumpIT', ['Age7' => [2, 200.00]], transitRows: 2, transitAmount: 200.00),
            self::out(40, 'Delta', 'ABSA', 'Bank', ['Age7' => [2, 200.00]]),
        ]), null, [40])->areas('difference', $this->link())['rows'][0]['cells']['Transit'];
        $this->assertSame('1000.00', $delta['sort']);
        $this->assertEquals(['view' => 'items', 'branches' => ['40'], 'side' => 'PumpIT', 'area' => 'FNB', 'from' => '2026-11-13'], $this->params($delta['href']));
        $this->assertStringContainsString('ABSA: R0.00 not yet in the bank, of R200.00 in transit from 13 Nov', (string) $delta['tip']);
        $this->assertSame(['+R503k', 'warn'], [$this->row($this->pivot()->areas('difference'), 12)['cells']['ABSA']['text'],
            $this->row($this->pivot()->areas('difference'), 12)['cells']['ABSA']['tone']], 'Over is amber, with an explicit +.');
    }

    /* ================================================================= dates */

    public function test_day_columns_cells_links_and_the_total_equal_the_areas_cell(): void
    {
        $pivot = $this->datesPivot('2026-11-03', '2026-11-16');
        $dates = $pivot->dates('amount', 'PumpIT', null, $this->link());

        $this->assertSame('day', $dates['scheme']);
        $figures = array_values(array_filter($dates['columns'], fn ($c) => $c['kind'] === 'figure'));
        $this->assertCount(15, $figures, '14 days and the Total.');
        $this->assertSame(['d:2026-11-03', 'Tue 3', 'Tuesday 3 November 2026'], [$figures[0]['key'], $figures[0]['label'], substr((string) $figures[0]['title'], 0, 23)]);
        $this->assertFalse($figures[0]['transit']);
        $this->assertTrue($figures[13]['transit'], '16 Nov ends after the earliest TransitFrom (9 Nov for cash bags).');
        $this->assertStringContainsString('in transit from 13 Nov (ABSA, FNB, Cash machine, Smart ATM) · 9 Nov (Cash bags)', (string) $figures[13]['title']);
        $this->assertStringContainsString('rp-transit-col', $figures[13]['class']);

        // A bank line is never in transit: on the Bank side no heading is dashed or says so.
        foreach ($pivot->dates('amount', 'Bank')['columns'] as $c) {
            $this->assertFalse($c['transit'] ?? false, (string) $c['key']);
            $this->assertStringNotContainsString('rp-transit-col', (string) $c['class']);
            $this->assertStringNotContainsString('in transit', (string) $c['title']);
        }

        $row = $this->row($dates, 26)['cells'];
        $this->assertSame(['R1k', 'recent'], [$row['d:2026-11-03']['text'], $row['d:2026-11-03']['state']], '3 Nov is in the last 30 days, beyond transit.');
        $this->assertSame('', $row['d:2026-11-06']['text'], 'Nothing dated then: blank, unlinked.');
        $this->assertNull($row['d:2026-11-06']['href']);
        $this->assertSame(['~R400', 'transit'], [$row['d:2026-11-14']['text'], $row['d:2026-11-14']['state']]);
        $this->assertSame('recent', $row['d:2026-11-10']['state']);
        $this->assertEquals(['view' => 'items', 'branches' => ['26'], 'side' => 'PumpIT', 'from' => '2026-11-10', 'to' => '2026-11-10'],
            $this->params($row['d:2026-11-10']['href']));

        // The Total is the row's sum, and the row's sum is the Areas cells for that side.
        $this->assertSame('3100.00', $row['Total']['sort']);
        $areas = $this->row($pivot->areas('amount'), 26)['cells'];
        $this->assertSame(self::c((float) $areas['FNB.PumpIT']['sort']) + self::c((float) $areas['ABSA.PumpIT']['sort']), self::c((float) $row['Total']['sort']));
        $fnb = $this->row($pivot->dates('amount', 'PumpIT', 'FNB'), 26)['cells'];
        $this->assertSame($areas['FNB.PumpIT']['sort'], $fnb['Total']['sort']);
        $bank = $this->row($pivot->dates('count', 'Bank', 'FNB', $this->link()), 26)['cells'];
        $this->assertSame($areas['FNB.Bank']['sort'], $this->row($pivot->dates('amount', 'Bank', 'FNB'), 26)['cells']['Total']['sort']);
        $this->assertSame(['2', '1'], [$bank['d:2026-11-04']['sort'], $bank['d:2026-11-11']['sort']]);
        $this->assertEquals(['view' => 'items', 'branches' => ['26'], 'side' => 'Bank', 'area' => 'FNB'], $this->params($bank['Total']['href']));

        // The foot counts the sites with something dated in each period; no entity row.
        $this->assertNull($dates['entities']);
        $foot = $dates['total']['cells'];
        $this->assertStringContainsString('1 of 2 sites have PumpIT outstanding dated in this period', (string) $foot['d:2026-11-03']['tip']);
        $this->assertSame('3100.00', $foot['Total']['sort']);
        $this->assertEquals(['view' => 'items', 'side' => 'PumpIT', 'from' => '2026-11-03', 'to' => '2026-11-03'], $this->params($foot['d:2026-11-03']['href']));
    }

    public function test_the_week_roll_up_equals_the_days(): void
    {
        $days = $this->row($this->datesPivot('2026-11-03', '2026-11-16')->dates('amount', 'PumpIT'), 26)['cells'];
        $dates = $this->datesPivot('2026-09-01', '2026-11-16')->dates('amount', 'PumpIT');
        $weeks = $this->row($dates, 26)['cells'];

        $this->assertSame('week', $dates['scheme']);
        $columns = array_column(array_filter($dates['columns'], fn ($c) => $c['kind'] === 'figure'), null, 'key');
        $this->assertSame(['w/c 31 Aug', '1 Sep – 6 Sep 2026'], [$columns['w:2026-08-31']['label'], $columns['w:2026-08-31']['title']],
            'The first week is clipped to the window.');
        $this->assertCount(13, $columns, '12 weeks and the Total.');

        $sum = fn (array $keys) => array_sum(array_map(fn ($k) => self::c((float) ($days[$k]['sort'] ?: 0)), $keys));
        $this->assertSame($sum(['d:2026-11-03', 'd:2026-11-04', 'd:2026-11-05', 'd:2026-11-06', 'd:2026-11-07', 'd:2026-11-08']),
            self::c((float) $weeks['w:2026-11-02']['sort']));
        $this->assertSame($sum(['d:2026-11-09', 'd:2026-11-10', 'd:2026-11-11', 'd:2026-11-12', 'd:2026-11-13', 'd:2026-11-14', 'd:2026-11-15']),
            self::c((float) $weeks['w:2026-11-09']['sort']));
        $this->assertSame($days['d:2026-11-16']['sort'], $weeks['w:2026-11-16']['sort']);
        $this->assertSame($days['Total']['sort'], $weeks['Total']['sort']);
        $this->assertSame('', $weeks['w:2026-09-07']['sort']);
    }

    public function test_a_week_or_month_that_only_reaches_into_the_last_30_days_is_not_marked_recent(): void
    {
        // Five deposits dated 13 Oct: 34 days before 16 Nov, so Backlog. The
        // week of 12 Oct and the month of October both reach 17 Oct, the first
        // of the last 30 days, and neither holds anything dated there.
        $out = self::out(5, 'Baobab Inn', 'FNB', 'PumpIT', ['Age90' => [5, 500.00]], oldest: '2026-10-13', newest: '2026-10-13');
        foreach ([['2026-08-17', 'day', '2026-10-13', 'w:2026-10-12'], [null, 'month', '2026-10-01', 'm:2026-10']] as [$from, $grain, $start, $key]) {
            $figures = $this->figures([self::site(5, 'Baobab Inn')], [$out], [self::period(5, 'FNB', 'PumpIT', $start, 5, 500.00)], $grain);
            $pivot = $this->pivot($figures, null, [5], $from);
            $cell = $this->row($pivot->dates('amount', 'PumpIT'), 5)['cells'][$key];

            $this->assertSame('Backlog', $pivot->statusOf(5));
            $this->assertSame('R500', $cell['text'], $key);
            $this->assertNull($cell['state'], "{$key} reaches into the last 30 days, but nothing in it is dated there.");
            $this->assertStringNotContainsString('last 30 days', (string) $cell['tip']);
        }

        // The same week with one deposit dated 17 Oct is recent: that one is.
        $figures = $this->figures([self::site(5, 'Baobab Inn')], [$out], [
            self::period(5, 'FNB', 'PumpIT', '2026-10-13', 5, 500.00),
            self::period(5, 'FNB', 'PumpIT', '2026-10-17', 1, 100.00),
        ], 'day');
        $week = $this->row($this->pivot($figures, null, [5], '2026-08-17')->dates('amount', 'PumpIT'), 5)['cells']['w:2026-10-12'];
        $this->assertSame(['R600', 'recent'], [$week['text'], $week['state']]);
    }

    public function test_months_fold_into_years_oldest_on_the_left_only_years_that_hold_data(): void
    {
        $periods = [
            self::period(26, 'FNB', 'PumpIT', '2023-06-01', 5, 500.00),
            self::period(26, 'FNB', 'PumpIT', '2024-02-01', 3, 300.00),
            self::period(26, 'FNB', 'PumpIT', '2025-03-01', 2, 200.00),
            self::period(26, 'FNB', 'PumpIT', '2025-11-01', 4, 400.00),
            self::period(26, 'FNB', 'PumpIT', '2025-12-01', 6, 600.00),
            self::period(26, 'FNB', 'PumpIT', '2026-11-01', 1, 100.00),
        ];
        $figures = $this->figures([self::site(26, 'Total Hluhluwe')], [
            self::out(26, 'Total Hluhluwe', 'FNB', 'PumpIT', ['Age7' => [1, 100.00], 'Age365' => [6, 600.00], 'AgeOver' => [14, 1400.00]]),
        ], $periods, 'month');
        $pivot = $this->pivot($figures, $this->feeds([], [26]));
        $dates = $pivot->dates('amount', 'PumpIT', 'FNB');

        $this->assertSame('month', $dates['scheme']);
        $figure = array_values(array_filter($dates['columns'], fn ($c) => $c['kind'] === 'figure'));
        $this->assertSame(['2023', '2024', 'Jan–Nov 2025', 'Dec 25', 'Jan 26'], array_slice(array_column($figure, 'label'), 0, 5));
        $this->assertSame(['Nov 26', 'Total'], array_slice(array_column($figure, 'label'), -2));
        $this->assertCount(16, $figure, '3 years, 12 months and the Total.');
        $this->assertSame(['2023', '2025', '2026-11', 'Total'], [$figure[0]['export'], $figure[2]['export'], $figure[14]['export'], $figure[15]['export']]);

        $cells = $this->row($dates, 26)['cells'];
        $this->assertSame(['500.00', '300.00', '600.00', '600.00', '100.00'],
            [$cells['y:2023']['sort'], $cells['y:2024']['sort'], $cells['y:2025']['sort'], $cells['m:2025-12']['sort'], $cells['m:2026-11']['sort']]);
        $this->assertSame('2100.00', $cells['Total']['sort']);
        $this->assertSame($this->row($pivot->areas(), 26)['cells']['FNB.PumpIT']['sort'], $cells['Total']['sort'], 'Months over all time = set 1.');

        // A side with nothing earlier gets no year columns at all.
        $bank = array_values(array_filter($pivot->dates('amount', 'Bank')['columns'], fn ($c) => $c['kind'] === 'figure'));
        $this->assertSame(['Dec 25', 'Nov 26'], [$bank[0]['label'], $bank[11]['label']]);
        $this->assertCount(13, $bank);
    }

    /* ============================================================ escaping */

    public function test_a_site_name_with_markup_is_escaped_in_data_tip_and_rendered_safely(): void
    {
        $name = 'Te<b>ds & Co';
        $pivot = $this->pivot($this->figures([self::site(30, $name)], [
            self::out(30, $name, 'ABSA', 'PumpIT', ['Age7' => [4, 400.00]]),
        ]), $this->feeds([self::feed(30, 'ABSA', 'PumpIT', '2025-01-01', '2026-11-15', 40)], [], [30]));
        $cell = $this->row($pivot->areas('amount', $this->link()), 30)['cells']['ABSA.PumpIT'];

        $this->assertStringContainsString('<b>Te&lt;b&gt;ds &amp; Co · ABSA · PumpIT</b>', $cell['tip']);
        $this->assertStringNotContainsString('Te<b>ds', $cell['tip']);
        $never = $this->row($pivot->areas('amount'), 30)['cells']['ABSA.Bank']['tip'];
        $this->assertStringContainsString('classified to Te&lt;b&gt;ds &amp; Co for ABSA', $never);

        $html = Blade::render('<table><tr><x-recon::cell :cell="$cell" /></tr></table>', ['cell' => $cell]);
        $this->assertStringContainsString('data-tip="&lt;b&gt;Te&amp;lt;b&amp;gt;ds &amp;amp; Co · ABSA · PumpIT&lt;/b&gt;', $html);
        $this->assertStringContainsString('aria-label="Te&lt;b&gt;ds &amp; Co, ABSA, PumpIT: R400.00 in 4 deposits', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('<span class="tone-value tone-crit">R400</span>', $html);
        $this->assertStringContainsString('data-sort="400.00"', $html);
    }

    /* ========================================================= work window */

    public function test_the_work_window_respects_manual_matchs_500_rows(): void
    {
        $pivot = $this->pivot(from: '2026-10-25');
        $bank = self::out(26, 'Total Hluhluwe', 'FNB', 'Bank', ['Age7' => [100, 400000.00], 'Age30' => [200, 600000.00]]);
        $pumpit = self::out(26, 'Total Hluhluwe', 'FNB', 'PumpIT', ['Age7' => [300, 400500.00], 'Age30' => [600, 600000.00]]);

        $busy = $pivot->workWindow($bank, $pumpit, '2026-10-25', null);
        $this->assertSame(['from' => '2026-11-10', 'to' => '2026-11-16', 'tab' => 'suggest', 'window' => 'week'],
            array_intersect_key($busy, array_flip(['from', 'to', 'tab', 'window'])), '900 PumpIT rows in 30 days: the last 7 days.');
        $this->assertStringContainsString('more than 500 rows a side', $busy['title']);

        $absa = self::out(26, 'Total Hluhluwe', 'ABSA', 'PumpIT', ['Age7' => [20, 2000.00]]);
        $month = $pivot->workWindow(null, $absa, '2026-10-25', null);
        $this->assertSame(['2026-10-25', '2026-11-16', null, 'month'], [$month['from'], $month['to'], $month['tab'], $month['window']],
            'The last 30 days, but never before From; ABSA opens on Manual match.');

        $old = self::out(11, 'Nseleni Ok', 'FNB', 'Bank', ['AgeOver' => [2754, 3000000.00]], oldest: '2023-06-10');
        $older = $pivot->workWindow($old, null, null, null);
        $this->assertSame(['2023-06-10', '2023-07-10', 'oldest'], [$older['from'], $older['to'], $older['window']]);

        $transit = self::out(5, 'Baobab Inn', 'CashBags', 'PumpIT', ['Age7' => [3, 30000.00]], transitRows: 3, transitAmount: 30000.00, oldest: '2026-11-10');
        $this->assertSame('oldest', $pivot->workWindow(null, $transit)['window'], 'Only transit: nothing current beyond it.');

        $this->assertNull($pivot->workWindow(self::out(30, 'Teds', 'Unclassified', 'Bank', ['AgeOver' => [3, 10.00]]), null), 'Agora cannot classify a line.');
        $this->assertNull($pivot->workWindow(null, null));
    }

    /* ============================================================= notices */

    public function test_late_statements_name_the_area_and_where_transit_now_starts(): void
    {
        $figures = $this->figures([self::site(26, 'Total Hluhluwe')], [], [], 'month', self::coverage(['FNB' => '2026-11-12', 'SmartATM' => null]));
        $notices = $this->pivot($figures, $this->feeds([], [26]))->notices();

        $this->assertCount(1, $notices);
        $this->assertSame('warn', $notices[0]['tone']);
        $this->assertSame([
            'FNB statements are loaded to 12 Nov (4 days behind): FNB deposits from 10 Nov count as in transit until they arrive.',
            'No Smart ATM statement lines are loaded: Smart ATM deposits from 13 Nov count as in transit until they arrive.',
        ], $notices[0]['lines']);
        $this->assertSame([], $this->pivot()->notices(), 'Statements to yesterday are on time.');
    }

    /* =============================================================== export */

    public function test_the_export_is_the_table_on_screen_with_glyphs_as_numbers_or_blanks(): void
    {
        $sheet = $this->pivot()->sheet('table', 'areas', 'amount');

        $this->assertSame('Recon pivot areas amount', $sheet['title']);
        $labels = array_map(fn (GridColumn $c) => $c->label, $sheet['columns']);
        $this->assertSame(['Site', 'Site status', 'Status', 'ABSA bank', 'ABSA PumpIT'], array_slice($labels, 0, 5));
        $this->assertSame(['All bank', 'All PumpIT', 'Over 90 days (%)', 'Unclassified bank lines (in no total)'], array_slice($labels, -4));
        $this->assertSame(['money', 'pct', 'number'], [$sheet['columns'][3]->format, $sheet['columns'][15]->format, $sheet['columns'][16]->format]);

        $rows = $sheet['rows']->keyBy('site');
        $this->assertCount(11, $rows, '9 sites, the entity row and the totals row.');
        $this->assertSame('All sites in scope', $sheet['rows']->last()->site);
        $teds = $rows['Teds Convenience Centre'];
        $this->assertSame('Trading · manual only', $teds->site_status);
        $this->assertSame('Bank feed', $teds->status);
        $this->assertNull($teds->c_ABSA_Bank, 'never is empty');
        $this->assertSame(100000.0, $teds->c_ABSA_PumpIT);
        $this->assertNull($teds->c_SmartATM_Bank, '· is empty');
        $this->assertSame(0.0, $rows['Baobab Inn']->c_CashBags_Bank, '✓ is 0');
        $this->assertSame(30000.0, $rows['Baobab Inn']->c_CashBags_PumpIT, 'transit-only exports its amount');
        $this->assertSame('Mothballed', $rows['Nseleni Ok']->site_status);

        $long = $this->pivot()->sheet('long');
        $this->assertSame('Recon pivot long form', $long['title']);
        $this->assertSame(19, $long['rows']->count(), 'One per site × area × side in set 1, Unclassified and entities included.');
        $first = $long['rows']->first();
        $this->assertSame([26, 'Total Hluhluwe', 'ABSA', 'Bank', 'Yes', 'Not in bank'],
            [$first->site_id, $first->site, $first->area, $first->side, $first->rules, $first->status]);
        $loose = $long['rows']->firstWhere('site_id', 1);
        $this->assertSame(['Unclassified', null, null], [$loose->area, $loose->status, $loose->rules]);
        $this->assertSame('No', $long['rows']->firstWhere('site_id', 30)->rules);
    }

    /* ============================================================== service */

    public function test_figures_are_cached_per_scope_and_ledger_refreshed_on_demand_and_refusals_not_kept(): void
    {
        $procedures = new FakeProcedures([
            self::set([['BranchId' => 26, 'ReconArea' => 'FNB', 'Side' => 'Bank', 'Rows' => '1', 'Amount' => '10.00']]),
            self::set([['BranchId' => 26, 'BranchName' => 'Total Hluhluwe']]),
            self::set([['ReconArea' => 'FNB', 'LagDays' => 3, 'LastLineDate' => '2026-11-15', 'TransitFrom' => '2026-11-13']]),
            self::set([]),
        ]);
        $service = new LedgerService($procedures);

        $first = $service->figures([26, 12], [], '2026-11-10', null, self::AS_AT);
        $this->assertSame(1, $procedures->calls);
        $this->assertSame('day', $first['grain']);
        $this->assertSame('day', $procedures->params[0]['Grain']);
        $this->assertSame('12,26', $procedures->params[0]['BranchIds'], 'Sorted: the same selection is the same entry.');
        $this->assertSame(['BranchId' => 26, 'ReconArea' => 'FNB', 'Side' => 'Bank', 'Rows' => '1', 'Amount' => '10.00'], $first['rows'][0]);
        $this->assertIsArray($first['sites'][0], 'Plain arrays: the cache cannot unserialise objects.');

        $service->figures([12, 26], [], '2026-11-10', null, self::AS_AT);
        $this->assertSame(1, $procedures->calls, 'The same scope in another order is the same entry.');

        $service->figures([12, 26], [], '2026-11-10', null, self::AS_AT, fresh: true);
        $this->assertSame(2, $procedures->calls, 'Refresh reads again.');

        $service->ledger = [999, null];
        $service->figures([12, 26], [], '2026-11-10', null, self::AS_AT);
        $this->assertSame(3, $procedures->calls, 'An Agora commit is a new key.');

        $service->ledger = [999, '2026-11-16 08:00:00'];
        $service->figures([12, 26], [], '2026-11-10', null, self::AS_AT);
        $this->assertSame(4, $procedures->calls, 'So is a reversal.');

        $procedures->throw = new AgoraProcException('BAD_GRAIN', 'A period is a day or a month.', 'agora.usp_Recon_DashboardOutstanding');
        try {
            $service->figures([], [], null, null, self::AS_AT);
            $this->fail('A refusal must reach the page.');
        } catch (AgoraProcException $e) {
            $this->assertSame('BAD_GRAIN', $e->code());
        }
        $service->figures([], [], null, null, self::AS_AT);
        $this->assertSame(6, $procedures->calls, 'The refusal was not cached.');
        $this->assertSame('month', end($procedures->params)['Grain']);

        $this->assertSame('day', ReconDashboardService::grain('2026-08-17', null, self::AS_AT), '92 days is days.');
        $this->assertSame('month', ReconDashboardService::grain('2026-08-16', null, self::AS_AT), '93 days is months.');
        $this->assertSame('month', ReconDashboardService::grain('2026-11-20', '2026-11-10', self::AS_AT), 'From after To asks for nothing.');
        $this->assertSame('month', ReconDashboardService::grain(null, '2026-11-10', self::AS_AT));
    }

    public function test_feeds_fail_soft_are_reported_and_never_cached(): void
    {
        Exceptions::fake();
        $procedures = new FakeProcedures([self::set([['BranchId' => 26]]), self::set([['BranchId' => 26, 'ReconArea' => 'FNB', 'Rules' => 1]])]);
        $service = new LedgerService($procedures);

        $procedures->throw = new RuntimeException('timeout');
        $failed = $service->feeds([26], self::AS_AT);
        $this->assertNull($failed);
        Exceptions::assertReported(RuntimeException::class);

        $feeds = $service->feeds([26], self::AS_AT);
        $this->assertSame(2, $procedures->calls, 'The failure was not cached.');
        $this->assertSame([['BranchId' => 26, 'ReconArea' => 'FNB', 'Rules' => 1]], $feeds['rules'] ?? null);
        $this->assertNull($procedures->params[1]['BranchIds'], 'Feeds read the whole grant.');
        $this->assertSame('26', $procedures->params[1]['AllowedBranchIds']);

        $service->feeds([26], self::AS_AT);
        $this->assertSame(2, $procedures->calls, 'Cached for the hour.');

        $procedures->sets = [self::set([])];
        $this->assertNull($service->feeds([26], self::AS_AT, fresh: true), 'One set is half an answer.');
    }

    public function test_shape_counts_oldest_from_classified_rows_and_keeps_cells_as_objects(): void
    {
        $shape = (new ReconDashboardService(new FakeProcedures([])))->shape(collect([
            self::out(30, 'Teds', 'ABSA', 'PumpIT', ['Age7' => [4, 400.00]], oldest: '2026-11-01'),
            self::out(30, 'Teds', 'Unclassified', 'Bank', ['AgeOver' => [214, -5000.00]], oldest: '2015-10-01'),
            self::out(1, 'AJLG', 'Unclassified', 'Bank', ['AgeOver' => [10, -2000.00]], oldest: '2016-01-01'),
        ]));
        $sites = array_column($shape['sites'], null, 'id');

        $this->assertSame(['2026-11-01', 4, 214, 218], [$sites[30]['oldest'], $sites[30]['classified'], $sites[30]['unclassified'], $sites[30]['rows']]);
        $this->assertSame([null, 0, 10], [$sites[1]['oldest'], $sites[1]['classified'], $sites[1]['unclassified']]);
        $this->assertIsObject($sites[30]['cells']['ABSA']['PumpIT']);
        $this->assertSame('4', $sites[30]['cells']['ABSA']['PumpIT']->Rows);
        $this->assertSame('2015-10-01', $shape['unclassified']['oldest']);
        $this->assertSame('2026-11-01', $shape['sides']['PumpIT']['oldest']);
    }

    /* ============================================================ fixtures */

    /**
     * The estate: one site per status the spec names, plus two entities with
     * only Unclassified lines.
     *
     * @param  array<string, mixed>|null  $figures
     * @param  array<string, mixed>|null|false  $feeds  false: the estate's own
     * @param  list<int>  $selected
     */
    private function pivot(?array $figures = null, array|null|false $feeds = false, array $selected = [], ?string $from = null, ?string $to = null): ReconPivot
    {
        $figures ??= $this->estate();
        if ($feeds === false) {
            $feeds = $this->estateFeeds();
        }
        if ($selected !== []) {
            $figures['sites'] = array_values(array_filter($figures['sites'], fn ($s) => in_array((int) $s['BranchId'], $selected, true)));
            $figures['rows'] = array_values(array_filter($figures['rows'], fn ($r) => in_array((int) $r['BranchId'], $selected, true)));
        }

        return new ReconPivot($figures, $feeds, $selected, $from, $to, self::AS_AT,
            ['tolerance_rand' => 100, 'tolerance_pct' => 1, 'statements_behind_days' => 2]);
    }

    /** @return array<string, mixed> */
    private function estate(): array
    {
        return $this->figures([
            self::site(1, 'AJLG Properties', 'Non-trading', false, true, true),
            self::site(2, 'Zululand Petroleum', 'Non-trading', false, true, true),
            self::site(5, 'Baobab Inn'),
            self::site(6, 'Nyala One Stop'),
            self::site(7, 'Theku Plaza (Cygnisol)'),
            self::site(11, 'Nseleni Ok', 'Mothballed', false),
            self::site(12, 'Ngwelezane OK', 'Mothballed', false),
            self::site(16, 'Inkwazi Conveniece Centre'),
            self::site(18, 'Elephant Coast'),
            self::site(26, 'Total Hluhluwe'),
            self::site(30, 'Teds Convenience Centre'),
        ], [
            self::out(1, 'AJLG Properties', 'Unclassified', 'Bank', ['AgeOver' => [10, -2000.00]], creditRows: 2, creditAmount: 500.00),
            self::out(2, 'Zululand Petroleum', 'Unclassified', 'Bank', ['Age90' => [5, 1000.00]], creditRows: 5, creditAmount: 1000.00),
            self::out(5, 'Baobab Inn', 'CashBags', 'PumpIT', ['Age7' => [3, 30000.00]], transitRows: 3, transitAmount: 30000.00, oldest: '2026-11-10'),
            self::out(7, 'Theku Plaza (Cygnisol)', 'SmartATM', 'Bank', ['Age7' => [5, 50000.00], 'Age30' => [10, 100000.00], 'AgeOver' => [55, 500000.00]], oldest: '2024-03-01', newest: '2026-11-08'),
            self::out(7, 'Theku Plaza (Cygnisol)', 'SmartATM', 'PumpIT', ['AgeOver' => [2791, 1500000.00]], oldest: '2024-01-02', newest: '2024-05-27'),
            self::out(11, 'Nseleni Ok', 'FNB', 'Bank', ['AgeOver' => [2754, 3000000.00]], oldest: '2023-06-10', newest: '2025-06-28'),
            self::out(11, 'Nseleni Ok', 'FNB', 'PumpIT', ['AgeOver' => [2542, 2900000.00]], oldest: '2023-06-16', newest: '2025-06-30'),
            self::out(12, 'Ngwelezane OK', 'ABSA', 'Bank', ['Age7' => [20, 50000.00], 'Age30' => [57, 150000.00], 'Age90' => [100, 302963.00]], oldest: '2026-03-05', newest: '2026-11-15'),
            self::out(18, 'Elephant Coast', 'FNB', 'Bank', ['Age7' => [1, 50.00]], oldest: '2026-11-15', newest: '2026-11-15'),
            self::out(26, 'Total Hluhluwe', 'ABSA', 'Bank', ['Age7' => [10, 4000.00], 'Age30' => [30, 6000.00]]),
            self::out(26, 'Total Hluhluwe', 'ABSA', 'PumpIT', ['Age7' => [200, 24000.00], 'Age30' => [300, 36000.00], 'AgeOver' => [1, -11.32]]),
            self::out(26, 'Total Hluhluwe', 'FNB', 'Bank', ['Age7' => [100, 400000.00], 'Age30' => [200, 600000.00]]),
            self::out(26, 'Total Hluhluwe', 'FNB', 'PumpIT', ['Age7' => [300, 400500.00], 'Age30' => [600, 600000.00]], transitRows: 50, transitAmount: 100000.00),
            self::out(26, 'Total Hluhluwe', 'CashMachine', 'Bank', ['Age90' => [1, -7265.20]]),
            self::out(26, 'Total Hluhluwe', 'Unclassified', 'Bank', ['Age7' => [3, 300.00]], creditRows: 3, creditAmount: 300.00),
            self::out(30, 'Teds Convenience Centre', 'ABSA', 'PumpIT', ['Age7' => [50, 10000.00], 'Age30' => [150, 30000.00], 'Age90' => [200, 60000.00]]),
            self::out(30, 'Teds Convenience Centre', 'FNB', 'PumpIT', ['Age7' => [100, 200000.00]]),
            self::out(30, 'Teds Convenience Centre', 'CashMachine', 'PumpIT', ['Age30' => [20, 80000.00]]),
            self::out(30, 'Teds Convenience Centre', 'Unclassified', 'Bank', ['AgeOver' => [214, -5000.00]], creditRows: 20, creditAmount: 12000.00),
        ]);
    }

    /** @return array<string, mixed> */
    private function estateFeeds(): array
    {
        return $this->feeds([
            self::feed(5, 'CashBags', 'Bank', '2024-01-01', '2026-11-15', 100),
            self::feed(5, 'CashBags', 'PumpIT', '2024-01-01', '2026-11-14', 300),
            self::feed(6, 'ABSA', 'Bank', '2024-01-01', '2026-11-15', 500),
            self::feed(6, 'ABSA', 'PumpIT', '2024-01-01', '2026-11-15', 5000),
            self::feed(7, 'SmartATM', 'Bank', '2023-01-01', '2026-11-14', 900),
            self::feed(7, 'SmartATM', 'PumpIT', '2023-01-01', '2024-05-27', 5000),
            self::feed(11, 'FNB', 'Bank', '2023-01-01', '2025-06-28', 3000),
            self::feed(11, 'FNB', 'PumpIT', '2023-01-01', '2025-06-30', 3000),
            self::feed(12, 'ABSA', 'Bank', '2020-01-01', '2026-11-15', 3000),
            self::feed(12, 'ABSA', 'PumpIT', '2020-01-01', '2025-06-30', 9000),
            self::feed(18, 'FNB', 'Bank', '2022-01-01', '2026-11-15', 4000),
            self::feed(18, 'FNB', 'PumpIT', '2022-01-01', '2026-11-10', 8000),
            self::feed(26, 'ABSA', 'Bank', '2022-01-01', '2026-11-15', 900),
            self::feed(26, 'ABSA', 'PumpIT', '2022-01-01', '2026-11-16', 20000),
            self::feed(26, 'FNB', 'Bank', '2022-01-01', '2026-11-15', 9000),
            self::feed(26, 'FNB', 'PumpIT', '2022-01-01', '2026-11-16', 20000),
            self::feed(26, 'CashMachine', 'Bank', '2022-01-01', '2026-09-01', 50),
            self::feed(26, 'CashMachine', 'PumpIT', '2022-01-01', '2026-09-01', 60),
            self::feed(30, 'ABSA', 'PumpIT', '2025-01-01', '2026-11-15', 3000),
            self::feed(30, 'FNB', 'PumpIT', '2025-01-01', '2026-11-16', 3000),
            self::feed(30, 'CashMachine', 'PumpIT', '2025-09-01', '2026-11-01', 300),
        ], [1, 2, 5, 6, 7, 11, 12, 16, 18, 26], [30]);
    }

    /**
     * A day-grain estate for the Dates view: Total Hluhluwe with deposits on
     * five days and a few bank lines; Nyala clear.
     */
    private function datesPivot(string $from, string $to): ReconPivot
    {
        $periods = [
            self::period(26, 'FNB', 'PumpIT', '2026-11-03', 10, 1000.00),
            self::period(26, 'FNB', 'PumpIT', '2026-11-05', 5, 500.00),
            self::period(26, 'FNB', 'PumpIT', '2026-11-10', 7, 700.00),
            self::period(26, 'FNB', 'PumpIT', '2026-11-14', 4, 400.00, 4, 400.00),
            self::period(26, 'FNB', 'PumpIT', '2026-11-16', 2, 200.00, 2, 200.00),
            self::period(26, 'ABSA', 'PumpIT', '2026-11-04', 3, 300.00),
            self::period(26, 'FNB', 'Bank', '2026-11-04', 2, 250.00),
            self::period(26, 'FNB', 'Bank', '2026-11-11', 1, 100.00),
        ];
        $figures = $this->figures([self::site(6, 'Nyala One Stop'), self::site(26, 'Total Hluhluwe')], [
            self::out(26, 'Total Hluhluwe', 'FNB', 'PumpIT', ['Age7' => [13, 1300.00], 'Age30' => [15, 1500.00]], transitRows: 6, transitAmount: 600.00),
            self::out(26, 'Total Hluhluwe', 'ABSA', 'PumpIT', ['Age30' => [3, 300.00]]),
            self::out(26, 'Total Hluhluwe', 'FNB', 'Bank', ['Age7' => [1, 100.00], 'Age30' => [2, 250.00]]),
        ], $periods, 'day');

        return $this->pivot($figures, $this->feeds([], [6, 26]), [], $from, $to);
    }

    /**
     * @param  list<array<string, mixed>>  $sites
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $periods
     * @param  list<array<string, mixed>>|null  $coverage
     * @return array<string, mixed>
     */
    private function figures(array $sites, array $rows, array $periods = [], string $grain = 'month', ?array $coverage = null): array
    {
        return ['read_at' => '2026-11-16 06:41:00', 'grain' => $grain, 'rows' => $rows, 'sites' => $sites,
            'coverage' => $coverage ?? self::coverage(), 'periods' => $periods];
    }

    /**
     * @param  list<array<string, mixed>>  $sides
     * @param  list<int>  $withRules  one rule per area
     * @param  list<int>  $withoutRules  none, anywhere
     * @return array<string, mixed>
     */
    private function feeds(array $sides, array $withRules = [], array $withoutRules = []): array
    {
        $rules = [];
        foreach ([[$withRules, 1], [$withoutRules, 0]] as [$ids, $n]) {
            foreach ($ids as $id) {
                foreach (ReconPivot::AREAS as $area) {
                    $rules[] = ['BranchId' => (string) $id, 'ReconArea' => $area, 'Rules' => (string) $n];
                }
            }
        }

        return ['read_at' => '2026-11-16 06:00:00', 'sides' => $sides, 'rules' => $rules];
    }

    /** @return array<string, mixed> */
    private static function site(int $id, string $name, string $status = 'Trading', bool $trading = true, bool $active = true, bool $admin = false): array
    {
        return ['BranchId' => (string) $id, 'BranchName' => $name, 'Status' => $status,
            'IsTrading' => $trading ? '1' : '0', 'IsActive' => $active ? '1' : '0', 'IsAdminEntity' => $admin ? '1' : '0'];
    }

    /**
     * A set-1 row as the procedure returns it (strings, as PDO gives them).
     *
     * @param  array<string, array{0: int, 1: float}>  $buckets
     * @return array<string, mixed>
     */
    private static function out(int $id, string $name, string $area, string $side, array $buckets, int $transitRows = 0, float $transitAmount = 0.0,
        string $oldest = '2026-10-20', string $newest = '2026-11-14', int $creditRows = 0, float $creditAmount = 0.0): array
    {
        $row = ['BranchId' => (string) $id, 'BranchName' => $name, 'ReconArea' => $area, 'Side' => $side];
        $rows = 0;
        $amount = 0;
        foreach (array_keys(ReconDashboardService::BUCKETS) as $bucket) {
            [$n, $a] = $buckets[$bucket] ?? [0, 0.0];
            $row[$bucket.'Rows'] = (string) $n;
            $row[$bucket.'Amount'] = number_format($a, 2, '.', '');
            $rows += $n;
            $amount += (int) round($a * 100);
        }

        return $row + ['Rows' => (string) $rows, 'Amount' => number_format($amount / 100, 2, '.', ''),
            'OldestDate' => $oldest, 'NewestDate' => $newest,
            'TransitRows' => (string) $transitRows, 'TransitAmount' => number_format($transitAmount, 2, '.', ''),
            'CreditRows' => (string) $creditRows, 'CreditAmount' => number_format($creditAmount, 2, '.', '')];
    }

    /** @return array<string, mixed> */
    private static function feed(int $id, string $area, string $side, string $first, string $last, int $rows): array
    {
        return ['BranchId' => (string) $id, 'ReconArea' => $area, 'Side' => $side, 'FirstDate' => $first, 'LastDate' => $last, 'Rows' => (string) $rows];
    }

    /** @return array<string, mixed> */
    private static function period(int $id, string $area, string $side, string $start, int $rows, float $amount, int $transitRows = 0, float $transitAmount = 0.0): array
    {
        return ['BranchId' => (string) $id, 'ReconArea' => $area, 'Side' => $side, 'PeriodStart' => $start,
            'Rows' => (string) $rows, 'Amount' => number_format($amount, 2, '.', ''),
            'TransitRows' => (string) $transitRows, 'TransitAmount' => number_format($transitAmount, 2, '.', '')];
    }

    /**
     * Set 3: statements loaded to yesterday, the procedure's lags.
     *
     * @param  array<string, ?string>  $last
     * @return list<array<string, mixed>>
     */
    private static function coverage(array $last = []): array
    {
        $rows = [];
        foreach (['ABSA' => 3, 'FNB' => 3, 'CashMachine' => 3, 'CashBags' => 7, 'SmartATM' => 3] as $area => $lag) {
            $line = array_key_exists($area, $last) ? $last[$area] : '2026-11-15';
            $rows[] = ['ReconArea' => $area, 'LagDays' => (string) $lag, 'LastLineDate' => $line,
                'TransitFrom' => CarbonImmutable::parse($line ?? '2026-11-15')->addDays(1 - $lag)->format('Y-m-d')];
        }

        return $rows;
    }

    /**
     * A set-1 row as ReconPivot reads it.
     *
     * @param  array<string, array{0: int, 1: float}>  $buckets
     * @return array<string, mixed>
     */
    private function norm(array $buckets, string $side, int $transitRows = 0, float $transitAmount = 0.0): array
    {
        return ReconPivot::outstandingRow(self::out(1, 'x', 'FNB', $side, $buckets, $transitRows, $transitAmount), $side);
    }

    /** @return array<string, mixed> feed facts, everything known and fine unless said */
    private function facts(bool $bankStopped = false, bool $pumpitNever = false): array
    {
        return [
            'Bank' => ['used' => true, 'never' => false, 'stopped' => $bankStopped],
            'PumpIT' => ['used' => ! $pumpitNever, 'never' => $pumpitNever, 'stopped' => false],
            'unused' => false, 'rules' => 1,
        ];
    }

    /**
     * A link closure like the controller's: the query wins over the scope,
     * nulls are dropped.
     *
     * @param  array<string, mixed>  $scope
     */
    private function link(array $scope = []): \Closure
    {
        return fn (array $query) => '/app/recon/dashboard?'.http_build_query(array_filter($query + $scope, fn ($v) => $v !== null));
    }

    /** @return array<string, mixed> */
    private function params(?string $href): array
    {
        $this->assertNotNull($href, 'Expected a link.');
        parse_str((string) parse_url((string) $href, PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * @param  array<string, mixed>  $view
     * @return array<mixed>
     */
    private function row(array $view, int $id): array
    {
        foreach (is_array($view['rows'] ?? null) ? $view['rows'] : [] as $row) {
            if (is_array($row) && ($row['id'] ?? null) === $id) {
                return $row;
            }
        }

        $this->fail("Site {$id} is not a row.");
    }

    /** @param  array<string, mixed>  $areas */
    private function top5(array $areas): string
    {
        $gross = array_map(fn ($row) => self::c((float) $row['cells']['AllBank']['sort']) + self::c((float) $row['cells']['AllPumpIT']['sort']), $areas['rows']);
        $gross = array_map(fn ($g) => max(0, $g), $gross);
        rsort($gross);

        return Format::pct(100 * array_sum(array_slice($gross, 0, 5)) / array_sum($gross), 0);
    }

    /**
     * A result set as PDO returns it: a collection of row objects.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, object>
     */
    private static function set(array $rows): Collection
    {
        $objects = [];
        foreach ($rows as $row) {
            $objects[] = self::object($row);
        }

        return new Collection($objects);
    }

    /** @param  array<string, mixed>  $row */
    private static function object(array $row): object
    {
        return (object) $row;
    }

    private static function c(float $rand): int
    {
        return (int) round($rand * 100);
    }
}

/** The procedure layer, faked: counts the calls, returns the sets, throws once when told. */
class FakeProcedures extends ProcedureService
{
    public int $calls = 0;

    /** @var list<array<string, mixed>> */
    public array $params = [];

    public ?\Throwable $throw = null;

    /** @param  array<int, Collection<int, object>>  $sets */
    public function __construct(public array $sets)
    {
        parent::__construct();
    }

    public function callSets(string $procedure, array $params = []): array
    {
        $this->calls++;
        $this->params[] = $params;

        if ($this->throw !== null) {
            $e = $this->throw;
            $this->throw = null;
            throw $e;
        }

        return $this->sets;
    }
}

/** The dashboard service with its ledger fixed, so the cache key is the test's to move. */
class LedgerService extends ReconDashboardService
{
    /** @var array{0: mixed, 1: mixed} */
    public array $ledger = [41, null];

    protected function ledger(): array
    {
        return $this->ledger;
    }
}
