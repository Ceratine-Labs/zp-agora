<?php

namespace Tests\Feature\Recon;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Recon\Models\ReconRun;
use Modules\Recon\Models\ReconRunLine;
use Modules\Recon\Services\ReconMatchService;
use Tests\TestCase;

/**
 * Suggestions by value for FNB — agora.usp_Recon_SuggestMatches.
 *
 * The shapes below are real ones, taken from branch 15 in August 2026 and
 * worked out by hand before the procedure existed: a device's two lines
 * against a two-row batch, a Monday line that is Sunday's two batches, a line
 * that is one row. The amounts are the real amounts, because a test made of
 * round numbers would be a test of the one case the procedure treats as
 * ambiguous.
 *
 * The assertion the file exists for is `test_a_deposit_is_never_in_two_suggestions`
 * together with `test_ambiguity_is_possible_never_strong`: a strong
 * suggestion must mean nothing else wanted its rows, and no row may ever be
 * offered twice — a clerk accepting both would be told, correctly, that the
 * second one had moved, and would stop trusting the list.
 *
 * The fixture writes to the LOCAL PumpIT stub only, and only to branch 999.
 * Matching is done in journal mode, so nothing in the stub is stamped either;
 * the Agora ledger rows it writes are removed in tearDown().
 */
class SuggestMatchesTest extends TestCase
{
    private const BRANCH = 999;

    private const FROM = '2026-08-04';

    private const TO = '2026-08-12';

