<?php

/**
 * Local-only, opt-in fixture for the employee edit browser flow (T023).
 *
 * The edit is a named write to PumpIT (CLAUDE.md rule 1), so this refuses to
 * run unless EVERY connection — the PumpIT one included — points at the local
 * container: what the browser writes then lands in the local PumpIT stub and
 * nowhere else. Start the server with the estate connections pinned local:
 *
 *   PUMPIT_DB_HOST=127.0.0.1 PUMPIT_DB_USERNAME=sa PUMPIT_DB_PASSWORD=… (and MIST_, ALTERYX_)
 *
 *   php tests/e2e/support/people-editor-fixture.php prepare|snapshot|cleanup <12-hex token>
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\PermissionService;

if (PHP_SAPI !== 'cli' || getenv('AGORA_PEOPLE_EDITOR_E2E') !== '1') {
    throw new RuntimeException('People editor fixtures require an explicit local CLI opt-in.');
}

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): never {
    $detail = $error::class === RuntimeException::class ? $error->getMessage() : $error::class;
    fwrite(STDERR, 'People editor fixture refused: '.$detail."\n");
    exit(1);
});

if (app()->isProduction() || app()->configurationIsCached()) {
    throw new RuntimeException('People editor fixtures refuse production or cached configuration.');
}
foreach (['app', 'erp', 'pos_landing', 'reporting'] as $name) {
    $connection = config('agora.connections.'.$name);
    if (! is_string($connection) || ! in_array(config('database.connections.'.$connection.'.host'), ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new RuntimeException('Every fixture connection must point to loopback.');
    }
}

final class PeopleEditorFixture
{
    private const BRANCH = 969;

    private Connection $db;

    private string $code;

    private string $email;

    public function __construct(string $token)
    {
        if (! preg_match('/^[a-f0-9]{12}$/', $token)) {
            throw new RuntimeException('Expected a twelve-character fixture token.');
        }
        $this->db = DB::connection(config('agora.connections.app'));
        $this->code = 'TPB'.strtoupper(substr($token, 0, 6));
        $this->email = 'TEST-people-editor-'.$token.'@agora.invalid';
    }

    /** @return array<string, mixed> */
    public function prepare(): array
    {
        $this->assertAuditorReadOnly();
        if ($this->db->table('agora.Branch')->where('BranchId', self::BRANCH)->exists()
            || $this->db->table('PumpIT.dbo.BRN_Employee')->where('SSBranchId', self::BRANCH)->exists()) {
            throw new RuntimeException('Branch 969 is already in use; run cleanup first.');
        }
        $password = bin2hex(random_bytes(24));

        $this->db->transaction(function () use ($password): void {
            $this->db->table('agora.Branch')->insert(['BranchId' => self::BRANCH, 'Name' => 'TEST-People editor site', 'IsTrading' => 1,
                'IsActive' => 1, 'SortOrder' => self::BRANCH, 'Status' => 'Trading', 'POSType' => 'BRANCH', 'IsAdminEntity' => 0, 'CreatedAt' => now()]);
            $this->db->table('PumpIT.dbo.BRN_EmployeePost')->insert([
                ['SSBranchId' => self::BRANCH, 'PostNo' => 18, 'PostDescription' => 'TEST-Fuel Cashier', 'StockControl' => 1],
                ['SSBranchId' => self::BRANCH, 'PostNo' => 46, 'PostDescription' => 'TEST-Site Manager', 'StockControl' => 1],
            ]);
            $this->db->table('PumpIT.dbo.BRN_Employee')->insert(['SSBranchId' => self::BRANCH, 'EmployeeCode' => $this->code,
                'FirstName' => 'TEST-Browser', 'Surname' => 'TEST-Editor', 'NickName' => null, 'IsActive' => 1, 'IsPumpAttendant' => 0, 'PostNo' => 18]);
            $this->db->table('PumpIT.dbo.STK_Area')->insert(['SSBranchId' => self::BRANCH, 'AreaNo' => 7, 'AreaDescription' => 'TEST-Fuel bay',
                'AreaGroup' => 'TEST', 'DayShift' => 1, 'AfternoonShift' => 0, 'NightShift' => 1, 'ShowReport' => 1, 'IsCaptureWaste' => 0]);

            $permissions = $this->db->table('agora.Permission')
                ->whereIn('Code', ['people.employees.view', 'people.employees.edit', 'people.areas.edit'])->pluck('Id');
            if ($permissions->count() !== 3) {
                throw new RuntimeException('The people permissions must already be seeded.');
            }
            $user = User::query()->acrossBranches()->create([
                'BranchId' => (int) config('agora.group_branch_id'), 'EmailAddress' => $this->email, 'UserName' => 'TEST-People editor',
                'PasswordHash' => $password, 'IsActive' => true, 'IsLocked' => false, 'MustChangePassword' => false,
                'Workspace' => 'ho', 'LandingRoute' => 'app.setup.employees.index',
            ]);
            foreach ($permissions as $permission) {
                $this->db->table('agora.UserPermission')->insert(['BranchId' => $user->BranchId, 'UserId' => $user->Id,
                    'PermissionId' => $permission, 'CreatedAt' => now()]);
            }
            $this->db->table('agora.UserBranch')->insert(['BranchId' => self::BRANCH, 'UserId' => $user->Id]);
        });

        return [
            'email' => $this->email, 'password' => $password, 'code' => $this->code, 'name' => 'TEST-Editor TEST-Browser',
            'show' => '/app/setup/employees/'.self::BRANCH.'/'.$this->code,
            'edit' => '/app/setup/employees/'.self::BRANCH.'/'.$this->code.'/edit',
            'sql_host' => $this->db->getConfig('host'),
        ];
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'employee' => $this->db->table('PumpIT.dbo.BRN_Employee')->where('SSBranchId', self::BRANCH)->where('EmployeeCode', $this->code)
                ->first(['IsActive', 'PostNo', 'NickName']),
            'areas' => $this->db->table('PumpIT.dbo.BRN_EmployeeArea')->where('BranchId', self::BRANCH)->where('EmployeeCode', $this->code)
                ->orderBy('AreaNo')->pluck('AreaNo')->all(),
            'ledger' => $this->db->table('agora.PeopleWrite')->where('BranchId', self::BRANCH)->orderBy('Id')->get(['Verb', 'Field', 'PriorValue', 'NewValue', 'State']),
        ];
    }

    /** @return array<string, int> */
    public function cleanup(): array
    {
        $this->db->transaction(function (): void {
            $this->db->table('agora.PeopleWrite')->where('BranchId', self::BRANCH)->delete();
            foreach (['BRN_EmployeeArea' => 'BranchId', 'STK_Area' => 'SSBranchId', 'BRN_Employee' => 'SSBranchId', 'BRN_EmployeePost' => 'SSBranchId'] as $table => $column) {
                $this->db->table('PumpIT.dbo.'.$table)->where($column, self::BRANCH)->delete();
            }
            $user = User::query()->acrossBranches()->where('EmailAddress', $this->email)->first();
            if ($user) {
                foreach (['UserPermission', 'UserBranch', 'UserGridColumn', 'UserMenuItem'] as $table) {
                    $this->db->table('agora.'.$table)->where('UserId', $user->Id)->delete();
                }
                $user->forceDelete();
            }
            $this->db->table('agora.Branch')->where('BranchId', self::BRANCH)->delete();
        });

        return ['users' => $this->db->table('agora.User')->where('EmailAddress', $this->email)->count(),
            'employees' => $this->db->table('PumpIT.dbo.BRN_Employee')->where('SSBranchId', self::BRANCH)->count()];
    }

    private function assertAuditorReadOnly(): void
    {
        $user = User::query()->acrossBranches()->where('EmailAddress', config('agora.e2e.email'))->firstOrFail();
        $permissions = app(PermissionService::class);
        if ($permissions->userHas($user, 'people.employees.edit') || $permissions->userHas($user, 'people.areas.edit')) {
            throw new RuntimeException('The shared auditor must remain view-only.');
        }
    }
}

$fixture = new PeopleEditorFixture($argv[2] ?? '');
$result = match ($argv[1] ?? '') {
    'prepare' => $fixture->prepare(),
    'snapshot' => $fixture->snapshot(),
    'cleanup' => $fixture->cleanup(),
    default => throw new RuntimeException('Use prepare, snapshot or cleanup.'),
};
echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
