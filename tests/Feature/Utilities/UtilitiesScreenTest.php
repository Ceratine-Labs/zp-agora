<?php

namespace Tests\Feature\Utilities;

use App\Grid\GridRegistry;
use Illuminate\Support\Str;
use Modules\Core\Models\Permission;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPermission;
use Modules\Core\Services\PermissionService;
use Modules\Utilities\Grids\UtilityReadingGrid;
use Tests\TestCase;

/**
 * The utilities screen and the boundary around its one write (T056).
 *
 * UtilityReadingsTest covers what the procedures compute and refuse. This
 * covers the layer above: who may open the screen, who is offered the form,
 * who may change a reading that was already captured, and whether a refusal
 * reaches the person who typed the value instead of becoming a 500.
 *
 * Three permissions, one per action (feature-rules §4): view, save, edit.
 * The people here are granted them BY CODE, not by profile, so a change to
 * who holds what elsewhere cannot quietly change what this test proves.
 */
class UtilitiesScreenTest extends TestCase
{
    use UtilitiesFixture;

    /** @var array<int, User> */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub('This fixture writes meter readings into the PumpIT stub.');

        if (Permission::query()->acrossBranches()->where('Code', 'utilities.readings.view')->doesntExist()) {
            $this->markTestSkipped('Run: php artisan seed:master --only=UtilitiesPermissionSeeder');
        }

        // The line config/grids.php carries once this lane is merged. Until
        // then the screen cannot find its grid; the registration itself is
        // asserted by test_the_readings_grid_is_registered below, which does
        // NOT add it.
        if (! app(GridRegistry::class)->has('app.utilities.index')) {
            config(['grids.grids' => config('grids.grids') + ['app.utilities.index' => UtilityReadingGrid::class]]);
        }

        config([
            'utilities.write_mode' => 'live',
            'utilities.tariffs' => ['B' => 2.5],
            'utilities.zero_usage_run_days' => ['default' => 3, 'L' => null, 'M' => null],
        ]);
        $this->cleanUpUtilities();
        $this->seedMeters();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUpUtilities();

