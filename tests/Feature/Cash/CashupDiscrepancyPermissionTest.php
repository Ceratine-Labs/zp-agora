<?php

namespace Tests\Feature\Cash;

use Modules\Cash\Database\Seeders\CashupDiscrepancyPermissionSeeder;
use Modules\Core\Models\Permission;
use Modules\Core\Models\RolePermission;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPermission;
use Modules\Core\Services\PermissionService;
use Modules\Core\Services\RoleAccessService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/** The alert is restricted; the figures and posting safeguards are unchanged. */
class CashupDiscrepancyPermissionTest extends TestCase
{
    use CashupFixture {
        setUp as private setUpCashup;
        tearDown as private tearDownCashup;
    }

    private bool $createdPermission = false;

    private bool $createdBranch = false;

    protected function setUp(): void
    {
        $this->setUpCashup();
        $this->createdPermission = ! Permission::query()->acrossBranches()->where('Code', 'cash.discrepancy.view')->exists();
        app(CashupDiscrepancyPermissionSeeder::class)->run();
        // A disagreement with an otherwise ordinary, small variance.
        $this->db()->update("UPDATE PumpIT.dbo.DBF_P3TRANS_ZREAD SET Sales = 999 WHERE SSBranchId = ? AND USERID = 'CUA'", [self::BRANCH]);
        $this->createdBranch = ! $this->db()->table('PumpIT.dbo.SS_Branch')->where('SSBranchId', self::BRANCH)->exists();
        if ($this->createdBranch) {
            $this->db()->table('PumpIT.dbo.SS_Branch')->insert(['SSBranchId' => self::BRANCH, 'BranchName' => 'TEST-Cashup permission', 'IsActive' => 0]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            if ($this->createdBranch) {
                $this->db()->table('PumpIT.dbo.SS_Branch')->where('SSBranchId', self::BRANCH)->delete();
            }
            // Remove test people/grants first, then the permission if this test registered it.
            if ($this->createdPermission) {
                $permissionId = Permission::query()->acrossBranches()->where('Code', 'cash.discrepancy.view')->value('Id');
                UserPermission::query()->acrossBranches()->where('PermissionId', $permissionId)->delete();
                Permission::query()->acrossBranches()->whereKey($permissionId)->delete();
            }
        }
        $this->tearDownCashup();
    }

    /** A normal operator explicitly allowed the cashup functions, without alert access. */
    private function operator(): User
    {
        $person = $this->person('admin');
        UserPermission::query()->acrossBranches()->where('UserId', $person->Id)->delete();
        foreach (['cash.cashup.view', 'cash.cashup.confirm', 'cash.cashup.close', 'cash.cashup.save', 'reports.daily-banking.view'] as $code) {
            $this->grant($person, $code);
        }

        return $person;
    }

    private function grant(User $person, string $code): void
    {
        $permission = Permission::query()->acrossBranches()->where('Code', $code)->firstOrFail();
        UserPermission::query()->acrossBranches()->firstOrCreate([
            'BranchId' => $person->BranchId, 'UserId' => $person->Id, 'PermissionId' => $permission->Id,
        ]);
        app(PermissionService::class)->forget($person);
    }

    /** @return array<string, int|string> */
    private function bankingScope(): array
    {
        return ['branch_id' => self::BRANCH, 'date' => self::DAY, 'till' => 41, 'shift' => 1];
    }

    /** @return array<string, int|string> */
    private function gridScope(): array
    {
        return ['branches' => self::BRANCH, 'from' => self::DAY, 'to' => self::DAY];
    }

    public function test_denied_operator_keeps_figures_but_no_alerts_on_desktop_row_mobile_or_report(): void
    {
        $this->actingAs($this->operator());
        $banking = $this->get(route('app.cash.banking', $this->bankingScope()))->assertOk()
            ->assertSee('1 008.00')->assertSee('1 000.00')->assertSee('Z-reads with no cashup')
            ->assertDontSee('Z-read disagrees')->assertDontSee('Z-read differs')
            ->assertDontSee('The Z-read allocated to this till and shift says');
        $banking->assertViewHas('summary', fn ($summary) => ! array_key_exists('disagree', $summary));
        $doc = new \DOMDocument;
        @$doc->loadHTML($banking->getContent());
        $xpath = new \DOMXPath($doc);
        $noCashup = $xpath->query('//table[.//th[normalize-space(.)="Sales"]]')->item(0);
        $this->assertNotNull($noCashup);
        $this->assertSame(5, $xpath->query('.//thead/tr/th', $noCashup)->length);
        $this->assertSame(5, $xpath->query('.//tbody/tr[1]/td', $noCashup)->length);
        $this->assertSame('Z-read', trim($xpath->query('.//thead/tr/th[4]', $noCashup)->item(0)->textContent));
        $this->get(route('app.cash.cashups.row', $this->bankingScope()), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee('1 008.00')->assertDontSee('Z-read differs')
            ->assertDontSee('The Z-read allocated to this till and shift says');
        $this->get(route('app.cash.banking.phone', $this->bankingScope()))->assertOk()
            ->assertSee('1 008.00')->assertDontSee('Z-read: z-read differs');
        $this->get(route('app.cash.cashups', $this->gridScope()))->assertOk()
            ->assertDontSee('Z-read disagrees')->assertDontSee('Z-read differs')->assertDontSee('data-column="ZreadCheck"', false);
        $this->get(route('app.reports.show', ['report' => 'daily-banking', 'sort' => 'ZreadCheck'] + $this->gridScope()))
            ->assertOk()->assertSee('1 008.00')->assertDontSee('Z-read differs')
            ->assertViewHas('params', fn ($params) => $params['SortColumn'] === null);
    }

    public function test_allowed_operator_sees_the_existing_alert_and_queue(): void
    {
        $person = $this->operator();
        $this->grant($person, 'cash.discrepancy.view');
        $this->actingAs($person);
        $this->get(route('app.cash.banking', $this->bankingScope()))->assertOk()
            ->assertSee('Z-read disagrees')->assertSee('Z-read differs')
            ->assertSee('The Z-read allocated to this till and shift says')
            ->assertViewHas('summary', fn ($summary) => $summary['disagree'] === 1);
        $this->get(route('app.cash.cashups.row', $this->bankingScope()), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee('Z-read differs')->assertSee('The Z-read allocated to this till and shift says');
        $this->get(route('app.cash.banking.phone', $this->bankingScope()))->assertOk()->assertSee('Z-read: z-read differs');
        $this->get(route('app.cash.cashups', ['show' => 'disagree'] + $this->gridScope()))->assertOk()->assertSee('Z-read differs');
        $this->get(route('app.cash.cashups', ['f' => ['ZreadCheck' => ['in' => ['Agrees']]]] + $this->gridScope()))
            ->assertOk()->assertViewHas('grid', fn ($grid) => $grid->total === 0 && count($grid->gridQuery->filters) === 1);
        $this->get(route('app.cash.cashups', ['f' => ['ZreadCheck' => ['in' => ['Z-read differs']]]] + $this->gridScope()))
            ->assertOk()->assertViewHas('grid', fn ($grid) => $grid->total === 1 && count($grid->gridQuery->filters) === 1);
        $this->get(route('app.reports.show', ['report' => 'daily-banking', 'sort' => 'ZreadCheck'] + $this->gridScope()))
            ->assertOk()->assertSee('Z-read differs')
            ->assertViewHas('params', fn ($params) => $params['SortColumn'] === 'ZreadCheck');
    }

    public function test_denied_operator_cannot_restore_the_alert_via_grid_state_or_extracts(): void
    {
        $person = $this->operator();
        $this->actingAs($person);
        $this->db()->table('agora.UserGridColumn')->insert([
            'BranchId' => $person->BranchId, 'UserId' => $person->Id, 'GridKey' => 'app.cash.cashups',
            'ColumnsJson' => json_encode(['column_order' => ['ZreadCheck', 'ZRead'], 'hidden' => [], 'sort' => 'ZreadCheck']),
        ]);
        $forged = ['sort' => 'ZreadCheck', 'f' => ['ZreadCheck' => ['in' => ['Agrees']]]] + $this->gridScope();
        $this->get(route('app.cash.cashups', $forged))->assertOk()->assertSee('1 008.00')
            ->assertDontSee('Z-read differs')->assertViewHas('grid', function ($grid) {
                $this->assertNotSame('ZreadCheck', $grid->gridQuery->sort);
                $this->assertSame([], $grid->gridQuery->filters);
                $this->assertArrayNotHasKey('ZreadCheck', $grid->definition->catalogue());

                return true;
            });
        $extract = ['grid' => 'app.cash.cashups', 'columns' => 'ZRead,ZreadCheck,ActualTotal'] + $forged;
        $csv = $this->get(route('app.grids.extract', $extract))->assertOk()->streamedContent();
        $this->assertStringContainsString('"Z read","Actual total"', $csv);
        $this->assertStringContainsString('1000,1008', $csv);
        $this->assertStringNotContainsString('Z-read differs', $csv);
        $this->assertStringNotContainsString('Z-read', $csv);
        $xlsx = $this->get(route('app.grids.extract', ['format' => 'xlsx'] + $extract))->assertOk();
        $response = $xlsx->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $file = $response->getFile()->getPathname();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($file));
        try {
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            $this->assertIsString($sheet);
            $this->assertStringContainsString('Actual total', $sheet);
            $this->assertStringNotContainsString('Z-read', $sheet);
            $this->assertStringNotContainsString('Z-read differs', $sheet);
        } finally {
            $zip->close();
            unlink($file);
        }
        $this->get(route('app.cash.cashups', ['show' => 'disagree'] + $this->gridScope()))->assertForbidden();
        $this->get(route('app.grids.extract', ['show' => 'disagree'] + $extract))->assertForbidden();
    }

    public function test_allowed_operator_can_export_the_alert_column(): void
    {
        $person = $this->operator();
        $this->grant($person, 'cash.discrepancy.view');
        $csv = $this->actingAs($person)->get(route('app.grids.extract', [
            'grid' => 'app.cash.cashups', 'show' => 'disagree', 'columns' => 'ZRead,ZreadCheck,ActualTotal',
        ] + $this->gridScope()))->assertOk()->streamedContent();
        $this->assertStringContainsString('Z-read', $csv);
        $this->assertStringContainsString('Z-read differs', $csv);
        $this->assertStringContainsString('1000,"Z-read differs",1008', $csv);
    }

    public function test_alert_permission_does_not_relax_confirm_close_or_save_rules(): void
    {
        $this->actingAs($this->operator());
        $scope = $this->bankingScope();
        $header = (array) $this->header(41, 1);
        $this->get(route('app.cash.banking', $scope))->assertOk()->assertSee('Why — required for this one');
        $this->get(route('app.cash.banking.phone', $scope))->assertOk()->assertSee('Why — required');
        $this->post(route('app.cash.banking.confirm'), $scope)->assertRedirect()->assertSessionHas('cashupCode', 'CASH_CASHUP_REASON');
        $this->post(route('app.cash.banking.close'), $scope)->assertRedirect()->assertSessionHas('cashupCode', 'CASH_CASHUP_NOT_CONFIRMED');
        config(['cash.cashup.write_mode' => 'off']);
        $this->post(route('app.cash.banking.cards'), $scope)->assertForbidden();
        $this->assertSame($header, (array) $this->header(41, 1), 'None of these requests changed the source cashup.');
        $this->post(route('app.cash.banking.confirm'), $scope + ['note' => 'TEST-reviewed source difference'])
            ->assertRedirect()->assertSessionHas('cashupCode', 'CASH_CASHUP_CONFIRMED');
        $this->post(route('app.cash.banking.close'), $scope)->assertRedirect()->assertSessionHas('cashupCode', 'CASH_CASHUP_CLOSED_OFF');
    }

    public function test_registration_copies_no_grants_and_managed_roles_can_add_and_remove_access(): void
    {
        $person = $this->operator();
        $users = UserPermission::query()->acrossBranches()->count();
        $roles = RolePermission::query()->acrossBranches()->count();
        app(CashupDiscrepancyPermissionSeeder::class)->run();
        app(CashupDiscrepancyPermissionSeeder::class)->run();
        $this->assertSame($users, UserPermission::query()->acrossBranches()->count());
        $this->assertSame($roles, RolePermission::query()->acrossBranches()->count());
        $this->assertSame(1, Permission::query()->acrossBranches()->where('Code', 'cash.discrepancy.view')->count());
        $this->assertFalse($person->can('cash.discrepancy.view'));
        $access = app(RoleAccessService::class);
        $data = ['Code' => 'TEST-cash-discrepancy-'.uniqid(), 'Name' => 'TEST-cash discrepancy',
            'permissions' => [Permission::query()->acrossBranches()->where('Code', 'cash.discrepancy.view')->value('Id')]];
        $role = $access->save(null, $data);
        try {
            $access->assign($person, [(int) $role->Id]);
            $this->assertTrue($person->can('cash.discrepancy.view'));
            $access->save((int) $role->Id, array_replace($data, ['permissions' => []]));
            $this->assertFalse($person->can('cash.discrepancy.view'), 'Removing the role grant clears cached permission access.');
            $this->assertTrue($person->can('cash.cashup.view'));
            $this->assertTrue($this->person('admin')->can('cash.discrepancy.view'));
        } finally {
            $access->assign($person, []);
            $access->delete((int) $role->Id);
        }
    }
}
