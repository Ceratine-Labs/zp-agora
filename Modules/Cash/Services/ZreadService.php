<?php

namespace Modules\Cash\Services;

use App\Support\ProcedureService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Cash\Support\ZreadKey;
use Modules\Cash\Support\ZreadScope;

/**
 * Z-read allocation (T036): the seam between the screen and the procedures.
 *
 * Every rule is in T-SQL. The proposer — which prior wins, what counts as
 * certain, what the evidence says — is agora.usp_Cash_ProposeZreadAllocation
 * and nothing here re-derives a confidence. What this class adds is only what
 * a procedure cannot know: where the priors are configured, who the person is,
 * and that the phone's Confirm is two writes that must land together.
 *
 * WHAT REACHES THE CUSTOMER'S DATABASE, and only through a procedure:
 * usp_Cash_CommitZreadAllocation and usp_Cash_ReverseZreadCommit write
 * TillNo / ShiftNo / EmployeeCode on [PumpIT].dbo.DBF_P3TRANS_ZREAD, and the
 * commit does so only when stampMode() is 'live'. Everything else writes
 * agora.ZreadAllocation or nothing.
 */
class ZreadService
{
    public function __construct(protected ProcedureService $procedures) {}

    /**
     * The four numbers the proposer runs on, as the procedures name them.
     *
     * config('cash.zread') today, agora.Setting once T026 exists — this is
     * the one method that changes when it does.
     *
     * @return array{CertainShare: float, LikelyShare: float, ShiftShare: float, HistoryDays: int, DayShiftNo: int, NightShiftNo: int, SequenceExcluded: string}
     */
    public function priors(): array
    {
        return [
            'CertainShare' => (float) config('cash.zread.certain_share', 0.75),
            'LikelyShare' => (float) config('cash.zread.likely_share', 0.60),
            'ShiftShare' => (float) config('cash.zread.shift_share', 0.60),
            'HistoryDays' => (int) config('cash.zread.history_days', 90),
            'DayShiftNo' => (int) config('cash.zread.day_shift_no', 1),
            'NightShiftNo' => (int) config('cash.zread.night_shift_no', 3),
            'SequenceExcluded' => implode(',', (array) config('cash.zread.sequence_excluded_locations', ['ARCH'])),
        ];
    }

    /**
     * 'live' when Update ZREAD also writes TillNo / ShiftNo / EmployeeCode
     * onto the PumpIT row; 'journal' when it saves in Agora only.
     *
     * Read from the LITERAL config('cash.zread.stamp_mode') and nothing else —
     * never an environment variable — and anything that is not exactly 'live'
     * is 'journal', so a typo fails safe.
     */
    public function stampMode(): string
    {
        return config('cash.zread.stamp_mode') === 'live' ? 'live' : 'journal';
    }

    /** certain | likely | review — what Auto-allocate stages without a person. */
    public function threshold(): string
    {
        $threshold = (string) config('cash.zread.auto_accept', 'certain');

        return in_array($threshold, ['certain', 'likely', 'review'], true) ? $threshold : 'certain';
    }

    /**
     * One row per Z-read in scope, each with its proposal.
     *
     * @return Collection<int, object>
     */
    public function propose(ZreadScope $scope, bool $replay = false): Collection
    {
        return $this->procedures->call('usp_Cash_ProposeZreadAllocation', [
            'BranchId' => $scope->branchId,
            'DateFrom' => $scope->from,
            'DateTo' => $scope->to,
            'Location' => $scope->location,
            'Replay' => $replay ? 1 : 0,
            ...$this->priors(),
        ]);
    }

    /** One Z-read and its proposal, or null when it is not in the site's readings. */
    public function find(ZreadKey $key): ?object
    {
        return $this->procedures->call('usp_Cash_ProposeZreadAllocation', [
            'BranchId' => $key->branchId,
            'DateFrom' => $key->date,
            'DateTo' => $key->date,
            'Location' => $key->location !== '' ? $key->location : null,
            'Replay' => 0,
            ...$this->priors(),
        ])->first(fn (object $row) => $key->matches($row));
    }

