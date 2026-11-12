<?php

namespace Tests\Feature\Core;

use App\Exceptions\AgoraProcException;
use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cash\Services\CashupService;
use Modules\Cash\Services\DeductionService;
use Modules\Cash\Services\DropSafeService;
use Modules\Cash\Services\TillBalanceService;
use Modules\Cash\Services\ZreadService;
use Modules\Core\Database\Seeders\SettingSeeder;
use Modules\Core\Facades\Settings;
use Modules\Core\Models\User;
use Modules\Core\Services\PermissionService;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\SettingCatalogue;
use Modules\Utilities\Services\UtilitiesService;
use ReflectionProperty;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * System defaults and thresholds (T026): agora.Setting, Settings::for() and
 * its fallback, agora.usp_Core_SaveSetting's rules and audit, the copy of
 * PumpIT's SS_SysDefaults / BRN_Config, the Setup screen, and the grep rule
 * that every moved threshold is read through Settings and nowhere else.
 *
 * Runs against the local container only, with every estate connection pinned
 * to it. Writes go to two TEST- branches (976, 977) that exist nowhere else,
 * plus a branch id that does not exist at all (978); the PumpIT stub rows it
 * writes for the copy are TEST_ prefixed. All of it is removed in tearDown.
 */
class SettingsTest extends TestCase
{
    private const SITE = 976;

    private const OTHER = 977;

    private const MISSING = 978;

    private const KEY = 'cash.cashup.variance_tolerance';

    /** @var array<int, User> */
    private array $users = [];