    private ProcedureService $procedures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub();
        $this->procedures = new ProcedureService;
        $this->cleanUp();
        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUp();
        }

        parent::tearDown();
    }

    public function test_one_line_and_one_deposit_of_the_same_amount_is_strong(): void
    {
        $s = $this->suggestionFor(3689.76);

        $this->assertSame('strong', $s->Confidence);
        $this->assertSame('line=row', $s->Shape);
        $this->assertSame(1, (int) $s->LagDays);
        $this->assertNull($s->Caution);
    }

    /** Branch 15, 5 Aug: device 00707874's two lines are batch 13's two rows. */
    public function test_a_device_day_settles_a_whole_batch(): void
    {
        $s = $this->suggestionFor(8218.78);

        $this->assertSame('strong', $s->Confidence);
        $this->assertSame('devday=batch', $s->Shape);
        $this->assertCount(2, $s->bank);
        $this->assertCount(2, $s->mops);
        $this->assertSame(['13'], $s->mops->pluck('SourceKey')->unique()->values()->all());
    }

    /**
     * Branch 15, Monday 11 Aug: one line is Sunday's two batches together.
     * Neither batch equals the line alone, so only the combination pass can
     * find it — and it has to find it on ONE day.
     */
    public function test_a_monday_line_is_two_batches_from_one_day(): void
    {
        $s = $this->suggestionFor(11338.63);

        $this->assertSame('strong', $s->Confidence);
        $this->assertSame('line=2 batches', $s->Shape);
        $this->assertSame(['2026-08-10'], $s->mops->map(fn ($m) => substr((string) $m->SourceDate, 0, 10))->unique()->values()->all());
    }

    /** Two R220 deposits, one R220 line: a person decides which. */
    public function test_ambiguity_is_possible_never_strong(): void
    {
        $s = $this->suggestionFor(220.00);

        $this->assertSame('possible', $s->Confidence);
        $this->assertSame('One other pairing wants some of these rows', $s->Caution);
        $this->assertCount(1, $s->mops, 'The line is offered against one of the two deposits, never both.');
    }

    public function test_a_deposit_is_never_in_two_suggestions(): void
    {
        $deposits = [];
        $lines = [];

        foreach ($this->suggest()['suggestions'] as $s) {
            foreach ($s->mops as $m) {
                $deposits[] = $m->SourceKey.'|'.substr((string) $m->SourceDate, 0, 10).'|'.$m->Amount;
            }

            foreach ($s->bank as $b) {
                $lines[] = (int) $b->BankStatementLineID;
            }
        }

        // The fixture holds no two indistinguishable deposits, so every member
        // is one row and appears once.
        $this->assertNotEmpty($deposits);
        $this->assertSame(count($deposits), count(array_unique($deposits)));
        $this->assertSame(count($lines), count(array_unique($lines)));
    }

    /** "After batch numbers have been matched" — ZP's words. */
    public function test_what_the_batch_number_settles_is_left_out(): void
    {
        $answer = $this->suggest();

        $this->assertSame(1, (int) $answer['summary']->BatchBankRows);
        $this->assertNull($this->suggestionFor(500.00, $answer, false),
            'A line the preview matches on its batch number belongs on the Auto tab.');
    }

    /**
     * The value ties, but the bank line says batch 853 and the deposit is
     * batch 451. That is for a person to look at, whatever the amounts say.
     */
    public function test_a_batch_number_that_disagrees_is_only_possible(): void
    {
        $s = $this->suggestionFor(150.00);

        $this->assertSame('possible', $s->Confidence);
        $this->assertSame('The batch number on the bank line is not on these deposits', $s->Caution);
    }

    /**
     * The deposit of 2 Aug belongs to the line of 3 Aug, which is BEFORE the
     * period. Without that line loaded, the in-period line of 5 Aug for the
     * same amount would be handed its deposit as a strong match. The replay
     * against four months of history found exactly this.
     */
    public function test_a_line_before_the_period_keeps_its_own_deposit(): void
    {
        $this->assertNull($this->suggestionFor(777.77, null, false));
    }

    /** Nothing strong or possible is ever proposed whose sides differ by a cent. */
    public function test_every_exact_suggestion_balances_to_the_cent(): void
    {
        foreach ($this->suggest()['suggestions']->whereIn('Confidence', ['strong', 'possible']) as $s) {
            $this->assertSame(
                round((float) $s->bank->sum('Amount'), 2),
                round((float) $s->mops->sum('Amount'), 2),
                "Suggestion {$s->SuggestionNo} ({$s->Shape}) does not balance."
            );
        }
    }

    /**
     * Site 8, 4 Aug 2026, as a single line: R19,197.34 on the statement
     * against R19,179.28 of takings the day before. Neither exact tier can
     * have it; the close tier says what it is and by how much.
     */
    public function test_a_line_a_few_rand_off_its_takings_is_close(): void
    {
        $s = $this->suggestionFor(19197.34);

        $this->assertSame('close', $s->Confidence);
        $this->assertSame('line~row', $s->Shape);
        $this->assertSame(-18.06, round((float) $s->DiffAmount, 2), 'Deposits less bank, the sign ManualMatch records.');
        $this->assertSame(1, (int) $s->LagDays);
        $this->assertStringStartsWith('The bank is R18.06 over the takings', (string) $s->Caution);
        $this->assertSame(round((float) $s->MopsTotal - (float) $s->BankTotal, 2), round((float) $s->DiffAmount, 2));
    }

    /**
     * The R3,689.76 line ties to the cent with one deposit and is within R11
     * of another. The exact reading is taken, and the near one never sees the
     * line at all.
     */
    public function test_close_never_displaces_an_exact_match(): void
    {
        $answer = $this->suggest();

        $this->assertSame('strong', $this->suggestionFor(3689.76, $answer)->Confidence);
        $this->assertNull(
            $answer['suggestions']->first(fn (object $s) => round((float) $s->MopsTotal, 2) === 3700.00),
            'The R3,700.00 deposit was offered against a line an exact suggestion already holds.'
        );

        $exact = $answer['suggestions']->whereIn('Confidence', ['strong', 'possible']);
        $close = $answer['suggestions']->where('Confidence', 'close');
        $held = $exact->flatMap(fn (object $s) => $s->bank->pluck('BankStatementLineID'))->all();

        foreach ($close as $s) {
            $this->assertEmpty(array_intersect($held, $s->bank->pluck('BankStatementLineID')->all()));
        }
    }

    /** @CloseMax = 0 is the procedure as it was: the exact tiers do not move. */
    public function test_the_close_tier_switched_off_leaves_the_exact_tiers_as_they_were(): void
    {
        $shape = fn (Collection $rows) => $rows->whereIn('Confidence', ['strong', 'possible'])
            ->map(fn (object $s) => $s->Confidence.'|'.$s->Shape.'|'.$s->BankTotal.'|'.$s->MopsTotal)->values()->all();

        $on = $this->procedures->callSets('usp_Recon_SuggestMatches', [
            'BranchId' => self::BRANCH, 'ReconArea' => 'FNB', 'FromDate' => self::FROM, 'ToDate' => self::TO.' 23:59:59',
        ]);
        $off = $this->procedures->callSets('usp_Recon_SuggestMatches', [
            'BranchId' => self::BRANCH, 'ReconArea' => 'FNB', 'FromDate' => self::FROM, 'ToDate' => self::TO.' 23:59:59',
            'CloseMax' => 0,
        ]);

        $this->assertNotEmpty($on[0]->where('Confidence', 'close'));
        $this->assertEmpty($off[0]->where('Confidence', 'close'));
        $this->assertSame($shape($on[0]), $shape($off[0]));
        $this->assertSame(0, (int) $off[2]->first()->CloseSuggestions);
    }

    /** A close one is a forced match: the procedure will not take it on the algorithm's word. */
    public function test_a_close_suggestion_needs_a_reason(): void
    {
        config(['recon.stamp_mode' => 'journal']);

        $s = $this->suggestionFor(19197.34);

        $this->actingAs($this->admin())
            ->postJson('/app/recon/auto/FNB/match', $this->form($s))
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'code' => 'FORCE_REASON_REQUIRED']);
    }

    public function test_an_accepted_close_suggestion_is_forced_and_says_it_was_suggested(): void
    {
        config(['recon.stamp_mode' => 'journal']);

        $s = $this->suggestionFor(19197.34);
        $form = ['basis' => $s->Basis.' — close: '.$s->Caution, 'reason' => 'TEST- card fee on the settlement'] + $this->form($s);

        $this->actingAs($this->admin())
            ->postJson('/app/recon/auto/FNB/match', $form)
            ->assertOk()
            ->assertJson(['ok' => true, 'code' => 'MATCHED_FORCED']);

        $run = ReconRun::query()->acrossBranches()->where('BranchId', self::BRANCH)->latest('Id')->firstOrFail();
        $line = ReconRunLine::query()->acrossBranches()->where('RunId', $run->Id)->firstOrFail();

        $this->assertSame('Matched by suggestion - forced', $line->Outcome);
        $this->assertStringStartsWith('Suggested match (forced) — ', (string) $run->Note);
        $this->assertStringContainsString('— close: The bank is R18.06 over the takings', (string) $run->Note);
        $this->assertSame(-18.06, round((float) $line->DiffAmount, 2));
        $this->assertSame('TEST- card fee on the settlement', $line->BlockReason);
    }

    /**
     * Each close row carries its own reason box, and there is no press that
     * forces them all — that one waits on Ryan.
     */
    public function test_close_suggestions_are_offered_one_at_a_time_with_a_reason(): void
    {
        $this->actingAs($this->admin())
            ->get('/app/recon/auto/FNB/suggest?branch_id='.self::BRANCH.'&from='.self::FROM.'&to='.self::TO)
            ->assertOk()
            ->assertSee('Close — needs a reason')
            ->assertSee('data-suggest-kind="close"', false)
            ->assertSee('name="reason" required', false)
            ->assertSee('Force match')
            ->assertSee('−R18.06')
            ->assertDontSee('data-suggest-run="close"', false);
    }

    public function test_an_area_other_than_fnb_is_refused(): void
    {
        try {
            $this->procedures->callSets('usp_Recon_SuggestMatches', [
                'BranchId' => self::BRANCH, 'ReconArea' => 'ABSA',
                'FromDate' => self::FROM, 'ToDate' => self::TO.' 23:59:59',
            ]);
            $this->fail('ABSA was given suggestions by value.');
        } catch (AgoraProcException $e) {
            $this->assertSame('SUGGEST_UNSUPPORTED', $e->code());
        }
    }

    public function test_the_tab_is_offered_on_fnb_and_not_on_absa(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/app/recon/auto/FNB/suggest?branch_id='.self::BRANCH.'&from='.self::FROM.'&to='.self::TO)
            ->assertOk()
            ->assertSee('Suggested matches')
            ->assertSee('agora.usp_Recon_SuggestMatches');

        // A tab of the recon centre once a scope is chosen (23 Sep 2026); the
        // pane fetches the route, so the route is what to look for.
        $scope = '?branch_id='.self::BRANCH.'&from='.self::FROM.'&to='.self::TO;
        $this->actingAs($admin)->get('/app/recon/auto/FNB'.$scope)->assertSee(route('app.recon.suggest', 'FNB'), false);
        $this->actingAs($admin)->get('/app/recon/auto/ABSA'.$scope)->assertDontSee(route('app.recon.suggest', 'ABSA'), false);
        $this->actingAs($admin)->get('/app/recon/auto/ABSA/suggest')->assertNotFound();
    }

    /**
     * Accepting one goes through the manual match, unchanged, and the run
     * says it was a suggestion. Journal mode: the stub is not stamped.
     */
    public function test_accepting_a_suggestion_records_it_as_one(): void
    {
        config(['recon.stamp_mode' => 'journal']);

        $s = $this->suggestionFor(3689.76);

        $this->actingAs($this->admin())
            ->post('/app/recon/auto/FNB/match', $this->form($s))
            ->assertRedirect()
            ->assertSessionHas('suggestMatched');

        $run = ReconRun::query()->acrossBranches()->where('BranchId', self::BRANCH)->latest('Id')->firstOrFail();
        $line = ReconRunLine::query()->acrossBranches()->where('RunId', $run->Id)->firstOrFail();

        $this->assertSame('agora.usp_Recon_ManualMatch', $run->ProcedureName);
        $this->assertStringStartsWith('Suggested match — ', (string) $run->Note);
        $this->assertSame('Matched by suggestion', $line->Outcome);
        $this->assertSame(0.0, round((float) $line->DiffAmount, 2));
    }

    /**
     * Possible has its own press (Ryan, 23 Sep 2026), never the strong one's:
     * the page offers both, marks every form with its tier, and a possible
     * form carries its reason into the basis the run records.
     */
    public function test_possible_suggestions_have_their_own_press(): void
    {
        $this->actingAs($this->admin())
            ->get('/app/recon/auto/FNB/suggest?branch_id='.self::BRANCH.'&from='.self::FROM.'&to='.self::TO)
            ->assertOk()
            ->assertSee('Match all 3 strong')
            ->assertSee('Match all 2 possible')
            ->assertSee('data-suggest-kind="possible"', false)
            ->assertSee('— possible: The batch number on the bank line is not on these deposits', false);
    }

    public function test_an_accepted_possible_suggestion_says_so_on_its_run(): void
    {
        config(['recon.stamp_mode' => 'journal']);

        $s = $this->suggestionFor(150.00);
        $form = ['basis' => $s->Basis.' — possible: '.$s->Caution] + $this->form($s);

        $this->actingAs($this->admin())
            ->postJson('/app/recon/auto/FNB/match', $form)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $run = ReconRun::query()->acrossBranches()->where('BranchId', self::BRANCH)->latest('Id')->firstOrFail();

        $this->assertStringContainsString('— possible: The batch number on the bank line is not on these deposits', (string) $run->Note);
    }

    /** The "match all strong" press asks for JSON, one suggestion at a time. */
    public function test_the_bulk_press_gets_a_json_answer(): void
    {
        config(['recon.stamp_mode' => 'journal']);

        $s = $this->suggestionFor(8218.78);

        $this->actingAs($this->admin())
            ->postJson('/app/recon/auto/FNB/match', $this->form($s))
            ->assertOk()
            ->assertJson(['ok' => true]);

        // A refusal is that row's answer, not a dead page.
        $this->actingAs($this->admin())
            ->postJson('/app/recon/auto/FNB/match', ['branch_id' => self::BRANCH, 'from' => self::FROM, 'to' => self::TO])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'code' => 'NOTHING_SELECTED']);
    }

    // ---- helpers -----------------------------------------------------------

    /** @return array{suggestions: Collection<int, object>, summary: object|null} */
    private function suggest(): array
    {
        return app(ReconMatchService::class)->suggestions('FNB', self::BRANCH, Carbon::parse(self::FROM), Carbon::parse(self::TO));
    }

    /**
     * The suggestion whose bank side totals this amount.
     *
     * @param  array{suggestions: Collection<int, object>, summary: object|null}|null  $answer
     */
    private function suggestionFor(float $bankTotal, ?array $answer = null, bool $required = true): ?object
    {
        $answer ??= $this->suggest();
        $found = $answer['suggestions']->first(fn (object $s) => round((float) $s->BankTotal, 2) === round($bankTotal, 2));

        if ($required) {
            $this->assertNotNull($found, "No suggestion for a bank side of {$bankTotal}.");
        }

        return $found;
    }

    /** @return array<string, mixed> exactly what the Suggestions tab posts */
    private function form(object $s): array
    {
        return [
            'branch_id' => self::BRANCH,
            'from' => substr((string) $s->MatchFrom, 0, 10),
            'to' => substr((string) $s->MatchTo, 0, 10),
            'period_from' => self::FROM,
            'period_to' => self::TO,
            'back' => 'suggest',
            'basis' => $s->Basis,
            'bank' => $s->bank->pluck('BankStatementLineID')->all(),
            'mops' => $s->mops->map(fn (object $m) => json_encode([
                'id' => null, 'key' => $m->SourceKey,
                'dt' => substr((string) $m->SourceDate, 0, 10), 'amt' => (float) $m->Amount,
            ]))->all(),
        ];
    }

    private function admin(): User
    {
        $user = User::query()->acrossBranches()->where('EmailAddress', 'ryan@revvtech.co.za')->first();

        if (! $user) {
            $this->markTestSkipped('No seeded administrator — run db:seed first.');
        }

        return $user;
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }

    private function seedFixture(): void
    {
        $db = $this->db();

        // The real FNB configuration: batch trailing, merchant at (33, len 6).
        $db->table('PumpIT.dbo.BRN_AutoReconCriteria')->insert([
            'SSBranchId' => self::BRANCH, 'BankReconArea' => 'FNB', 'ProcessOrder' => 1,
            'BANK_StartPosition' => 40, 'BANK_EndPosition' => 3,
            'BANK_StartPosition2' => 33, 'BANK_EndPosition2' => 6,
            'MOPS_StartPosition' => 8, 'MOPS_EndPosition' => 3,
            'FILTER_Value' => null, 'FILTER_StartPosition' => 0, 'FILTER_EndPosition' => 0,
        ]);

        $fn = fn (string $device) => 'SETTLEMENT ACB CREDIT SPEEDPOINT'.$device.'FN';
        $batched = fn (string $batch) => 'SETTLEMENT ACB CREDIT SPEEDPOINT850250 '.$batch;

        $db->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')->insert([
            $this->bankLine('2026-08-04', $fn('00707875'), 3689.76),
            $this->bankLine('2026-08-05', $fn('00707874'), 1432.77),
            $this->bankLine('2026-08-05', $fn('00707874'), 6786.01),
            $this->bankLine('2026-08-11', $fn('00707875'), 11338.63),
            $this->bankLine('2026-08-07', $fn('00200137'), 220.00),
            // Settles on its batch number — the Auto tab's.
            $this->bankLine('2026-08-06', $batched('203'), 500.00),
            // Batch 853 on the statement; the only R150 deposit is batch 451.
            $this->bankLine('2026-08-09', $batched('853'), 150.00),
            // Before the period: the owner of the 2 Aug deposit below.
            $this->bankLine('2026-08-03', $fn('00707876'), 777.77),
            // In the period, same amount, same deposit in reach.
            $this->bankLine('2026-08-05', $fn('00707876'), 777.77),
            // Site 8, 4 Aug, as one line: R18.06 over the day before's takings.
            $this->bankLine('2026-08-08', $fn('00707877'), 19197.34),
        ]);

        $db->table('PumpIT.dbo.BRN_DailyBankingFNB')->insert([
            $this->deposit('2026-08-03', '1', '999999', 3689.76),
            $this->deposit('2026-08-04', '13', '999999', 3152.61),
            $this->deposit('2026-08-04', '13', '999999', 5066.17),
            $this->deposit('2026-08-10', '1', '999999', 4598.49),
            $this->deposit('2026-08-10', '13', '999999', 6740.14),
            $this->deposit('2026-08-06', '0000000894', '016241', 220.00),
            $this->deposit('2026-08-05', '0000000270', '501254', 220.00),
            $this->deposit('2026-08-05', '0000000203', '850250', 500.00),
            $this->deposit('2026-08-08', '0000000451', '850250', 150.00),
            $this->deposit('2026-08-02', '1', '999999', 777.77),
            $this->deposit('2026-08-07', '126', '999999', 19179.28),
            // Within R11 of the R3,689.76 line, which ties exactly elsewhere.
            $this->deposit('2026-08-03', '74', '999999', 3700.00),
        ]);
    }

    /** @return array<string, mixed> */
    private function bankLine(string $date, string $narrative, float $amount): array
    {
        return [
            'SSBranchId' => self::BRANCH, 'LineDate' => $date, 'Description' => $narrative,
            'Amount' => $amount, 'Type' => 'FNB', 'IDState' => 2, 'ReconState' => 1, 'ReconBatchNo' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function deposit(string $date, string $batch, string $merchant, float $amount): array
    {
        return [
            'SSBranchId' => self::BRANCH, 'TransactionDate' => $date, 'BatchNo' => $batch,
            'MerchantNo' => $merchant, 'Amount' => $amount, 'ReconBatchNoPumpIT' => 0,
        ];
    }

    private function cleanUp(): void
    {
        $db = $this->db();

        $db->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')->where('SSBranchId', self::BRANCH)->where('Type', 'FNB')->delete();
        $db->table('PumpIT.dbo.BRN_DailyBankingFNB')->where('SSBranchId', self::BRANCH)->delete();
        $db->table('PumpIT.dbo.BRN_AutoReconCriteria')->where('SSBranchId', self::BRANCH)->where('BankReconArea', 'FNB')->delete();

        $schema = config('agora.schema');
        $runs = ReconRun::query()->acrossBranches()->where('BranchId', self::BRANCH)->where('ReconArea', 'FNB')->pluck('Id');

        foreach (['ReconStamp', 'ReconMatch', 'ReconBatch', 'ReconRunLine'] as $table) {
            $db->table("{$schema}.{$table}")->whereIn('RunId', $runs)->delete();
        }

        ReconRun::query()->acrossBranches()->whereIn('Id', $runs)->delete();
    }
}
