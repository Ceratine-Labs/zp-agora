<?php

namespace Modules\StockRecon\Services;

use App\Exceptions\AgoraProcException;
use App\Grid\GridQuery;
use App\Support\BranchContext;
use App\Support\BusinessDate;
use App\Support\ProcedureService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Source transport only. Classification and action rules live in procedures. */
class WeeklyStockService
{
    public function __construct(private ProcedureService $procedures) {}

    /** @return array<string, scalar|null> */
    public static function access(string $action = 'view'): array
    {
        $context = app(BranchContext::class);

        return ['UserId' => auth()->id(), 'Authorized' => (int) (auth()->user()?->can('stockrecon.weekly.'.$action) ?? false),
            'Workspace' => $context->workspace(), 'BranchPin' => $context->id()];
    }

    public function create(int $branch, Carbon $week, ?Carbon $periodFrom = null, ?Carbon $periodTo = null, ?Carbon $asOf = null): object
    {
        $access = ['BranchId' => $branch] + self::access('create');
        $this->procedures->call('agora.usp_StockRecon_AuthorizeWeekly', $access);
        $periodFrom ??= $week->copy();
        $periodTo ??= $week->copy()->addDays(6);
        $asOf ??= BusinessDate::today();
        $readAt = now('UTC')->format('Y-m-d H:i:s');
        $parameters = ['BranchId' => $branch, 'FromDate' => $periodFrom->toDateString(), 'ToDate' => $periodTo->toDateString()];
        $recon = $this->procedures->call('agora.usp_StockRecon_WeeklyRecon', $parameters);
        $countSets = $this->procedures->callSets('agora.usp_StockRecon_WeeklyCounts', $parameters);
        $coverage = ($countSets[1] ?? collect())->all();
        $evidence = collect();
        foreach (['WeeklyReceipts', 'WeeklyRecipeEvidence'] as $reader) {
            try {
                $rows = $this->procedures->call('agora.usp_StockRecon_'.$reader, [
                    'BranchId' => $branch, 'FromDate' => $reader === 'WeeklyReceipts' ? $periodFrom->toDateString() : $periodFrom->copy()->subDays(7)->toDateString(),
                    'ToDate' => $periodTo->toDateString(),
                ]);
                $evidence = $evidence->concat($rows);
                $coverage[] = (object) ['SourceSystem' => 'Evidence', 'SourceName' => $reader, 'State' => 'Available',
                    'Note' => $rows->count().' matching rows. Availability does not prove the source extract is complete.'];
            } catch (AgoraProcException $e) {
                if ($e->code() !== 'WEEKLY_SOURCE_UNAVAILABLE') {
                    throw $e;
                }
                $coverage[] = (object) ['SourceSystem' => 'Evidence', 'SourceName' => $reader, 'State' => 'Unavailable', 'Note' => $e->getMessage()];
            }
        }
        $costs = collect();
        try {
            $costs = $this->procedures->call('agora.usp_StockRecon_WeeklyCosts', $parameters);
            $coverage[] = (object) ['SourceSystem' => 'Cost', 'SourceName' => 'DET sales cost fallback', 'State' => 'Available',
                'Note' => $costs->count().' product cost records; conflicting categories remain unavailable.'];
        } catch (AgoraProcException $e) {
            if ($e->code() !== 'WEEKLY_SOURCE_UNAVAILABLE') {
                throw $e;
            }
            $coverage[] = (object) ['SourceSystem' => 'Cost', 'SourceName' => 'DET sales cost fallback', 'State' => 'Unavailable', 'Note' => $e->getMessage()];
        }
        $source = ['costs' => $costs, 'recon' => $recon, 'counts' => $countSets[0] ?? collect(), 'lastCounts' => $countSets[2] ?? collect(), 'evidence' => $evidence];

        return $this->procedures->write('agora.usp_StockRecon_CreateWeeklySnapshot', [
            'WeekStart' => $week->toDateString(), 'PeriodFrom' => $periodFrom->toDateString(), 'PeriodTo' => $periodTo->toDateString(), 'ReportingAsOf' => $asOf->toDateString(), 'SnapshotRef' => (string) Str::uuid(),
            'SourceJson' => json_encode($source, JSON_THROW_ON_ERROR), 'CoverageJson' => json_encode($coverage, JSON_THROW_ON_ERROR), 'SourceReadAt' => $readAt,
        ] + $access);
    }

    /** @return array{case: object, shifts: Collection<int, object>, counts: Collection<int, object>, evidence: Collection<int, object>, history: Collection<int, object>, owners: Collection<int, object>} */
    public function get(int $branch, int $case): array
    {
        $sets = $this->procedures->callSets('agora.usp_StockRecon_GetWeeklyCase', ['BranchId' => $branch, 'CaseId' => $case] + self::access());
        $row = ($sets[0] ?? collect())->first();
        abort_if($row === null, 404);

        return ['case' => $row, 'shifts' => $sets[1] ?? collect(), 'counts' => $sets[2] ?? collect(),
            'evidence' => $sets[3] ?? collect(), 'history' => $sets[4] ?? collect(), 'owners' => $sets[5] ?? collect()];
    }

    /** @return array{sites: Collection<int, object>, products: Collection<int, object>, registers: Collection<int, object>, patterns: Collection<int, object>} */
    public function overview(GridQuery $query, ?string $snapshot): array
    {
        $sets = $this->procedures->callSets('agora.usp_StockRecon_GetWeeklyOverview', [
            'BranchIds' => implode(',', $query->branchIds) ?: null, 'DateFrom' => $query->from,
            'DateTo' => $query->to, 'SnapshotRef' => $snapshot,
        ] + self::access());

        return ['sites' => $sets[0] ?? collect(), 'products' => $sets[1] ?? collect(), 'registers' => $sets[2] ?? collect(), 'patterns' => $sets[3] ?? collect()];
    }

    /** @param array<string, mixed> $input */
    public function save(int $branch, int $case, array $input, string $operation = 'save'): object
    {
        return $this->procedures->write('agora.usp_StockRecon_SaveWeeklyCase', [
            'BranchId' => $branch, 'CaseId' => $case, 'ExpectedVersion' => $input['ExpectedVersion'],
            'Operation' => $operation, 'Status' => $operation === 'approve' ? 'Closed' : ($operation === 'reopen' ? 'Investigating' : $input['Status']), 'OwnerId' => $input['OwnerId'] ?? null, 'DueDate' => $input['DueDate'] ?? null,
            'Finding' => $input['Finding'] ?? null, 'EvidenceReference' => $input['EvidenceReference'] ?? null, 'Reason' => $input['Reason'],
            'ClosureOutcome' => $input['ClosureOutcome'] ?? null, 'RecoveryAmount' => $input['RecoveryAmount'] ?? null,
            'RecoveryReference' => $input['RecoveryReference'] ?? null, 'CorrectionStatus' => $input['CorrectionStatus'] ?? null,
            'CorrectionReference' => $input['CorrectionReference'] ?? null, 'ActionTaken' => $input['ActionTaken'] ?? null,
            'ActionDate' => $input['ActionDate'] ?? null, 'SignoffNote' => $input['SignoffNote'] ?? null,
        ] + self::access($operation));
    }
}
