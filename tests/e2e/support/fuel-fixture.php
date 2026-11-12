<?php

/** Opt-in local replay of recorded, read-only Fuel source evidence. */
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\PermissionService;

if (PHP_SAPI !== 'cli' || getenv('AGORA_FUEL_E2E') !== '1') {
    throw new RuntimeException('Fuel fixtures require the explicit local CLI opt-in.');
}
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, 'Fuel fixture refused: '.($error::class === RuntimeException::class ? $error->getMessage() : $error::class)."\n");
    exit(1);
});
if (app()->isProduction() || app()->configurationIsCached()) {
    throw new RuntimeException('Fuel fixtures refuse production or cached configuration.');
}
foreach (['app', 'erp', 'pos_landing', 'reporting'] as $role) {
    $connection = config('agora.connections.'.$role);
    if (! in_array(config('database.connections.'.$connection.'.host'), ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new RuntimeException('Every connection must point to loopback.');
    }
}
$token = $argv[2] ?? '';
if (! preg_match('/^[a-f0-9]{12}$/', $token)) {
    throw new RuntimeException('Expected a twelve-character fixture token.');
}
$branch = DB::table('agora.Branch')->where('BranchId', 999)->first();
if (! $branch || ! str_starts_with($branch->Name, 'TEST-') || $branch->IsTrading || $branch->IsActive) {
    throw new RuntimeException('Expected the inactive TEST branch 999.');
}
$evidence = [];
foreach (['POS', 'DOE'] as $source) {
    $path = getenv('AGORA_FUEL_'.$source.'_EVIDENCE');
    $capture = is_string($path) && is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : null;
    if (! is_array($capture) || ($capture['connection']['database'] ?? null) !== 'PumpIT'
        || ($capture['connection']['read_only_intent'] ?? null) !== true
        // Captured for T034 (price history) or T025 (the fuel matrix); either
        // way a read-only capture of the same two PumpIT tables.
        || ! in_array($capture['task_id'] ?? null, ['01a0bfed-7bc3-70d8-b52b-769f4bdbbf29', '01a06859-adda-72e3-9742-ddffb237bd4e'], true)) {
        throw new RuntimeException('A verified read-only Fuel capture is required.');
    }
    $evidence[$source] = $capture['rows'];
    foreach ($evidence[$source] as $row) {
        if ($row['SSBranchId'] !== 18 || ! in_array($row['FuelTypeNo'], [1, 2, 5], true)) {
            throw new RuntimeException('Expected the recorded branch 18 acceptance rows.');
        }
    }
}
$email = 'TEST-fuel-'.$token.'@agora.invalid';
$pos = fn () => DB::table('PumpIT.dbo.BRN_FuelPrice')->where('SSBranchId', 999)->whereIn('FuelTypeNo', [1, 2, 5]);
$doe = fn () => DB::table('PumpIT.dbo.BRN_MonthlyDOEFuelPricingMatrix')->where('SSBranchId', 999)->whereIn('FuelTypeNo', [1, 2, 5]);
$action = $argv[1] ?? '';
if ($action === 'prepare') {
    $password = bin2hex(random_bytes(24));
    DB::transaction(function () use ($pos, $doe, $email, $password, $evidence): void {
        if ($pos()->exists() || $doe()->exists() || DB::table('agora.User')->where('EmailAddress', $email)->exists()) {
            throw new RuntimeException('Fixture keys exist; refusing to overwrite.');
        }
        foreach ($evidence['POS'] as $row) {
            $row['SSBranchId'] = 999;
            $row['Date'] = $row['TradingDate'];
            $row['S_Date'] = $row['SourceDate'];
            unset($row['TradingDate'], $row['SourceDate']);
            DB::table('PumpIT.dbo.BRN_FuelPrice')->insert($row);
        }
        foreach ($evidence['DOE'] as $row) {
            $row['SSBranchId'] = 999;
            DB::table('PumpIT.dbo.BRN_MonthlyDOEFuelPricingMatrix')->insert($row);
        }
        $user = User::query()->acrossBranches()->create([
            'BranchId' => 2, 'EmailAddress' => $email, 'UserName' => 'TEST-Fuel source replay',
            'PasswordHash' => $password, 'IsActive' => true, 'IsLocked' => false, 'MustChangePassword' => false,
            'Workspace' => 'branch', 'HomeBranchId' => 999, 'LandingRoute' => 'app.fuel.matrix',
        ]);
        $permissions = DB::table('agora.Permission')->whereIn('Code', ['fuel.prices.view', 'reports.fuel-history.view'])->pluck('Id');
        if ($permissions->count() !== 2) {
            throw new RuntimeException('Fuel permissions must be seeded first.');
        }
        foreach ($permissions as $id) {
            DB::table('agora.UserPermission')->insert(['BranchId' => 2, 'UserId' => $user->Id, 'PermissionId' => $id]);
        }
        DB::table('agora.UserBranch')->insert(['BranchId' => 999, 'UserId' => $user->Id]);
    });
    echo json_encode(['email' => $email, 'password' => $password, 'source_branch' => 18, 'local_branch' => 999,
        'pos_rows' => $pos()->count(), 'doe_rows' => $doe()->count(), 'sql_host' => config('database.connections.agora.host')], JSON_THROW_ON_ERROR), "\n";
} elseif ($action === 'cleanup') {
    $fixtureUserId = DB::transaction(function () use ($email, $pos, $doe, $evidence): int {
        $user = User::query()->acrossBranches()->where('EmailAddress', $email)->firstOrFail();
        if ($pos()->count() !== count($evidence['POS']) || $doe()->count() !== count($evidence['DOE'])) {
            throw new RuntimeException('Fixture counts changed; refusing cleanup.');
        }
        $pos()->delete();
        $doe()->delete();
        foreach (['UserActivity', 'UserPreference', 'UserGridColumn', 'UserPermission', 'UserBranch'] as $table) {
            DB::table('agora.'.$table)->where('UserId', $user->Id)->delete();
        }
        app(PermissionService::class)->forget($user);
        $id = (int) $user->Id;
        $user->forceDelete();

        return $id;
    });
    echo json_encode(['pos_remaining' => $pos()->count(), 'doe_remaining' => $doe()->count(),
        'users_remaining' => DB::table('agora.User')->where('EmailAddress', $email)->count(),
        'activity_remaining' => DB::table('agora.UserActivity')->where('UserId', $fixtureUserId)->count(),
        'preferences_remaining' => DB::table('agora.UserPreference')->where('UserId', $fixtureUserId)->count(),
        'grid_state_remaining' => DB::table('agora.UserGridColumn')->where('UserId', $fixtureUserId)->count(),
        'grants_remaining' => DB::table('agora.UserPermission')->where('UserId', $fixtureUserId)->count(),
        'branches_remaining' => DB::table('agora.UserBranch')->where('UserId', $fixtureUserId)->count()], JSON_THROW_ON_ERROR), "\n";
} else {
    throw new RuntimeException('Use prepare or cleanup.');
}