    /**
     * The figures above the grid, counted from the proposer's own rows so the
     * strip and the grid cannot disagree about what "waiting" means.
     *
     * @param  Collection<int, object>  $rows
     * @return array<string, int|float>
     */
    public function summary(Collection $rows): array
    {
        $waiting = $rows->where('State', '!=', 'allocated');
        $open = $rows->where('State', 'open');

        return [
            'total' => $rows->count(),
            'waiting' => $waiting->count(),
            'waitingSales' => (float) $waiting->sum(fn (object $r) => (float) $r->Sales),
            'staged' => $rows->where('State', 'staged')->count(),
            'certain' => $open->where('Confidence', 'certain')->count(),
            'likely' => $open->where('Confidence', 'likely')->count(),
            'review' => $open->where('Confidence', 'review')->count(),
            'manual' => $open->where('Confidence', 'manual')->count(),
            'people' => $rows->pluck('EmployeeCode')->filter()->unique()->count(),
        ];
    }

    /** @return Collection<int, object> who normally works the Z-read's terminal */
    public function terminalHistory(ZreadKey $key): Collection
    {
        return $this->procedures->call('usp_Cash_ZreadTerminalHistory', [
            'BranchId' => $key->branchId,
            'Location' => $key->location,
            'TerminalNo' => $key->terminal,
            'AsOf' => $key->date,
            'HistoryDays' => $this->priors()['HistoryDays'],
        ]);
    }

    /** Stage every open Z-read at or above the threshold. */
    public function autoAllocate(ZreadScope $scope, int $userId): object
    {
        return $this->procedures->write('usp_Cash_AutoAllocate', [
            'BranchId' => $scope->branchId,
            'DateFrom' => $scope->from,
            'DateTo' => $scope->to,
            'Location' => $scope->location,
            'Threshold' => $this->threshold(),
            ...$this->priors(),
            'UserId' => $userId,
        ]);
    }

    /** Stage one Z-read exactly as the system proposes it. */
    public function accept(ZreadKey $key, int $userId): object
    {
        return $this->procedures->write('usp_Cash_SetZreadAllocation', [
            ...$key->procedureParameters(),
            'ZReadDate' => $key->date,
            'Source' => 'accepted',
            ...$this->priors(),
            'UserId' => $userId,
        ]);
    }

    /** Stage one Z-read as a person set it. */
    public function set(ZreadKey $key, int $tillNo, int $shiftNo, string $employeeCode, int $userId): object
    {
        return $this->procedures->write('usp_Cash_SetZreadAllocation', [
            ...$key->procedureParameters(),
            'ZReadDate' => $key->date,
            'Source' => 'manual',
            'TillNo' => $tillNo,
            'ShiftNo' => $shiftNo,
            'EmployeeCode' => $employeeCode,
            ...$this->priors(),
            'UserId' => $userId,
        ]);
    }

    /** Throw staging away — the scope, or one Z-read. Marked, never deleted. */
    public function clear(ZreadScope $scope, int $userId, ?ZreadKey $only = null): object
    {
        return $this->procedures->write('usp_Cash_ClearZreadProposals', $this->scoped($scope, $only) + ['UserId' => $userId]);
    }

    /**
     * Update ZREAD: every staged Z-read in scope (or one) becomes its
     * allocation — and, in 'live' stamp mode, is written onto the PumpIT row.
     */
    public function commit(ZreadScope $scope, int $userId, ?ZreadKey $only = null): object
    {
        return $this->procedures->write('usp_Cash_CommitZreadAllocation', $this->scoped($scope, $only) + [
            'StampMode' => $this->stampMode(),
            'UserId' => $userId,
        ]);
    }

