<?php

namespace Tests\Feature\Utilities;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Support\Carbon;
use Modules\Utilities\Services\UtilitiesService;
use Tests\TestCase;

/**
 * Utility meter readings (T056) — the three procedures and the view they
 * share, against the LOCAL PumpIT stub, branch 999 only.
 *
 * THE ARITHMETIC UNDER TEST is the customer's own sp_SelectUtilityTransaction:
 * Usage = Closing - Opening - Prepaid, with one correction Agora keeps from
 * usp_Reports_GridUtilityMeters — the opening is the meter's PREVIOUS reading
 * whenever it was taken, not the row dated yesterday.
 *
 * usp_Utilities_Save writes PumpIT.dbo.BRN_UtilityTransaction. These tests run
 * it against the local stub and nowhere else; skipUnlessLocalStub() refuses to
 * run them against a remote connection.
 */
class UtilityReadingsTest extends TestCase
{
    use UtilitiesFixture;

    private UtilitiesService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub('This fixture writes meter readings into the PumpIT stub.');
        $this->service = new UtilitiesService;
        config([
            'utilities.write_mode' => 'live',
            'utilities.tariffs' => ['B' => 2.5, 'S' => null],
            'utilities.zero_usage_run_days' => ['default' => 3, 'L' => null, 'M' => null],
        ]);
        $this->cleanUpUtilities();
        $this->seedMeters();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUpUtilities();
        }

        parent::tearDown();
    }

    public function test_usage_is_the_closing_less_the_previous_reading_less_prepaid_units(): void
    {
        $this->reading('2026-08-01', 100);
        $this->reading('2026-08-02', 110);
        $this->reading('2026-08-03', 125, prepaid: 5);

        $lines = $this->lines('2026-08-01', '2026-08-03', 'B');

        $this->assertNull($lines['2026-08-01']->Usage, 'A first reading has no opening, so no usage — not the whole register.');
        $this->assertSame('First reading', $lines['2026-08-01']->Status);
        $this->assertEqualsWithDelta(10.0, (float) $lines['2026-08-02']->Usage, 0.0001);
        // 125 - 110 - 5: the five prepaid units are a purchase, not consumption.
        $this->assertEqualsWithDelta(10.0, (float) $lines['2026-08-03']->Usage, 0.0001);
        $this->assertEqualsWithDelta(110.0, (float) $lines['2026-08-03']->OpeningReading, 0.0001);
    }

    public function test_cost_is_usage_at_the_tariff_and_no_tariff_is_no_cost_rather_than_zero(): void
    {
        $this->reading('2026-08-01', 100);
        $this->reading('2026-08-02', 110);
        $this->reading('2026-08-01', 50, type: 'S');
        $this->reading('2026-08-02', 53, type: 'S');

        $b = $this->lines('2026-08-02', '2026-08-02', 'B')['2026-08-02'];
        $s = $this->lines('2026-08-02', '2026-08-02', 'S')['2026-08-02'];

        $this->assertEqualsWithDelta(25.0, (float) $b->Cost, 0.001, '10 units at R2.50');
        $this->assertEqualsWithDelta(3.0, (float) $s->Usage, 0.0001);
        $this->assertNull($s->Cost, 'Water ZP has no tariff; a cost of R0.00 would be a lie.');
    }

    public function test_the_opening_is_the_previous_reading_whenever_it_was_taken_not_yesterday(): void
    {
        // Read weekly. The legacy looks for the row dated exactly the day
        // before, finds none, and calls the whole register 170 units of usage.
        $this->reading('2026-08-01', 100);
        $this->reading('2026-08-08', 170);

        $line = $this->lines('2026-08-08', '2026-08-08', 'B')['2026-08-08'];

        $this->assertEqualsWithDelta(100.0, (float) $line->OpeningReading, 0.0001);
        $this->assertSame('2026-08-01', substr((string) $line->PreviousReadingDate, 0, 10));
        $this->assertEqualsWithDelta(70.0, (float) $line->Usage, 0.0001);
    }

    public function test_a_second_row_for_one_meter_on_one_day_does_not_double_anything(): void
    {
        // Live holds four such pairs. The customer's key leads with the
        // identity, so nothing stops them.
        $this->reading('2026-08-01', 100);
        $this->reading('2026-08-02', 105);
        $this->reading('2026-08-02', 110);
        $this->reading('2026-08-03', 120);

        $prepared = $this->service->prepare(self::BRANCH, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-03'), 'B');

        $this->assertCount(3, $prepared['lines'], 'One line per meter per day, whatever PumpIT holds.');
        $day2 = $prepared['lines']->firstWhere(fn ($l) => str_starts_with((string) $l->TransactionDate, '2026-08-02'));
        $this->assertEqualsWithDelta(110.0, (float) $day2->ClosingReading, 0.0001, 'The newest row is the reading.');
        $this->assertSame(2, (int) $day2->RowsForDay, 'And the duplicate is visible, not swallowed.');

        // The month still telescopes: 120 - 100 = 20, not 20 plus a phantom 5.
        $this->assertEqualsWithDelta(20.0, (float) $prepared['meters']->first()->Usage, 0.0001);
    }

    public function test_the_month_is_the_last_closing_less_the_opening_before_it_less_prepaid(): void
    {
        // The acceptance hand check, in miniature: the reading before the
        // month, the month's readings, one prepaid purchase.
        $this->reading('2026-07-31', 1000);
        $this->reading('2026-08-01', 1010);
        $this->reading('2026-08-02', 1030, prepaid: 4);
        $this->reading('2026-08-03', 1041);

        $meter = $this->service->prepare(self::BRANCH, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-03'), 'B')['meters']->first();

        $this->assertEqualsWithDelta(1000.0, (float) $meter->OpeningReading, 0.0001);
        $this->assertEqualsWithDelta(1041.0, (float) $meter->ClosingReading, 0.0001);
        $this->assertEqualsWithDelta(4.0, (float) $meter->PrepaidUnitsPurchased, 0.0001);
        // (1041 - 1000) - 4 = 37
        $this->assertEqualsWithDelta(37.0, (float) $meter->Usage, 0.0001);
        $this->assertEqualsWithDelta(92.50, (float) $meter->Cost, 0.001, '37 units at R2.50');
        $this->assertSame(3, (int) $meter->DaysCaptured);
    }

    public function test_a_new_reading_is_inserted_with_its_opening_and_usage_stored_and_recorded(): void
    {
        $this->reading('2026-08-01', 100);

        $result = $this->save([$this->line('2026-08-02', '112.5')]);

        $this->assertSame('SAVED', $result->Code);
        $rows = $this->stored('2026-08-02');
        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(112.5, (float) $rows[0]->ClosingReading, 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $rows[0]->OpeningReading, 0.0001, 'Stored opening is the previous reading.');
        $this->assertEqualsWithDelta(12.5, (float) $rows[0]->Usage, 0.0001);
        $this->assertNotNull($rows[0]->CreateDateTime);

        $write = $this->db()->table('agora.UtilityReadingWrite')->where('BranchId', self::BRANCH)->first();
        $this->assertSame('insert', trim($write->Action));
        $this->assertSame((int) $rows[0]->UtilityTransactionId, (int) $write->UtilityTransactionId);
        $this->assertSame((int) $result->Id, (int) $write->SaveId);
    }

    public function test_days_saved_together_chain_their_openings_and_the_next_reading_follows(): void
    {
        // A clerk catching up three days at once, with the 5th already read.
        // Each day's opening is the day before it IN THIS SAVE, and the 5th's
        // stored figures move because the 4th now exists.
        $this->reading('2026-08-01', 100);
        $this->reading('2026-08-05', 150, opening: 100, usage: 50);

        $result = $this->save([
            $this->line('2026-08-04', '140'),
            $this->line('2026-08-02', '110'),
            $this->line('2026-08-03', '125', prepaid: '5'),
        ]);

        $this->assertStringContainsString('3 readings recorded', $result->Message);
        $expect = ['2026-08-02' => [100, 10], '2026-08-03' => [110, 10], '2026-08-04' => [125, 15], '2026-08-05' => [140, 10]];
        foreach ($expect as $date => [$opening, $usage]) {
            $row = $this->stored($date)[0];
            $this->assertEqualsWithDelta($opening, (float) $row->OpeningReading, 0.0001, "{$date} opening");
            $this->assertEqualsWithDelta($usage, (float) $row->Usage, 0.0001, "{$date} usage");
        }

        $actions = $this->db()->table('agora.UtilityReadingWrite')->where('BranchId', self::BRANCH)
            ->orderBy('ReadingDate')->pluck('Action')->map(fn ($a) => trim($a))->all();
        $this->assertSame(['insert', 'insert', 'insert', 'recompute'], $actions);
    }

    public function test_a_day_the_legacy_wrote_ahead_as_zero_is_filled_in_place_not_duplicated(): void
    {
        // The legacy screen saves the whole month grid, so the rest of the
        // month sits in PumpIT as 0 / 0 waiting to be read.
        $this->reading('2026-08-01', 100);
        $placeholder = $this->reading('2026-08-02', 0);

        $this->save([$this->line('2026-08-02', '108')]);

        $rows = $this->stored('2026-08-02');
        $this->assertCount(1, $rows, 'Filled, not a second row beside the placeholder.');
        $this->assertSame($placeholder, (int) $rows[0]->UtilityTransactionId);
        $this->assertEqualsWithDelta(8.0, (float) $rows[0]->Usage, 0.0001);
        $this->assertSame('fill', trim($this->db()->table('agora.UtilityReadingWrite')->where('BranchId', self::BRANCH)->value('Action')));
    }

    public function test_changing_a_captured_reading_needs_the_edit_permission_and_a_reason(): void
    {
        $this->reading('2026-08-01', 100);
        $this->reading('2026-08-02', 110, opening: 100, usage: 10);
        $this->reading('2026-08-03', 125, opening: 110, usage: 15);

        $this->expectRefusal('AMEND_NOT_ALLOWED', fn () => $this->save([$this->line('2026-08-02', '112')]));
        $this->expectRefusal('REASON_REQUIRED', fn () => $this->save([$this->line('2026-08-02', '112')], allowAmend: true));

        $this->save([$this->line('2026-08-02', '112')], allowAmend: true, reason: 'TEST-misread the 0 as a 2');

        $this->assertEqualsWithDelta(112.0, (float) $this->stored('2026-08-02')[0]->ClosingReading, 0.0001);

        // The NEXT reading's opening moved with it, so its stored figures are
        // brought into line: 125 - 112 = 13, where it said 15.
        $next = $this->stored('2026-08-03')[0];
        $this->assertEqualsWithDelta(112.0, (float) $next->OpeningReading, 0.0001);
        $this->assertEqualsWithDelta(13.0, (float) $next->Usage, 0.0001);

        $writes = $this->db()->table('agora.UtilityReadingWrite')->where('BranchId', self::BRANCH)
            ->orderBy('ReadingDate')->get();
        $this->assertSame(['amend', 'recompute'], $writes->map(fn ($w) => trim($w->Action))->all());
        $this->assertEqualsWithDelta(110.0, (float) $writes[0]->PriorClosing, 0.0001, 'The value it replaced is kept.');
        $this->assertEqualsWithDelta(15.0, (float) $writes[1]->PriorUsage, 0.0001);
        $this->assertSame('TEST-misread the 0 as a 2',
            $this->db()->table('agora.UtilityReadingSave')->where('BranchId', self::BRANCH)->value('Reason'));
    }

    public function test_a_save_that_changes_nothing_writes_nothing(): void
    {
        $this->reading('2026-08-01', 100);
        $this->reading('2026-08-02', 110.25, opening: 100, usage: 10.25);

        $result = $this->save([$this->line('2026-08-02', '110.25')]);

        $this->assertSame('NOTHING_CHANGED', $result->Code);
        $this->assertSame(0, $this->db()->table('agora.UtilityReadingSave')->where('BranchId', self::BRANCH)->count());
    }

    public function test_a_blank_box_is_not_a_reading_and_is_not_sent(): void
    {
        $lines = $this->service->linesForProcedure([
            $this->line('2026-08-02', ''),
            $this->line('2026-08-02', '0', type: 'S'),
        ]);

        // The empty one is dropped. The typed 0 is sent, and the procedure
        // refuses it by name (test_a_zero_with_no_prepaid_is_refused...).
        $this->assertCount(1, $lines);
        $this->assertSame('0', $lines[0]['ClosingReading']);
    }

    public function test_a_zero_zero_day_is_not_a_reading(): void
    {
        // Ryan, PM decision 01a0e312-07a0. The legacy writes days ahead as
        // 0 / 0; such a day has no usage and is never an opening.
        $this->reading('2026-08-01', 100);
        $this->reading('2026-08-02', 0);
        $this->reading('2026-08-03', 120);

        $lines = $this->lines('2026-08-01', '2026-08-03', 'B');

        $this->assertSame('No reading', $lines['2026-08-02']->Status);
        $this->assertNull($lines['2026-08-02']->Usage, 'Not 0 - 100 = -100: there is no reading to take usage from.');
        $this->assertSame(0, (int) $lines['2026-08-02']->IsCaptured);
        $this->assertEqualsWithDelta(100.0, (float) $lines['2026-08-02']->OpeningReading, 0.0001,
            'The blank day still says what it would be measured from.');

        $this->assertEqualsWithDelta(100.0, (float) $lines['2026-08-03']->OpeningReading, 0.0001, 'Measured past the blank day.');
        $this->assertSame('2026-08-01', substr((string) $lines['2026-08-03']->PreviousReadingDate, 0, 10));
        $this->assertEqualsWithDelta(20.0, (float) $lines['2026-08-03']->Usage, 0.0001);
        $this->assertSame('Normal', $lines['2026-08-03']->Status);

        $view = $this->db()->table('agora.vw_UtilityReading')->where('BranchId', self::BRANCH)
            ->where('ReadingDate', '2026-08-03')->first();
        $this->assertEqualsWithDelta(20.0, (float) $view->Usage, 0.0001, 'The view itself, not only the procedure.');
    }

    public function test_a_month_that_ends_in_days_not_yet_read_reads_sensibly(): void
    {
        // Branch 8's September 2026 on live, in miniature: read to the 3rd,
        // the rest of the month written ahead as 0 / 0. It read -1,000.
        $this->reading('2026-07-31', 1000);
        $this->reading('2026-08-01', 1010);
        $this->reading('2026-08-02', 1020);
        $this->reading('2026-08-03', 1030);
        $this->reading('2026-08-04', 0);
        $this->reading('2026-08-05', 0);

        $meter = $this->service->prepare(self::BRANCH, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-05'), 'B')['meters']->first();

        $this->assertEqualsWithDelta(30.0, (float) $meter->Usage, 0.0001);
        $this->assertEqualsWithDelta(1030.0, (float) $meter->ClosingReading, 0.0001, 'The last REAL reading, not the 0 after it.');
        $this->assertSame('2026-08-03', substr((string) $meter->ClosingDate, 0, 10));
        $this->assertSame(3, (int) $meter->DaysCaptured);
        $this->assertSame(2, (int) $meter->BlankDays);
    }

    public function test_a_zero_with_no_prepaid_is_refused_as_not_a_reading(): void
    {
        $this->reading('2026-08-01', 100);
        $this->reading('2026-08-02', 110);

        // Over nothing, or over a real reading — which would quietly un-read the day.
        $this->expectRefusal('NOT_A_READING', fn () => $this->save([$this->line('2026-08-03', '0')]));
        $this->expectRefusal('NOT_A_READING', fn () => $this->save([$this->line('2026-08-02', '0')], allowAmend: true, reason: 'TEST'));

        // A zero WITH prepaid units is a reading (a prepaid meter bought back from empty).
        $this->save([$this->line('2026-08-03', '0', prepaid: '5')]);
        $this->assertCount(1, $this->stored('2026-08-03'));
    }

    public function test_saving_over_a_blank_day_fills_it_and_the_next_reading_is_measured_from_it(): void
    {
        $this->reading('2026-08-01', 100);
        $blank = $this->reading('2026-08-02', 0);
        $this->reading('2026-08-03', 125, opening: 100, usage: 25);

        $this->save([$this->line('2026-08-02', '110')]);

        $this->assertSame($blank, (int) $this->stored('2026-08-02')[0]->UtilityTransactionId, 'Filled in place.');
        $next = $this->stored('2026-08-03')[0];
        $this->assertEqualsWithDelta(110.0, (float) $next->OpeningReading, 0.0001);
        $this->assertEqualsWithDelta(15.0, (float) $next->Usage, 0.0001);
    }

    public function test_amending_a_reading_brings_the_next_real_reading_into_line_and_leaves_blank_days_alone(): void
    {
        $this->reading('2026-08-01', 100);
        $this->reading('2026-08-02', 110, opening: 100, usage: 10);
        $this->reading('2026-08-03', 0, opening: 110, usage: -110);   // as the legacy leaves a blank day
        $this->reading('2026-08-04', 130, opening: 110, usage: 20);

        $this->save([$this->line('2026-08-02', '112')], allowAmend: true, reason: 'TEST-misread');

        $blank = $this->stored('2026-08-03')[0];
        $this->assertEqualsWithDelta(-110.0, (float) $blank->Usage, 0.0001, 'Not a reading, so not rewritten.');
        $next = $this->stored('2026-08-04')[0];
        $this->assertEqualsWithDelta(112.0, (float) $next->OpeningReading, 0.0001);
        $this->assertEqualsWithDelta(18.0, (float) $next->Usage, 0.0001);

        $actions = $this->db()->table('agora.UtilityReadingWrite')->where('BranchId', self::BRANCH)
            ->orderBy('ReadingDate')->pluck('Action')->map(fn ($a) => trim($a))->all();
        $this->assertSame(['amend', 'recompute'], $actions);
    }

    public function test_agora_stamps_its_own_record_in_utc_and_pumpit_keeps_its_local_clock(): void
    {
        // The recon procedures moved to UTC on 24 Sep 2026 (v1__13py) because
        // the application writes UTC; a local SYSDATETIME() read "2 hours from
        // now" on the +02:00 server. PumpIT's CreateDateTime stays GETDATE(),
        // as the customer's table has always been written.
        $sql = file_get_contents(base_path('Modules/Utilities/Database/Procedures/usp_Utilities_Save.sql'));
        $code = preg_replace('~/\*.*?\*/~s', '', $sql);
        $this->assertStringNotContainsString('SYSDATETIME(', $code);
        $this->assertSame(3, substr_count($code, 'SYSUTCDATETIME()'), 'The save header and both write-record inserts.');

        $this->reading('2026-08-01', 100);
        $this->save([$this->line('2026-08-02', '110')]);

        $saved = Carbon::parse($this->db()->table('agora.UtilityReadingSave')->where('BranchId', self::BRANCH)->value('CreatedAt'), 'UTC');
        $this->assertLessThan(120, abs(Carbon::now('UTC')->diffInSeconds($saved)), 'Agora\'s timestamp is UTC.');
    }

    public function test_what_the_procedure_refuses(): void
    {
        $this->reading('2026-08-01', 100);

        $this->expectRefusal('FUTURE_DATE', fn () => $this->save([$this->line(Carbon::tomorrow()->toDateString(), '120')]));
        $this->expectRefusal('NEGATIVE_READING', fn () => $this->save([$this->line('2026-08-02', '-1')]));
        $this->expectRefusal('UNKNOWN_METER', fn () => $this->save([$this->line('2026-08-02', '120', meter: 'TEST-No such meter')]));
        $this->expectRefusal('DUPLICATE_LINE', fn () => $this->save([$this->line('2026-08-02', '120'), $this->line('2026-08-02', '121')]));
        // Prepaid with no closing: the purchase has no reading to sit against.
        $this->expectRefusal('CLOSING_REQUIRED', fn () => $this->save([$this->line('2026-08-02', '', prepaid: '50')]));

        $this->assertCount(0, $this->stored('2026-08-02'), 'Nothing refused was written.');
    }

    public function test_the_procedure_refuses_to_write_unless_writes_are_live(): void
    {
        $this->expectRefusal('WRITES_OFF', fn () => app(ProcedureService::class)->write('agora.usp_Utilities_Save', [
            'BranchId' => self::BRANCH,
            'LinesJson' => json_encode([$this->line('2026-08-02', '120')]),
            'WriteMode' => 'off',
            'AllowAmend' => 0,
            'Reason' => null,
            'UserId' => null,
        ]));
    }

    public function test_a_meter_that_stops_moving_is_a_zero_usage_run_measured_in_days(): void
    {
        $this->reading('2026-08-09', 190);
        $this->reading('2026-08-10', 200);
        foreach (['11', '12', '13', '14', '15'] as $d) {
            $this->reading("2026-08-{$d}", 200);
        }
        $this->reading('2026-08-16', 205);

        $runs = $this->service->zeroUsage(self::BRANCH, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        $this->assertCount(1, $runs);
        $run = $runs->first();
        $this->assertSame(5, (int) $run->RunDays, 'Held at 200 from the 10th to the 15th.');
        $this->assertSame('Meter did not move', $run->Kind);
        $this->assertSame(0, (int) $run->IsOngoing, 'It moved again on the 16th.');
        $this->assertSame('utilities.zero-usage:999:B:'.self::ELECTRIC.':2026-08-10', $run->Fingerprint);
    }

    public function test_a_meter_read_weekly_that_did_not_move_is_a_seven_day_run_not_one(): void
    {
        $this->reading('2026-08-01', 300);
        $this->reading('2026-08-08', 300);

        $run = $this->service->zeroUsage(self::BRANCH, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'))->first();

        $this->assertNotNull($run);
        $this->assertSame(7, (int) $run->RunDays);
        $this->assertSame(1, (int) $run->Readings);
        $this->assertSame(1, (int) $run->IsOngoing);
    }

    public function test_a_short_pause_is_not_flagged_and_the_capture_line_says_when_it_is(): void
    {
        $this->reading('2026-08-01', 50);
        $this->reading('2026-08-02', 50);
        $this->reading('2026-08-03', 50);

        // Two days still is below the default of three.
        $this->assertCount(0, $this->service->zeroUsage(self::BRANCH, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-03')));
        $this->assertSame(0, (int) $this->lines('2026-08-03', '2026-08-03', 'B')['2026-08-03']->IsZeroRun);

        $this->reading('2026-08-04', 50);

        $this->assertCount(1, $this->service->zeroUsage(self::BRANCH, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-04')));
        $line = $this->lines('2026-08-04', '2026-08-04', 'B')['2026-08-04'];
        $this->assertSame(1, (int) $line->IsZeroRun);
        $this->assertSame(3, (int) $line->ZeroRunDays);
    }

    public function test_a_meter_nobody_has_ever_read_is_no_reading_counted_from_its_first_row(): void
    {
        foreach (['01', '02', '03', '04', '05'] as $d) {
            $this->reading("2026-08-{$d}", 0, type: 'S');
        }

        $runs = $this->service->zeroUsage(self::BRANCH, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        $this->assertCount(1, $runs);
        $this->assertSame('No reading', $runs->first()->Kind);
        $this->assertSame(1, (int) $runs->first()->NeverRead);
        $this->assertSame(5, (int) $runs->first()->RunDays, 'Five days held as 0 / 0, none of them read.');
    }

    public function test_blank_days_after_a_reading_are_no_reading_and_never_did_not_move(): void
    {
        $this->reading('2026-08-01', 100);
        foreach (['02', '03', '04', '05', '06'] as $d) {
            $this->reading("2026-08-{$d}", 0);
        }

        $runs = $this->service->zeroUsage(self::BRANCH, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        $this->assertSame(['No reading'], $runs->pluck('Kind')->all());
        $run = $runs->first();
        $this->assertSame(5, (int) $run->RunDays, 'Last read on the 1st, nothing to the 6th.');
        $this->assertSame('2026-08-01', substr((string) $run->RunFrom, 0, 10));
        $this->assertSame(1, (int) $run->IsOngoing);
        $this->assertSame('utilities.no-reading:999:B:'.self::ELECTRIC.':2026-08-01', $run->Fingerprint);
        $this->assertStringContainsString('has not been read for 5 days', $run->Title);
    }

    public function test_a_blank_day_neither_moves_a_still_meter_nor_stops_it(): void
    {
        $this->reading('2026-08-09', 190);
        $this->reading('2026-08-10', 200);
        $this->reading('2026-08-11', 0);
        $this->reading('2026-08-12', 200);
        $this->reading('2026-08-13', 200);
        $this->reading('2026-08-14', 200);

        $still = $this->service->zeroUsage(self::BRANCH, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'))
            ->firstWhere('Kind', 'Meter did not move');

        $this->assertNotNull($still);
        $this->assertSame(4, (int) $still->RunDays, 'Held at 200 from the 10th to the 14th, the unread 11th included.');
        $this->assertSame(3, (int) $still->Readings);
    }

    public function test_the_run_length_is_per_meter_type_and_generators_are_never_flagged(): void
    {
        $this->db()->table('PumpIT.dbo.BRN_UtilityMeter')->insert([
            'UtilityMeterId' => 999003, 'SSBranchId' => self::BRANCH, 'UtilityType' => 'L',
            'MeterNo' => 'TEST-Generator', 'Description' => 'TEST-Generator',
        ]);
        foreach (range(1, 8) as $d) {
            $this->reading(sprintf('2026-08-%02d', $d), 50, type: 'L', meter: 'TEST-Generator');
            $this->reading(sprintf('2026-08-%02d', $d), 70, type: 'B');
        }

        $runs = fn () => $this->service->zeroUsage(self::BRANCH, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        // The default: the electricity meter (7 days still) is flagged, the
        // generator is not — it stands still whenever there is no load-shedding.
        $this->assertSame(['B'], $runs()->pluck('UtilityType')->all());
        $this->assertSame(0, (int) $this->lines('2026-08-08', '2026-08-08', 'L')['2026-08-08']->IsZeroRun);

        // A length of its own: B after 10 days is not yet flagged at 7.
        config(['utilities.zero_usage_run_days' => ['default' => 3, 'B' => 10, 'L' => null, 'M' => null]]);
        $this->assertCount(0, $runs());

        // And a generator given a number is flagged like anything else.
        config(['utilities.zero_usage_run_days' => ['default' => 3, 'L' => 5]]);
        $this->assertSame(['B', 'L'], $runs()->pluck('UtilityType')->sort()->values()->all());

        $this->assertSame(
            'Flagged after 3 days without moving, or without a reading; never for meter types L and M.',
            (function () {
                config(['utilities.zero_usage_run_days' => ['default' => 3, 'L' => null, 'M' => null]]);

                return $this->service->zeroRunRule();
            })()
        );
    }

    public function test_the_readings_grid_filters_narrow_and_one_that_matches_nothing_empties_it(): void
    {
        $this->reading('2026-08-01', 100);
        $this->reading('2026-08-02', 110);
        $this->reading('2026-08-03', 110);
        $this->reading('2026-08-04', 125);

        $grid = fn (array $filters) => app(ProcedureService::class)->callSets('agora.usp_Utilities_GridReadings', [
            'BranchIds' => (string) self::BRANCH,
            'DateFrom' => '2026-08-01',
            'DateTo' => '2026-08-31',
            'FiltersJson' => json_encode($filters),
            'TariffsJson' => '{"B":2.5}',
        ]);

        [$rows, $total] = $grid([]);
        $this->assertCount(4, $rows);
        $this->assertSame(4, (int) $total->first()->TotalRows);

        // The filter that proves the filters work: one that matches nothing.
        [$rows, $total] = $grid([['column' => 'MeterDescription', 'type' => 'text', 'op' => 'contains', 'value' => 'ZZZZ-NO-SUCH-METER']]);
        $this->assertCount(0, $rows);
        $this->assertSame(0, (int) $total->first()->TotalRows, 'The count comes from the same filtered set as the page.');

        [$rows] = $grid([['column' => 'Status', 'type' => 'set', 'in' => ['Zero usage']]]);
        $this->assertSame(['2026-08-03'], $rows->map(fn ($r) => substr((string) $r->TransactionDate, 0, 10))->all());

        [$rows] = $grid([['column' => 'Usage', 'type' => 'number', 'op' => 'gt', 'value' => '10']]);
        $this->assertSame(['2026-08-04'], $rows->map(fn ($r) => substr((string) $r->TransactionDate, 0, 10))->all());
        $this->assertEqualsWithDelta(37.50, (float) $rows->first()->Cost, 0.001, '15 units at R2.50');

        // A number that does not parse fails closed rather than letting everything through.
        [$rows] = $grid([['column' => 'Usage', 'type' => 'number', 'op' => 'gt', 'value' => 'ten']]);
        $this->assertCount(0, $rows);
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, object> keyed by date */
    private function lines(string $from, string $to, string $type): array
    {
        return $this->service->prepare(self::BRANCH, Carbon::parse($from), Carbon::parse($to), $type)['lines']
            ->keyBy(fn ($l) => substr((string) $l->TransactionDate, 0, 10))
            ->all();
    }

    /** @return array<string, string> */
    private function line(string $date, string $closing, string $prepaid = '', string $type = 'B', ?string $meter = null): array
    {
        return [
            'UtilityType' => $type,
            'MeterNo' => $meter ?? ($type === 'B' ? self::ELECTRIC : self::WATER),
            'TransactionDate' => $date,
            'ClosingReading' => $closing,
            'PrepaidUnitsPurchased' => $prepaid,
        ];
    }

    /** @param array<int, array<string, string>> $lines */
    private function save(array $lines, bool $allowAmend = false, ?string $reason = null): object
    {
        return $this->service->save(self::BRANCH, $lines, $allowAmend, $reason, null);
    }

    private function expectRefusal(string $code, callable $act): void
    {
        try {
            $act();
        } catch (AgoraProcException $e) {
            $this->assertSame($code, $e->code(), "Refused, but with {$e->code()}: {$e->getMessage()}");

            return;
        }

        $this->fail("Expected AGORA:{$code} and the write was allowed.");
    }
}
