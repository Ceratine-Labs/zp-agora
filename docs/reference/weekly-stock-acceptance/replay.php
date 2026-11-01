<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
use App\Support\ProcedureService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\User;

$dir = rtrim($argv[2] ?? base_path('storage/app/weekly-workbook-evidence/2026-10-26'), '/').'/';
if (! in_array(config('database.connections.'.config('agora.connections.app').'.host'), ['127.0.0.1', 'localhost', '::1'])) {
    throw new RuntimeException('Local comparison only');
}
$branch = (int) ($argv[1] ?? 23);
if (! in_array($branch, [13, 23], true)) {
    throw new RuntimeException('Use one of the two captured comparison branches.');
}
$priorMeta = is_file($dir.'actual-snapshot-'.$branch.'.json') ? json_decode(file_get_contents($dir.'actual-snapshot-'.$branch.'.json')) : null;
$source = json_decode(file_get_contents($dir.'current-source-'.$branch.'.json'), true, 512, JSON_THROW_ON_ERROR);
DB::beginTransaction();
try {
    $u = User::query()->acrossBranches()->create(['BranchId' => 2, 'EmailAddress' => 'TEST-workbook-'.Str::uuid().'@agora.invalid', 'UserName' => 'TEST-workbook comparison', 'IsActive' => 1, 'IsLocked' => 0, 'Workspace' => 'ho', 'PasswordHash' => Str::random(30)]);
    $access = ['UserId' => $u->Id, 'Authorized' => 1, 'Workspace' => 'ho'];
    $snapshot = app(ProcedureService::class)->write('agora.usp_StockRecon_CreateWeeklySnapshot', ['BranchId' => $branch, 'WeekStart' => '2026-09-14', 'PeriodFrom' => '2026-09-01', 'PeriodTo' => '2026-09-21', 'ReportingAsOf' => '2026-09-23',
        'SnapshotRef' => (string) Str::uuid(), 'SourceReadAt' => $priorMeta?->SourceReadAt ?? gmdate('Y-m-d H:i:s', filemtime($dir.'current-source-'.$branch.'.json')), 'SourceJson' => json_encode($source, JSON_THROW_ON_ERROR),
        'CoverageJson' => '[{"SourceName":"Scoped current customer read","State":"Partial","Note":"Current extracts are not the immutable September exports. Branch 23 contains full WinBranch site; branch 13 contains three receipt-controlled products only."}]'] + $access);
    $overview = app(ProcedureService::class)->callSets('agora.usp_StockRecon_GetWeeklyOverview', ['SnapshotRef' => $snapshot->SnapshotRef] + $access);
    file_put_contents($dir.'actual-overview-'.$branch.'.json', json_encode($overview, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    $cases = DB::table('agora.StockWeeklyCase')->where('SnapshotRef', $snapshot->SnapshotRef)->get();
    foreach ($cases as $case) {
        unset($case->RowVer);
    }
    file_put_contents($dir.'actual-cases-'.$branch.'.json', json_encode($cases, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    $meta = DB::table('agora.StockWeeklySnapshot')->where('SnapshotRef', $snapshot->SnapshotRef)->first();
    unset($meta->SourceJson);
    file_put_contents($dir.'actual-snapshot-'.$branch.'.json', json_encode($meta, JSON_PRETTY_PRINT));
    echo json_encode(['cases' => $cases->count(), 'registers' => $cases->groupBy('RegisterName')->map->count(), 'weeklyThrough' => $meta->CompleteThrough, 'periodThrough' => $meta->PeriodThrough])."\n";
} finally {
    DB::rollBack();
}