    /**
     * Undo one Update ZREAD press: its allocations are marked reversed, what
     * they replaced is back in force, and any PumpIT row it stamped gets its
     * prior three columns back. All or nothing — see the procedure.
     */
    public function reverse(int $branchId, string $commitRef, string $reason, int $userId): object
    {
        return $this->procedures->write('usp_Cash_ReverseZreadCommit', [
            'BranchId' => $branchId,
            'CommitRef' => $commitRef,
            'Reason' => $reason,
            'UserId' => $userId,
        ]);
    }

    /**
     * The presses that saved a Z-read in the scope's period, newest first.
     *
     * @return Collection<int, object>
     */
    public function commits(ZreadScope $scope): Collection
    {
        return $this->procedures->call('usp_Cash_ZreadCommits', [
            'BranchId' => $scope->branchId,
            'DateFrom' => $scope->from,
            'DateTo' => $scope->to,
        ]);
    }

    /**
     * The phone's Confirm: accept the proposal and save it, as ONE act.
     *
     * Two procedure calls in one transaction — ProcedureService::write() joins
     * a transaction already open, so a refusal from either leaves nothing
     * staged behind. A Z-read already staged (by Auto-allocate, or at a desk)
     * is saved as it was staged rather than re-accepted over the top.
     */
    public function confirm(ZreadKey $key, ZreadScope $scope, int $userId, bool $alreadyStaged): object
    {
        return DB::connection(config('agora.connections.app'))->transaction(function () use ($key, $scope, $userId, $alreadyStaged) {
            if (! $alreadyStaged) {
                $this->accept($key, $userId);
            }

            return $this->commit($scope->forDay($key->date, $key->location), $userId, $key);
        });
    }

    /**
     * The site's tills for the Set form — the Z-read's own POS location first,
     * because a WINBRANCH reading on an ARCH till is almost always a mis-key.
     *
     * A plain SELECT on the view rather than a procedure: this is a choice
     * list for a form control, the lookup feature-rules §2 says need not be
     * wrapped.
     *
     * @return Collection<int, object>
     */
    public function tills(int $branchId, string $location): Collection
    {
        return collect(DB::connection(config('agora.connections.app'))->select(
            'SELECT TillNo, TillDescription, PosLocation FROM ['.config('agora.schema').'].[vw_Till]
             WHERE BranchId = ?
             ORDER BY CASE WHEN PosLocation = ? THEN 0 ELSE 1 END, TillNo',
            [$branchId, $location],
        ));
    }

    /** @return Collection<int, object> */
    public function shifts(int $branchId): Collection
    {
        return collect(DB::connection(config('agora.connections.app'))->select(
            'SELECT ShiftNo, ShiftDescription FROM ['.config('agora.schema').'].[vw_Shift]
             WHERE BranchId = ? ORDER BY ShiftNo',
            [$branchId],
        ));
    }

    /**
     * The site's staff, active first. Inactive people are offered too — a
     * Z-read from last month can belong to somebody who has since left.
     *
     * @return Collection<int, object>
     */
    public function employees(int $branchId): Collection
    {
        return collect(DB::connection(config('agora.connections.app'))->select(
            'SELECT EmployeeCode,
                    LTRIM(RTRIM(ISNULL(FirstName, \'\') + \' \' + ISNULL(Surname, \'\'))) AS EmployeeName,
                    IsActive
             FROM ['.config('agora.schema').'].[vw_Employee]
             WHERE BranchId = ?
             ORDER BY IsActive DESC, FirstName, Surname',
            [$branchId],
        ));
    }

    /**
     * @return array<string, string|int|null>
     */
    private function scoped(ZreadScope $scope, ?ZreadKey $only): array
    {
        $parameters = [
            'BranchId' => $scope->branchId,
            'DateFrom' => $only !== null ? $only->date : $scope->from,
            'DateTo' => $only !== null ? $only->date : $scope->to,
            'Location' => $only !== null ? $only->location : $scope->location,
        ];

        if ($only !== null) {
            $parameters += [
                'EodCounter' => $only->eod,
                'TerminalNo' => $only->terminal,
                'LogFile' => $only->logFile,
                'PosUserId' => $only->posUserId,
            ];
        }

        return $parameters;
    }
}
