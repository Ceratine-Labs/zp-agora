<?php

/** Local-only, opt-in fixture for the real stock editor browser flow. */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\PermissionService;

if (PHP_SAPI !== 'cli' || getenv('AGORA_STOCK_EDITOR_E2E') !== '1') {
    throw new RuntimeException('Stock editor fixtures require an explicit local CLI opt-in.');
}

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): never {
    // Laravel's CLI renderer does not give this standalone script a failing
    // exit status. Keep failed fixture commands explicit and omit SQL values.
    $detail = $error::class === RuntimeException::class ? $error->getMessage() : $error::class;
    fwrite(STDERR, 'Stock editor fixture refused: '.$detail."\n");
    exit(1);
});

if (app()->isProduction() || app()->configurationIsCached()) {
    throw new RuntimeException('Stock editor fixtures refuse production or cached configuration.');
}
foreach (['app', 'erp', 'pos_landing', 'reporting'] as $name) {
    $connection = config('agora.connections.'.$name);
    if (! is_string($connection) || ! in_array(config('database.connections.'.$connection.'.host'), ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new RuntimeException('Every fixture connection must point to loopback.');
    }
}

final class StockEditorFixture
{
    private const BRANCH = 999;

    private Connection $db;

    private string $item;

    private string $other;

    private int $area;

    private string $email;

    private string $prefix;

    public function __construct(private string $token)
    {
        if (! preg_match('/^[a-f0-9]{12}$/', $token)) {
            throw new RuntimeException('Expected a twelve-character fixture token.');
        }
        $this->db = DB::connection(config('agora.connections.app'));
        $suffix = strtoupper(substr($token, 0, 4));
        $this->item = 'T'.$suffix;
        $this->other = 'U'.$suffix;
        $this->area = 800000 + hexdec(substr($token, 4, 4));
        $this->email = 'TEST-stock-editor-'.$token.'@agora.invalid';
        $this->prefix = 'TEST-'.$token;
    }

    /** @return array<string, mixed> */
    public function prepare(): array
    {
        $branch = $this->db->table('agora.Branch')->where('BranchId', self::BRANCH)->first();
        if (! $branch || ! str_starts_with($branch->Name, 'TEST-') || $branch->IsActive || $branch->IsTrading) {
            throw new RuntimeException('Expected the existing inactive TEST branch 999.');
        }
        $auditor = $this->auditor();
        $password = bin2hex(random_bytes(24));

        $this->db->transaction(function () use ($password): void {
            if ($this->source()->exists() || $this->overrides()->exists()
                || $this->db->table('PumpIT.dbo.STK_Area')->where('SSBranchId', self::BRANCH)->where('AreaNo', $this->area)->exists()
                || $this->db->table('agora.User')->where('EmailAddress', $this->email)->exists()) {
                throw new RuntimeException('Fixture keys already exist; refusing to reuse or overwrite them.');
            }
            $permissions = $this->db->table('agora.Permission')->whereIn('Code', ['master.stock.view', 'master.stock.edit'])->pluck('Id');
            if ($permissions->count() !== 2) {
                throw new RuntimeException('The two stock permissions must already be registered.');
            }
            $user = User::query()->acrossBranches()->create([
                'BranchId' => (int) config('agora.group_branch_id'),
                'EmailAddress' => $this->email, 'UserName' => $this->prefix.' stock editor',
                'PasswordHash' => $password, 'IsActive' => true, 'IsLocked' => false,
                'MustChangePassword' => false, 'Workspace' => 'ho', 'LandingRoute' => 'app.master.stock.index',
            ]);
            foreach ($permissions as $permission) {
                $this->db->table('agora.UserPermission')->insert([
                    'BranchId' => $user->BranchId, 'UserId' => $user->Id, 'PermissionId' => $permission, 'CreatedAt' => now(),
                ]);
            }
            $this->db->table('agora.UserBranch')->insert(['BranchId' => self::BRANCH, 'UserId' => $user->Id]);
            $this->db->table('PumpIT.dbo.STK_Area')->insert([
                'SSBranchId' => self::BRANCH, 'AreaNo' => $this->area, 'AreaDescription' => $this->prefix.' area',
                'AreaGroup' => 'TEST', 'DayShift' => 1, 'AfternoonShift' => 0, 'NightShift' => 0,
                'ShowReport' => 1, 'IsCaptureWaste' => 0,
            ]);
            foreach ([$this->item, $this->other] as $item) {
                $this->db->table('PumpIT.dbo.STK_StockMaster')->insert([
                    'SSBranchId' => self::BRANCH, 'StockItemNo' => $item,
                    'StockItemDescription' => $this->prefix.' '.$item, 'AreaNo' => $this->area,
                    'Location' => 'WINBRANCH', 'POSCode' => $this->prefix.'-'.$item,
                    'SellingPrice' => 29.99, 'PriceType' => 'Set Price', 'Factor' => 0,
                    'UOMCode' => 'Each', 'IssueMultiple' => 1, 'IssueMultiplePercentage' => 0,
                    'QtyVarAllowance' => 0, 'isMonitoredItem' => 0, 'IsDoCloseQtyCalc' => 1,
                    'IsAllowNegativeQtyIssued' => 0, 'IsAllowNegativeQtyClose' => 0,
                    'IsStockItemPreProduction' => 0, 'isPreProductionItem' => 0,
                    'PreProductionTypeNo' => 0, 'Ratio' => 0, 'ProduceLimitPercentage' => 0,
                ]);
            }
        });

        return [
            'email' => $this->email, 'password' => $password,
            'path' => '/app/master/stock/'.self::BRANCH.'/'.$this->item,
            'item' => $this->item, 'area' => $this->area,
            'description' => $this->prefix.' '.$this->item, 'savedDescription' => $this->prefix.' saved',
            'posCode' => $this->prefix.'-'.$this->item, 'collisionCode' => $this->prefix.'-'.$this->other,
            'reason' => $this->prefix.' browser override', 'auditor' => $auditor,
            'sql_host' => $this->db->getConfig('host'),
        ];
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'source' => $this->source()->where('StockItemNo', $this->item)
                ->first(['StockItemDescription', 'SellingPrice', 'POSCode']),
            'override' => $this->overrides()->where('StockItemNo', $this->item)
                ->first(['StockItemDescription', 'SellingPrice', 'POSCode', 'Reason', 'CreatedBy']),
            'override_count' => $this->overrides()->count(), 'auditor' => $this->auditor(),
        ];
    }

    /** @return array<string, mixed> */
    public function cleanup(): array
    {
        $removed = $this->db->transaction(function (): array {
            // An existing source row proves these keys belong to this run.
            if ($this->source()->count() !== 2 || $this->source()->where('StockItemDescription', 'not like', $this->prefix.'%')->exists()) {
                throw new RuntimeException('Fixture ownership changed; refusing cleanup.');
            }
            $removed = ['overrides' => $this->overrides()->delete(), 'source_rows' => $this->source()->delete()];
            $removed['areas'] = $this->db->table('PumpIT.dbo.STK_Area')->where('SSBranchId', self::BRANCH)
                ->where('AreaNo', $this->area)->where('AreaDescription', $this->prefix.' area')->delete();
            $user = User::query()->acrossBranches()->where('EmailAddress', $this->email)->firstOrFail();
            foreach (['UserActivity', 'UserPreference', 'UserPermission', 'UserBranch'] as $table) {
                $removed[$table] = $this->db->table('agora.'.$table)->where('UserId', $user->Id)->delete();
            }
            app(PermissionService::class)->forget($user);
            $user->forceDelete();
            $removed['users'] = 1;

            return $removed;
        });

        return ['removed' => $removed, 'remaining' => [
            'overrides' => $this->overrides()->count(), 'source_rows' => $this->source()->count(),
            'areas' => $this->db->table('PumpIT.dbo.STK_Area')->where('SSBranchId', self::BRANCH)->where('AreaNo', $this->area)->count(),
            'users' => $this->db->table('agora.User')->where('EmailAddress', $this->email)->count(),
        ], 'auditor' => $this->auditor()];
    }

    private function source(): Builder
    {
        return $this->db->table('PumpIT.dbo.STK_StockMaster')->where('SSBranchId', self::BRANCH)->whereIn('StockItemNo', [$this->item, $this->other]);
    }

    private function overrides(): Builder
    {
        return $this->db->table('agora.StockItem')->where('BranchId', self::BRANCH)->whereIn('StockItemNo', [$this->item, $this->other]);
    }

    /** @return array{can_edit: bool, grants_hash: string} */
    private function auditor(): array
    {
        $user = User::query()->acrossBranches()->where('EmailAddress', config('agora.e2e.email'))->firstOrFail();
        $permissions = app(PermissionService::class);
        if (! $permissions->userHas($user, 'master.stock.view') || $permissions->userHas($user, 'master.stock.edit')) {
            throw new RuntimeException('The shared auditor must remain view-only.');
        }
        $grants = $this->db->table('agora.UserPermission')->where('UserId', $user->Id)->orderBy('PermissionId')->pluck('PermissionId')->all();

        return ['can_edit' => false, 'grants_hash' => hash('sha256', json_encode($grants, JSON_THROW_ON_ERROR))];
    }
}

$fixture = new StockEditorFixture($argv[2] ?? '');
$result = match ($argv[1] ?? '') {
    'prepare' => $fixture->prepare(),
    'snapshot' => $fixture->snapshot(),
    'cleanup' => $fixture->cleanup(),
    default => throw new RuntimeException('Use prepare, snapshot or cleanup.'),
};
echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