    private bool $safe = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub();
        $local = config('database.connections.'.config('agora.connections.app'));
        foreach (config('agora.connections') as $name) {
            $database = config("database.connections.{$name}.database");
            config(["database.connections.{$name}" => array_replace($local, ['database' => $database])]);
            DB::purge($name);
            $this->assertContains(config("database.connections.{$name}.host"), ['127.0.0.1', 'localhost', '::1']);
        }
        $this->safe = true;
        $this->cleanUp();
        $this->assertTrue($this->db()->table('agora.Setting')->where('BranchId', 2)->where('SettingKey', self::KEY)->exists(),
            'SettingSeeder has run here (php artisan seed:master --only=SettingSeeder).');
        foreach ([self::SITE => 'TEST-Settings site', self::OTHER => 'TEST-Settings other'] as $id => $name) {
            $this->db()->table('agora.Branch')->insert([
                'BranchId' => $id, 'Name' => $name, 'IsTrading' => 1, 'IsActive' => 1, 'SortOrder' => $id,
                'Status' => 'Trading', 'POSType' => 'BRANCH', 'IsAdminEntity' => 0, 'CreatedAt' => now(),
            ]);
        }
        Settings::forget();
    }

    protected function tearDown(): void
    {
        if ($this->safe) {
            $this->cleanUp();
            foreach ($this->users as $user) {
                foreach (['UserPermission', 'UserBranch', 'UserGridColumn', 'UserMenuItem'] as $table) {
                    $this->db()->table('agora.'.$table)->where('UserId', $user->Id)->delete();
                }
                User::query()->acrossBranches()->where('Id', $user->Id)->forceDelete();
            }
        }
        parent::tearDown();
    }

    public function test_a_site_value_beats_the_group_value_and_removing_it_falls_back(): void
    {
        $this->actingAs($this->person(['view', 'edit', 'save', 'delete']));
        $group = (float) Settings::for(null, self::KEY);

        $this->save(self::SITE, 'create', '250');
        $this->assertSame(250.0, Settings::for(self::SITE, self::KEY), 'the site\'s own value wins there');
        $this->assertSame($group, Settings::for(self::OTHER, self::KEY), 'another site follows the group');
        $this->assertSame($group, Settings::for(null, self::KEY), 'null asks for the group');
        $this->assertSame($group, Settings::for(2, self::KEY), 'so does the group entity itself');
        $this->assertSame('250.00', $this->row(self::SITE)->Value, 'money is stored to the cent');

        // The consumers read it for that site, and the group's for any other.
        $cashups = app(CashupService::class);
        $this->assertSame(250.0, $cashups->varianceTolerance(self::SITE));
        $this->assertSame($group, $cashups->varianceTolerance(self::OTHER));
        $this->assertSame('warn', $cashups->varianceTone(200, self::SITE));
        $this->assertSame($group < 200 ? 'crit' : 'warn', $cashups->varianceTone(200, self::OTHER));

        $this->save(self::SITE, 'edit', '275.5', $this->row(self::SITE)->Version);
        $this->assertSame(275.5, Settings::for(self::SITE, self::KEY));

        $id = (int) $this->row(self::SITE)->Id;
        $this->save(self::SITE, 'remove', null, $this->row(self::SITE)->Version);
        $this->assertSame($group, Settings::for(self::SITE, self::KEY), 'removed: the site follows the group again');
        $this->assertNotNull($this->row(self::SITE)->DeletedAt, 'soft-deleted, never deleted');

        $this->save(self::SITE, 'create', '300');
        $this->assertSame($id, (int) $this->row(self::SITE)->Id, 'a second site value revives the removed row');
        $this->assertSame(300.0, Settings::for(self::SITE, self::KEY));

        $audit = $this->db()->table('agora.MasterChange')->where('Entity', 'Setting')->where('EntityId', $id)
            ->where('BranchId', self::SITE)->orderBy('Id')->get(['Field', 'PriorValue', 'NewValue', 'Reason', 'Source'])
            ->map(fn ($c) => [$c->Field, $c->PriorValue, $c->NewValue])->all();
        $this->assertSame([
            ['Override', null, '250.00'],
            ['Value', '250.00', '275.50'],
            ['Override', '275.50', null],
            ['Override', null, '300.00'],
        ], $audit, 'every change is in agora.MasterChange with the value before and after');
    }

    public function test_every_consumer_reads_the_site_value(): void
    {
        $this->actingAs($this->person(['save']));
        foreach ([
            'cash.zread.certain_share' => '0.8',
            'cash.zread.history_days' => '120',
            'cash.zread.auto_accept_confidence' => 'Likely',
            'cash.tillbalance.variance_floor' => '5',
            'utilities.zero_usage_run_days.default' => '5',
            'utilities.zero_usage_run_days.L' => '30',
            'recon.suggest.max_lag_days' => '3',
        ] as $key => $value) {
            $this->save(self::SITE, 'create', $value, null, $key);
        }

        $zread = app(ZreadService::class);
        $this->assertSame(0.8, $zread->priors(self::SITE)['CertainShare']);
        $this->assertSame(120, $zread->priors(self::SITE)['HistoryDays']);
        $this->assertSame('likely', $zread->threshold(self::SITE), 'a choice is stored as the list spells it');
        $this->assertSame((float) config('cash.zread.certain_share'), $zread->priors(self::OTHER)['CertainShare']);
        $this->assertSame(5.0, app(TillBalanceService::class)->varianceFloor(self::SITE));

        $utilities = app(UtilitiesService::class);
        $this->assertSame(['default' => 5, 'L' => 30, 'M' => null], $utilities->zeroRunDays(self::SITE));
        $this->assertSame(['default' => 3, 'L' => null, 'M' => null], $utilities->zeroRunDays(self::OTHER));
        $this->assertSame(3, Settings::for(self::SITE, 'recon.suggest.max_lag_days'));

        // One value for the estate: whatever site is asked about, the group's.
        $this->assertSame((float) Settings::for(null, 'cash.dropsafe.reason_threshold'), app(DropSafeService::class)->threshold(self::SITE));
        $this->assertSame((float) Settings::for(null, 'cash.deductions.cash_leniency'), app(DeductionService::class)->leniency(self::SITE));
    }

    public function test_the_seeded_group_values_are_the_module_config_literals(): void
    {
        $rows = $this->db()->table('agora.Setting')->where('BranchId', 2)->whereNull('DeletedAt')->get()->keyBy('SettingKey');

        foreach (SettingCatalogue::all() as $key => $definition) {
            $this->assertTrue($rows->has($key), "{$key} is seeded on the group entity");
            $row = $rows[$key];
            $this->assertSame($definition['type'], $row->Type, $key);
            $this->assertSame((int) ($definition['branch'] ?? true), (int) $row->AllowsBranch, $key);
            if ($row->Source !== 'edit') {
                $this->assertSame(SettingSeeder::normalise($definition['type'], SettingCatalogue::default($key)), $row->Value,
                    "{$key}: the seeded value is the default it was read from before T026");
            }
        }

        // The figures the plan names, where the code had them. Where the code
        // differed from the plan, the code's value was kept (recon window 4, not 3).
        $this->assertSame('2000.00', $rows['cash.dropsafe.reason_threshold']->Value);
        $this->assertSame('3.00', $rows['cash.deductions.cash_leniency']->Value);
        $this->assertSame('15.000', $rows['fuel.pump_variance_litres']->Value);
        $this->assertSame('certain', $rows['cash.zread.auto_accept_confidence']->Value);
        $this->assertSame('4', $rows['recon.suggest.max_lag_days']->Value);
    }

    public function test_with_no_row_the_code_default_answers_and_follows_the_config_literal(): void
    {
        $service = new SettingService(app(ProcedureService::class));
        (new ReflectionProperty(SettingService::class, 'rows'))->setValue($service, []);

        $this->assertSame(2000.0, $service->for(self::SITE, 'cash.dropsafe.reason_threshold'));
        config(['cash.dropsafe.reason_threshold' => 1234.5]);
        $this->assertSame(1234.5, $service->for(self::SITE, 'cash.dropsafe.reason_threshold'), 'the default is the config literal');
        $this->assertNull($service->for(null, 'utilities.tariff.B'));
        $this->assertSame(['B' => null, 'L' => null, 'R' => null, 'S' => null], $service->prefixed(null, 'utilities.tariff.'));

        $this->expectException(\InvalidArgumentException::class);
        $service->for(null, 'cash.no_such_setting');
    }

    public function test_the_procedure_refuses_what_the_definition_does_not_allow(): void
    {
        $this->actingAs($this->person(['view', 'edit', 'save', 'delete']));

        $this->assertRefused('SETTING_NO_OVERRIDE', fn () => $this->save(self::SITE, 'create', '2500', null, 'cash.dropsafe.reason_threshold'));
        $this->assertRefused('SETTING_NO_OVERRIDE', fn () => $this->save(self::SITE, 'create', '5', null, 'cash.deductions.cash_leniency'));
        $this->assertRefused('SETTING_READ_ONLY', fn () => $this->save(2, 'edit', '9', 'x', 'pumpit.SS_SysDefaults.ABSADaysTOADD'));
        $this->assertRefused('SETTING_GROUP_ROW', fn () => $this->save(2, 'create', '9'));
        $this->assertRefused('SETTING_BRANCH', fn () => $this->save(self::MISSING, 'create', '9'));
        $this->assertRefused('SETTING_MISSING', fn () => $this->save(self::SITE, 'create', '9', null, 'cash.no_such_setting'));
        $this->assertRefused('SETTING_TYPE', fn () => $this->save(self::SITE, 'create', 'lots'));
        $this->assertRefused('SETTING_TYPE', fn () => $this->save(self::SITE, 'create', '10.005'));
        $this->assertRefused('SETTING_TYPE', fn () => $this->save(self::SITE, 'create', '7.5', null, 'cash.zread.history_days'));
        $this->assertRefused('SETTING_RANGE', fn () => $this->save(self::SITE, 'create', '-1'));
        $this->assertRefused('SETTING_RANGE', fn () => $this->save(self::SITE, 'create', '1.2', null, 'cash.zread.certain_share'));
        $this->assertRefused('SETTING_RANGE', fn () => $this->save(self::SITE, 'create', '0.4', null, 'cash.zread.certain_share'));
        $this->assertRefused('SETTING_CHOICE', fn () => $this->save(self::SITE, 'create', 'maybe', null, 'cash.zread.auto_accept_confidence'));
        $this->assertRefused('SETTING_REQUIRED', fn () => $this->save(self::SITE, 'create', ''));
        $this->assertRefused('SETTING_REASON', fn () => $this->save(self::SITE, 'create', '200', null, self::KEY, 'short'));
        $this->assertRefused('SETTING_MISSING', fn () => $this->save(self::SITE, 'edit', '200', '0x00'));

        $this->save(self::SITE, 'create', '200');
        $stale = $this->row(self::SITE)->Version;
        $this->save(self::SITE, 'edit', '210', $stale);
        $this->assertRefused('SETTING_STALE', fn () => $this->save(self::SITE, 'edit', '220', $stale));
        $this->assertRefused('SETTING_EXISTS', fn () => $this->save(self::SITE, 'create', '230'));
        $this->assertSame(210.0, Settings::for(self::SITE, self::KEY), 'nothing refused was written');

        // A nullable setting takes empty as "none".
        $this->save(self::SITE, 'create', '', null, 'utilities.zero_usage_run_days.default');
        $this->assertNull(Settings::for(self::SITE, 'utilities.zero_usage_run_days.default'));

        // Without the permission the procedure refuses on its own.
        $this->actingAs($this->person(['view']));
        $this->assertRefused('SETTING_DENIED', fn () => $this->save(self::OTHER, 'create', '200'));
    }

    public function test_pumpit_settings_are_copied_once_read_only_and_never_with_credentials_or_cursors(): void
    {
        $columns = collect($this->db()->select("SELECT name FROM sys.columns WHERE object_id = OBJECT_ID('agora.vw_LegacySysDefault')"))
            ->pluck('name')->sort()->values()->all();
        $this->assertSame(['ColumnName', 'Description', 'Value'], $columns);
        $names = collect($this->db()->select('SELECT ColumnName FROM agora.vw_LegacySysDefault'))->pluck('ColumnName');
        $this->assertEmpty($names->filter(fn ($n) => preg_match('/smtp|ftp|password|pass|user/i', $n))->all(), 'no credential leaves PumpIT');

        $pumpit = DB::connection(config('agora.connections.erp'));
        // One row at a time: a multi-row VALUES takes its column types from the first row.
        foreach ([
            ['SSBranchId' => self::SITE, 'ConfigDescription' => 'TEST_SettingFlag', 'ValueType' => 'Yes\\No', 'IValue' => 0, 'SValue' => '', 'BValue' => 1, 'Description' => 'TEST flag'],
            ['SSBranchId' => self::SITE, 'ConfigDescription' => 'TEST_SettingName', 'ValueType' => 'S', 'IValue' => 0, 'SValue' => ' Cash Connect ', 'BValue' => 0, 'Description' => null],
            ['SSBranchId' => self::SITE, 'ConfigDescription' => 'TEST_SettingCents', 'ValueType' => 'Number', 'IValue' => 12.5, 'SValue' => '', 'BValue' => 0, 'Description' => null],
            ['SSBranchId' => self::SITE, 'ConfigDescription' => 'LastBranchImportedDay', 'ValueType' => 'I', 'IValue' => 7091, 'SValue' => '', 'BValue' => 0, 'Description' => 'cursor'],
            ['SSBranchId' => self::MISSING, 'ConfigDescription' => 'TEST_SettingFlag', 'ValueType' => 'B', 'IValue' => 0, 'SValue' => '', 'BValue' => 1, 'Description' => null],
        ] as $row) {
            $pumpit->table('dbo.BRN_Config')->insert($row);
        }

        $status = app(SettingService::class)->migrate();
        $this->assertStringContainsString('3 PumpIT settings copied', $status->Message);
        $this->assertStringContainsString('1 for branches Agora does not have', $status->Message);

        $copied = $this->db()->table('agora.Setting')->where('BranchId', self::SITE)->where('SettingKey', 'like', 'pumpit.%')
            ->orderBy('SettingKey')->get()->keyBy('SettingKey');
        $this->assertSame(['pumpit.BRN_Config.TEST_SettingCents', 'pumpit.BRN_Config.TEST_SettingFlag', 'pumpit.BRN_Config.TEST_SettingName'],
            $copied->keys()->all(), 'the import cursor is PumpIT\'s moving state, not configuration');
        $this->assertSame(['decimal', '12.5000'], [$copied['pumpit.BRN_Config.TEST_SettingCents']->Type, $copied['pumpit.BRN_Config.TEST_SettingCents']->Value]);
        $this->assertSame(['bool', '1'], [$copied['pumpit.BRN_Config.TEST_SettingFlag']->Type, $copied['pumpit.BRN_Config.TEST_SettingFlag']->Value]);
        $this->assertSame(['string', 'Cash Connect'], [$copied['pumpit.BRN_Config.TEST_SettingName']->Type, $copied['pumpit.BRN_Config.TEST_SettingName']->Value]);
        $this->assertSame('pumpit', $copied['pumpit.BRN_Config.TEST_SettingFlag']->Source);
        $this->assertSame('BRN_Config.TEST_SettingFlag', $copied['pumpit.BRN_Config.TEST_SettingFlag']->LegacySource);
        $this->assertTrue(Settings::for(self::SITE, 'pumpit.BRN_Config.TEST_SettingFlag'));
        $this->assertNull(Settings::for(self::OTHER, 'pumpit.BRN_Config.TEST_SettingFlag'), 'kept per branch; no group value');
        $this->assertSame(3, $this->db()->table('agora.MasterChange')->where('Entity', 'Setting')->where('BranchId', self::SITE)
            ->where('Source', 'migrate')->count());

        $this->assertStringContainsString('0 PumpIT settings copied', app(SettingService::class)->migrate()->Message, 'a re-run copies nothing');

        $this->actingAs($this->person(['edit']));
        $this->assertRefused('SETTING_READ_ONLY', fn () => $this->save(self::SITE, 'edit', '0', 'x', 'pumpit.BRN_Config.TEST_SettingFlag'));
    }

    public function test_the_screen_lists_shows_and_changes_behind_separate_permissions(): void
    {
        $this->actingAs($this->person([]))->get(route('app.setup.settings.index'))->assertRedirect(route('app.access-required'));

        $this->actingAs($this->person(['view']));
        $this->get(route('app.setup.settings.index'))->assertOk()->assertSee('System defaults and thresholds')
            ->assertSee('cash.dropsafe.reason_threshold')->assertSee('usp_Core_GridSettings');
        $csv = $this->get(route('app.grids.extract', ['grid' => 'app.setup.settings']))->assertOk()->streamedContent();
        $this->assertStringContainsString('cash.deductions.cash_leniency', $csv);
        $this->get(route('app.setup.settings.show', ['key' => self::KEY]))->assertOk()->assertSee('Group value')->assertSee('R150.00', false);
        $this->get(route('app.setup.settings.show', ['key' => self::KEY]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertDontSee('<html', false)->assertDontSee('Give a site its own value');
        $this->get(route('app.setup.settings.show', ['key' => 'cash.no_such_setting']))->assertNotFound();
        $this->get(route('app.setup.settings.overrides.create', ['key' => self::KEY]))->assertForbidden();
        $this->post(route('app.setup.settings.overrides.store', ['key' => self::KEY]), $this->input())->assertForbidden();

        $this->actingAs($this->person(['view', 'save']));
        $this->get(route('app.setup.settings.overrides.create', ['key' => self::KEY]))->assertOk()->assertSee('TEST-Settings site');
        $this->get(route('app.setup.settings.overrides.create', ['key' => 'cash.dropsafe.reason_threshold']))->assertNotFound();
        $this->post(route('app.setup.settings.overrides.store', ['key' => self::KEY]), $this->input(['Value' => '180']))
            ->assertRedirect(route('app.setup.settings.show', ['key' => self::KEY]));
        $this->assertSame(180.0, Settings::for(self::SITE, self::KEY));
        $this->get(route('app.setup.settings.edit', ['key' => self::KEY, 'branch' => self::SITE]))->assertForbidden();

        $this->actingAs($this->person(['view', 'edit', 'delete']));
        $this->get(route('app.setup.settings.show', ['key' => self::KEY]))->assertOk()->assertSee('TEST-Settings site')->assertSee('R180.00', false);
        $this->get(route('app.setup.settings.edit', ['key' => self::KEY, 'branch' => self::SITE]))->assertOk()->assertSee('name="ExpectedVersion"', false);
        $version = $this->row(self::SITE)->Version;
        $this->put(route('app.setup.settings.update', ['key' => self::KEY]), $this->input(['Value' => '190', 'ExpectedVersion' => $version]))
            ->assertRedirect(route('app.setup.settings.show', ['key' => self::KEY]));
        $this->assertSame(190.0, Settings::for(self::SITE, self::KEY));
        $this->from(route('app.setup.settings.edit', ['key' => self::KEY, 'branch' => self::SITE]))
            ->put(route('app.setup.settings.update', ['key' => self::KEY]), $this->input(['Value' => '200', 'ExpectedVersion' => $version]))
            ->assertSessionHasErrors('refusal');
        $this->from(route('app.setup.settings.show', ['key' => self::KEY]))
            ->put(route('app.setup.settings.update', ['key' => self::KEY]), $this->input(['Reason' => 'short', 'ExpectedVersion' => $version]))
            ->assertSessionHasErrors('Reason');

        $this->delete(route('app.setup.settings.overrides.destroy', ['key' => self::KEY, 'branch' => self::SITE]),
            ['ExpectedVersion' => $this->row(self::SITE)->Version, 'Reason' => 'TEST-site back on the group value'])
            ->assertRedirect(route('app.setup.settings.show', ['key' => self::KEY]));
        $this->assertSame((float) Settings::for(null, self::KEY), Settings::for(self::SITE, self::KEY));
        $this->get(route('app.setup.settings.show', ['key' => self::KEY]))->assertOk()->assertSee('TEST-site back on the group value');

        // A copy of PumpIT's own value opens, says so, and offers no change.
        $this->get(route('app.setup.settings.show', ['key' => 'pumpit.SS_SysDefaults.ABSADaysTOADD']))->assertOk()
            ->assertSee('read-only')->assertDontSee('Change group value');
        $this->get(route('app.setup.settings.edit', ['key' => 'pumpit.SS_SysDefaults.ABSADaysTOADD']))->assertNotFound();
    }

    /**
     * The plan's grep rule: every threshold moved to agora.Setting is read
     * through Settings and nowhere else. A config() read of one of these keys
     * outside the module's config file and the catalogue is the old path back.
     */
    public function test_no_module_reads_a_moved_threshold_from_config(): void
    {
        $keys = collect(SettingCatalogue::all())->pluck('config')->filter()
            ->merge(['utilities.tariffs', 'utilities.zero_usage_run_days'])->unique();
        $offenders = [];

        foreach ((new Finder)->files()->in(base_path('Modules'))->name(['*.php'])->notPath('Config') as $file) {
            if ($file->getFilename() === 'SettingCatalogue.php') {
                continue;
            }
            $text = $file->getContents();
            foreach ($keys as $key) {
                if (preg_match('/config\(\s*[\'"]'.preg_quote($key, '/').'[\'"]/', $text)) {
                    $offenders[] = $file->getRelativePathname().' reads '.$key;
                }
            }
        }

        $this->assertSame([], $offenders, 'Read these through Settings::for() instead.');

        // And the named-write switches stay config literals (CLAUDE.md rule 1).
        foreach (array_keys(SettingCatalogue::all()) as $key) {
            $this->assertDoesNotMatchRegularExpression('/(stamp|write|issue|adjust)_mode|reset_issues/', $key);
        }
    }

    // ---- helpers -----------------------------------------------------------------

    private function save(int $branchId, string $mode, ?string $value, ?string $version = null, string $key = self::KEY,
        string $reason = 'TEST-settings change for the test'): object
    {
        return app(SettingService::class)->save($key, $branchId, $mode, $value, $version, $reason);
    }

    private function assertRefused(string $code, callable $press): void
    {
        try {
            $press();
        } catch (AgoraProcException $e) {
            $this->assertSame($code, $e->code(), $e->getMessage());

            return;
        }
        $this->fail("Expected a {$code} refusal.");
    }

    private function row(int $branchId, string $key = self::KEY): object
    {
        return $this->db()->selectOne('SELECT Id, Value, DeletedAt, CONVERT(varchar(18), CONVERT(binary(8), RowVer), 1) AS Version
            FROM agora.Setting WHERE BranchId = ? AND SettingKey = ?', [$branchId, $key]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_replace(['BranchId' => self::SITE, 'Value' => '175', 'Reason' => 'TEST-site runs a bigger float'], $overrides);
    }

    /** @param  array<int, string>  $actions  under setup.settings */
    private function person(array $actions): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => 2, 'EmailAddress' => 'TEST-settings-'.Str::uuid().'@agora.invalid', 'UserName' => 'TEST-Settings',
            'IsActive' => true, 'IsLocked' => false, 'Workspace' => 'ho', 'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);
        $this->users[] = $user;
        foreach ($actions as $action) {
            $permission = $this->db()->table('agora.Permission')->where('Code', 'setup.settings.'.$action)->value('Id');
            $this->assertNotNull($permission, "setup.settings.{$action} is seeded");
            $this->db()->table('agora.UserPermission')->insert(['BranchId' => 2, 'UserId' => $user->Id, 'PermissionId' => $permission]);
        }
        app(PermissionService::class)->forget($user);
        app(BranchContext::class)->setWorkspace('ho')->setAllowed([]);
        Settings::forget();

        return $user;
    }

    private function cleanUp(): void
    {
        $branches = [self::SITE, self::OTHER, self::MISSING];
        $this->db()->table('agora.MasterChange')->where('Entity', 'Setting')->whereIn('BranchId', $branches)->delete();
        $this->db()->table('agora.Setting')->whereIn('BranchId', $branches)->delete();
        $this->db()->table('agora.Branch')->whereIn('BranchId', [self::SITE, self::OTHER])->delete();
        DB::connection(config('agora.connections.erp'))->table('dbo.BRN_Config')->whereIn('SSBranchId', $branches)->delete();
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
