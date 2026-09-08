<?php

namespace Modules\Recon\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Recon\Http\Controllers\Concerns\AreaWorkbench;
use Modules\Recon\Services\ReconService;

/**
 * Trace: one value, and everything it touches.
 *
 * "Where did batch 4412 come from" used to mean opening six tables by hand in
 * SSMS, and before Agora it could not be answered at all — the PumpIT
 * executable stamps ReconState and ReconBatchNo and records nothing about why.
 * That is why question 3.7 of the findings, "who stamped the historical
 * reconciliations?", could only be guessed at from 1,570 orphaned bank lines.
 *
 * ONE BOX, NO TYPE. The person types a batch number, a bank reference, a bag,
 * a slip, a terminal or a run number. They do not know which kind of thing it
 * is — that is frequently the question — so the screen does not ask. Every
 * result set is asked the same term and each says what it found.
 *
 * NOT AN AREA TAB, deliberately. A trace crosses all five areas and both
 * sides; scoping it to one would mean knowing the answer before asking.
 */
class ReconTraceController extends Controller
{
    use AreaWorkbench;

    /** The seven result sets, in the order the procedure returns them. */
    private const SETS = [
        'runs' => 'Runs',
        'proposals' => 'Proposals',
        'batches' => 'Batches',
        'matches' => 'Rows touched',
        'stamps' => 'Stamps',
        'bank' => 'Bank statement',
        'deposits' => 'Deposits',
    ];

    public function __construct(
        protected ReconService $service,
        protected ProcedureService $procedures,
    ) {}

    public function index(Request $request, BranchContext $context): View
    {
        $term = trim((string) $request->query('q', ''));

        // Ninety days, matching the procedure's own default. Stated on the
        // screen rather than assumed: the window is what keeps a LIKE off the
        // whole of a 249 GB statement table, so the reader has to know it is
        // there and be able to widen it deliberately.
        $to = $request->query('to') ?: Carbon::today()->toDateString();
        $from = $request->query('from') ?: Carbon::parse($to)->subDays(90)->toDateString();

        return view('recon::trace', [
            'term' => $term,
            'from' => $from,
            'to' => $to,
            'branches' => $context->isBranchWorkspace() ? null : $this->branches(),
            'branchId' => (int) $request->query('branch_id', $context->id() ?? 0),
            'labels' => self::SETS,
            'procedure' => config('agora.schema').'.usp_Recon_Trace',
            ...$this->trace($term, $from, $to, $request, $context),
        ]);
    }

    /**
     * Run the trace, or explain why it did not.
     *
     * A term shorter than three characters is refused by the procedure, and
     * the refusal is shown rather than swallowed — an empty screen would read
     * as "this reference appears nowhere", which is the opposite of what
     * happened.
     *
     * @return array{sets: array<string, Collection<int, object>>|null, refusal: string|null}
     */
    protected function trace(string $term, string $from, string $to, Request $request, BranchContext $context): array
    {
        if ($term === '') {
            return ['sets' => null, 'refusal' => null];
        }

        $branchId = (int) $request->query('branch_id', 0);

        try {
            $sets = $this->procedures->callSets('usp_Recon_Trace', [
                'Term' => $term,
                // One site when the reader has picked one, or the workspace's
                // when it is pinned. A branch id in a query string is not an
                // authorisation, so it is checked before it is sent.
                'BranchIds' => $this->scope($branchId, $context),
                'DateFrom' => $from,
                'DateTo' => $to,
                // The grant, which is a different question from the selection:
                // it must still hold when nothing is selected.
                'AllowedBranchIds' => ($allowed = $context->allowed()) === [] ? null : implode(',', $allowed),
                'MaxRows' => 200,
            ]);
        } catch (AgoraProcException $e) {
            return ['sets' => null, 'refusal' => $e->getMessage()];
        }

        return [
            'sets' => collect(self::SETS)
                ->keys()
                ->mapWithKeys(fn (string $key, int $i) => [$key => $sets[$i] ?? collect()])
                ->all(),
            'refusal' => null,
        ];
    }

    /** The site filter, checked. Null is every site the grant allows. */
    protected function scope(int $branchId, BranchContext $context): ?string
    {
        if ($context->isBranchWorkspace()) {
            return $context->id() === null ? null : (string) $context->id();
        }

        if ($branchId <= 0) {
            return null;
        }

        abort_unless($context->maySee($branchId), 403, 'You may not look at that branch.');

        return (string) $branchId;
    }
}