            foreach ($this->made as $user) {
                UserPermission::query()->acrossBranches()->where('UserId', $user->Id)->delete();
                $this->db()->table('agora.UserGridColumn')->where('UserId', $user->Id)->delete();
                User::query()->acrossBranches()->where('Id', $user->Id)->forceDelete();
            }
        }

        parent::tearDown();
    }

    public function test_the_readings_grid_is_registered(): void
    {
        // Fails until config/grids.php carries
        //   'app.utilities.index' => Modules\Utilities\Grids\UtilityReadingGrid::class,
        // — the extract and the saved column layout both find a grid by key.
        $this->assertSame(
            UtilityReadingGrid::class,
            (require base_path('config/grids.php'))['grids']['app.utilities.index'] ?? null,
        );
    }

    public function test_a_reader_sees_the_month_and_the_procedures_but_no_form(): void
    {
        $this->reading('2026-08-01', 100);
        $this->reading('2026-08-02', 110);

        $this->actingAs($this->person(['view']))
            ->get(route('app.utilities.index', ['branch_id' => self::BRANCH, 'date' => '2026-08-02']))
            ->assertOk()
            ->assertSee('Readings for Sunday 2 August')
            ->assertSee('TEST-Electricity Main')
            ->assertSee('agora.usp_Utilities_PrepareReadings')
            ->assertSee('usp_Utilities_GridReadings')
            ->assertSee('Read-only for you')
            ->assertDontSee('Record readings');
    }

    public function test_somebody_who_may_save_is_offered_a_box_per_meter_and_can_record_a_reading(): void
    {
        $this->reading('2026-08-01', 100);
        $person = $this->person(['view', 'save']);

        $this->actingAs($person)
            ->get(route('app.utilities.index', ['branch_id' => self::BRANCH, 'date' => '2026-08-02']))
            ->assertOk()
            ->assertSee('Record readings')
            ->assertSee('name="lines[0][ClosingReading]"', false)
            ->assertSee('Last read 100.00 on 1 Aug');

        $this->actingAs($person)
            ->post(route('app.utilities.save'), [
                'branch_id' => self::BRANCH,
                'date' => '2026-08-02',
                'lines' => [
                    ['UtilityType' => 'B', 'MeterNo' => self::ELECTRIC, 'TransactionDate' => '2026-08-02', 'ClosingReading' => '104', 'PrepaidUnitsPurchased' => ''],
                    // Not read today: left empty, so not sent at all.
                    ['UtilityType' => 'S', 'MeterNo' => self::WATER, 'TransactionDate' => '2026-08-02', 'ClosingReading' => '', 'PrepaidUnitsPurchased' => ''],
                ],
            ])
            ->assertRedirect(route('app.utilities.index', ['branch_id' => self::BRANCH, 'date' => '2026-08-02']))
            ->assertSessionHas('status');

        $this->assertCount(1, $this->stored('2026-08-02', 'B'));
        $this->assertCount(0, $this->stored('2026-08-02', 'S'), 'A blank box is a meter nobody read, not a zero.');
        $this->assertSame((int) $person->Id,
            (int) $this->db()->table('agora.UtilityReadingSave')->where('BranchId', self::BRANCH)->value('CreatedBy'),
            'PumpIT has no user column; Agora\'s record is the only place that says who captured it.');
    }

    public function test_without_edit_a_captured_reading_is_shown_not_offered_and_the_procedure_refuses_it_anyway(): void
    {
        $this->reading('2026-08-01', 100);
        $this->reading('2026-08-02', 110);
        $person = $this->person(['view', 'save']);

        $this->actingAs($person)
            ->get(route('app.utilities.index', ['branch_id' => self::BRANCH, 'date' => '2026-08-02']))
            ->assertOk()
            ->assertSee('changing it needs')
            ->assertDontSee('value="110"', false);

        // A hand-made POST gets past the missing box. It does not get past
        // the procedure, and the refusal comes back as words.
        $this->actingAs($person)
            ->from(route('app.utilities.index', ['branch_id' => self::BRANCH, 'date' => '2026-08-02']))
            ->post(route('app.utilities.save'), [
                'branch_id' => self::BRANCH,
                'date' => '2026-08-02',
                'lines' => [['UtilityType' => 'B', 'MeterNo' => self::ELECTRIC, 'TransactionDate' => '2026-08-02', 'ClosingReading' => '111']],
            ])
            ->assertRedirect()
            ->assertSessionHas('refusalCode', 'AMEND_NOT_ALLOWED');

        $this->assertEqualsWithDelta(110.0, (float) $this->stored('2026-08-02')[0]->ClosingReading, 0.0001);
    }

    public function test_with_edit_the_captured_reading_is_open_and_the_reason_is_asked_for(): void
    {
        $this->reading('2026-08-01', 100);
        $this->reading('2026-08-02', 110);

        $this->actingAs($this->person(['view', 'save', 'edit']))
            ->get(route('app.utilities.index', ['branch_id' => self::BRANCH, 'date' => '2026-08-02']))
            ->assertOk()
            ->assertSee('value="110"', false)
            ->assertSee('name="reason"', false);
    }

    public function test_days_not_yet_read_leave_the_month_reading_sensibly(): void
    {
        // A 0 / 0 day is not a reading (Ryan, 01a0e312-07a0). Before that
        // decision this month read 0 - 100 = -100 and the screen carried a
        // warning to say so; now the month is the 10 units actually used.
        $this->reading('2026-07-31', 90);
        $this->reading('2026-08-01', 100);
        $this->reading('2026-08-02', 0);
        $this->reading('2026-08-03', 0);

        $this->actingAs($this->person(['view']))
            ->get(route('app.utilities.index', ['branch_id' => self::BRANCH, 'date' => '2026-08-03']))
            ->assertOk()
            ->assertSee('A day PumpIT holds as 0 / 0 has not been read')
            ->assertSee('2 held in PumpIT as 0 / 0, not read')
            ->assertSee('<td class="num">10.00</td>', false)
            ->assertDontSee('read as zero, unread')
            ->assertDontSee('-90.00');
    }

    public function test_the_standing_still_rule_is_on_the_screen(): void
    {
        config(['utilities.zero_usage_run_days' => ['default' => 3, 'L' => null, 'M' => null]]);

        $this->actingAs($this->person(['view']))
            ->get(route('app.utilities.index', ['branch_id' => self::BRANCH, 'date' => '2026-08-03']))
            ->assertOk()
            ->assertSee('Flagged after 3 days without moving, or without a reading; never for meter types L and M.');
    }

    public function test_the_last_agora_save_is_named_with_its_time_read_as_utc(): void
    {
        $this->reading('2026-08-01', 100);
        $person = $this->person(['view', 'save']);

        $this->actingAs($person)->post(route('app.utilities.save'), [
            'branch_id' => self::BRANCH,
            'date' => '2026-08-02',
            'lines' => [['UtilityType' => 'B', 'MeterNo' => self::ELECTRIC, 'TransactionDate' => '2026-08-02', 'ClosingReading' => '104']],
        ])->assertSessionHas('status');

        // "ago", never "from now": the procedure stamps SYSUTCDATETIME() and
        // the screen parses it as UTC. A local stamp on the +02:00 server read
        // "2 hours from now" on the recon screens until 24 Sep 2026.
        $this->actingAs($person)
            ->get(route('app.utilities.index', ['branch_id' => self::BRANCH, 'date' => '2026-08-02']))
            ->assertOk()
            ->assertSee('Last recorded through Agora by TEST-utilities')
            ->assertSee('ago.')
            ->assertDontSee('from now');
    }

    public function test_with_no_site_chosen_the_screen_asks_for_one_and_still_lists_readings(): void
    {
        $this->actingAs($this->person(['view']))
            ->get(route('app.utilities.index'))
            ->assertOk()
            ->assertSee('Choose a site')
            ->assertSee('Readings are recorded one site at a time')
            ->assertSee('Utility meter readings')
            ->assertDontSee('Readings for ');
    }

    public function test_a_future_day_is_brought_back_to_today(): void
    {
        $this->actingAs($this->person(['view']))
            ->get(route('app.utilities.index', ['branch_id' => self::BRANCH, 'date' => now()->addDays(3)->toDateString()]))
            ->assertOk()
            ->assertSee('Readings for '.now()->format('l j F'));
    }

    public function test_nobody_without_the_view_permission_reaches_the_screen_or_the_save(): void
    {
        $person = $this->person([]);

        $this->actingAs($person)->get(route('app.utilities.index'))->assertForbidden();
        $this->actingAs($person)->post(route('app.utilities.save'), ['branch_id' => self::BRANCH])->assertForbidden();
    }

    public function test_a_reader_may_not_post_a_reading(): void
    {
        $this->actingAs($this->person(['view']))
            ->post(route('app.utilities.save'), [
                'branch_id' => self::BRANCH,
                'date' => '2026-08-02',
                'lines' => [['UtilityType' => 'B', 'MeterNo' => self::ELECTRIC, 'TransactionDate' => '2026-08-02', 'ClosingReading' => '120']],
            ])
            ->assertForbidden();

        $this->assertCount(0, $this->stored('2026-08-02'));
    }

    public function test_a_save_that_leaves_a_meter_standing_still_says_so_on_the_way_back(): void
    {
        foreach (['01', '02', '03'] as $d) {
            $this->reading("2026-08-{$d}", 500);
        }

        $this->actingAs($this->person(['view', 'save']))
            ->post(route('app.utilities.save'), [
                'branch_id' => self::BRANCH,
                'date' => '2026-08-04',
                'lines' => [['UtilityType' => 'B', 'MeterNo' => self::ELECTRIC, 'TransactionDate' => '2026-08-04', 'ClosingReading' => '500']],
            ])
            ->assertSessionHas('flagged', fn (array $flags) => $flags === ['TEST-Electricity Main has not moved for 3 days']);
    }

    /* ------------------------------------------------------------------ */

    /** @param array<int, string> $actions view|save|edit */
    private function person(array $actions): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => (int) config('agora.group_branch_id', 2),
            'EmailAddress' => 'TEST-t056-'.uniqid().'@agora.invalid',
            'UserName' => 'TEST-utilities',
            'IsActive' => true,
            'IsLocked' => false,
            'Workspace' => 'ho',
            'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);

        foreach ($actions as $action) {
            $permission = Permission::query()->acrossBranches()
                ->where('Code', Permission::code('utilities', 'readings', $action))->firstOrFail();

            UserPermission::query()->acrossBranches()->create([
                'BranchId' => (int) $user->BranchId,
                'UserId' => (int) $user->Id,
                'PermissionId' => (int) $permission->Id,
            ]);
        }

        app(PermissionService::class)->forget($user);
        $this->made[] = $user;

        return $user;
    }
}
