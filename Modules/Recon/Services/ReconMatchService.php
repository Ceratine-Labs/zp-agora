<?php

namespace Modules\Recon\Services;

use App\Support\ProcedureService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The seam between the manual-match screen and its two procedures.
 *
 * Like ReconService, this decides nothing. Both sides come out of
 * agora.usp_Recon_GetSides and the match itself out of
 * agora.usp_Recon_ManualMatch — which re-reads and re-checks every row it was
 * given, refuses a variance with no reason, and writes the same ledger an
 * automatic reconciliation writes. If a rule ever exists in both places the
 * procedure is right and this is the bug.
 */
class ReconMatchService
{
    public function __construct(protected ProcedureService $procedures) {}

    /**
     * Everything on both sides, with the colour that says which references
     * appear on both.
     *
     * @return array{bank: Collection<int, object>, mops: Collection<int, object>, summary: object|null}
     */
    public function sides(
        string $area,
        int $branchId,
        Carbon $from,
        Carbon $to,
        string $state = 'outstanding',
        int $maxRows = 500,
    ): array {
        $sets = $this->procedures->callSets('usp_Recon_GetSides', [
            'ReconArea' => $area,
            'BranchId' => $branchId,
            'FromDate' => $from->toDateString(),
            'ToDate' => $to->endOfDay()->toDateTimeString(),
            'State' => $state,
            'MaxRows' => $maxRows,
        ]);

        return [
            'bank' => $sets[0] ?? collect(),
            'mops' => $sets[1] ?? collect(),
            // Result set 3 is how the screen describes itself honestly: the
            // counts, the two totals, how many references are paired, and
            // whether this branch has any configuration at all.
            'summary' => ($sets[2] ?? collect())->first(),
        ];
    }

    /**
     * What the batch number could not pair, proposed by value.
     *
     * Everything is decided in agora.usp_Recon_SuggestMatches — the passes,
     * what counts as strong, what is left. This only hangs each suggestion's
     * members (result set 2) off the suggestion they belong to, so the screen
     * can list them and post them to the match unchanged.
     *
     * @return array{suggestions: Collection<int, object>, summary: object|null}
     */
    public function suggestions(string $area, int $branchId, Carbon $from, Carbon $to): array
    {
        $sets = $this->procedures->callSets('usp_Recon_SuggestMatches', [
            'BranchId' => $branchId,
            'ReconArea' => $area,
            'FromDate' => $from->toDateString(),
            'ToDate' => $to->endOfDay()->toDateTimeString(),
        ]);

        $members = ($sets[1] ?? collect())->groupBy('SuggestionNo');

        return [
            'suggestions' => ($sets[0] ?? collect())->map(function (object $suggestion) use ($members) {
                $rows = $members->get($suggestion->SuggestionNo, collect());
                $suggestion->bank = $rows->where('Side', 'bank')->values();
                $suggestion->mops = $rows->where('Side', 'mops')->values();

                return $suggestion;
            }),
            'summary' => ($sets[2] ?? collect())->first(),
        ];
    }

    /**
     * Make the match.
     *
     * The stamp mode is read from config and PASSED IN rather than looked up
     * by the procedure, so there is exactly one place in the codebase that
     * decides whether Agora writes to the customer's estate — the same rule
     * ReconService::commit() follows.
     *
     * `$basis` is set when the rows came from an accepted suggestion. It is
     * recorded on the run and changes nothing about what is checked.
     *
     * @param  array<int, int>  $bankLineIds
     * @param  array<int, array{id: int|null, key: string, dt: string, amt: float}>  $deposits
     */
    public function match(
        string $area,
        int $branchId,
        Carbon $from,
        Carbon $to,
        array $bankLineIds,
        array $deposits,
        ?string $reason = null,
        ?string $basis = null,
    ): object {
        return $this->procedures->write('usp_Recon_ManualMatch', [
            'BranchId' => $branchId,
            'ReconArea' => $area,
            'FromDate' => $from->toDateString(),
            'ToDate' => $to->endOfDay()->toDateTimeString(),
            'BankLineIds' => implode(',', $bankLineIds),
            'MopsJson' => json_encode(array_values($deposits)),
            'Reason' => $reason,
            'StampMode' => (string) config('recon.stamp_mode'),
            'UserId' => auth()->id(),
            'Basis' => $basis,
        ]);
    }
}
