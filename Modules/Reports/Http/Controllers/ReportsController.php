<?php

namespace Modules\Reports\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Core\Models\Branch;
use Modules\Reports\Services\ReportNotFound;
use Modules\Reports\Services\ReportsService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The fifteen Today reports.
 *
 * Thin on purpose: resolve the scope out of the query string, hand it to the
 * service, render. Every rule is in the procedure.
 *
 * The scope travels in the URL rather than in the session, so a link pasted to
 * a colleague opens what the sender was looking at (feature-rules, proposed §C).
 */
class ReportsController extends Controller
{
    public function __construct(protected ReportsService $service) {}

    /** The catalogue: every report, grouped as the Today menu groups them. */
    public function index(): View
    {
        return view('reports::index', [
            'catalogue' => $this->service->catalogue(),
        ]);
    }

    /** One report, run. */
    public function show(string $report, Request $request, BranchContext $context): View
    {
        try {
            $definition = $this->service->definition($report)
                ?? throw new ReportNotFound($report);

            $result = $this->service->run($report, [
                'branch_ids' => $this->scopedBranchIds($request, $context),
                'from' => $request->query('from'),
                'to' => $request->query('to'),
                'search' => $request->query('q'),
                'sort' => $request->query('sort'),
                'dir' => $request->query('dir'),
                'page' => $request->query('page'),
            ]);
        } catch (ReportNotFound $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        } catch (AgoraProcException $e) {
            // A procedure that REFUSES — a range it cannot usefully answer, so
            // far the only one — is a message to the user, not a 500. An
            // unexpected fault is still a QueryException and still a 500.
            return view('reports::show', [
                'report' => $this->service->definition($report),
                'rows' => collect(),
                'total' => 0,
                'params' => [],
                'refusal' => $e->getMessage(),
                'branches' => $this->branches($context),
                'context' => $context,
                'ceiling' => (int) config('reports.export_ceiling'),
            ]);
        }

        return view('reports::show', [
            'report' => $definition,
            'rows' => $result['rows'],
            'total' => $result['total'],
            'params' => $result['params'],
            'refusal' => null,
            'branches' => $this->branches($context),
            'context' => $context,
            'ceiling' => (int) config('reports.export_ceiling'),
        ]);
    }

    /**
     * Which branches the report may see.
     *
     * In the BRANCH workspace the site is already pinned and the selector does
     * not appear, so whatever arrives in the query string is ignored —
     * otherwise the scope would be a URL parameter anybody could edit
     * (feature-rules §3.3).
     *
     * @return array<int, int>
     */
    protected function scopedBranchIds(Request $request, BranchContext $context): array
    {
        if ($context->workspace() === 'branch') {
            return array_filter([$context->id()]);
        }

        /*
         * Checked, not merely parsed.
         *
         * These ids become a CSV argument to a stored procedure, which never
         * passes through BranchScope — so nothing else in the request would
         * stop a hand-edited query string reading a site the caller is not
         * granted. maySee() reads an empty grant list as "every branch", the
         * way head-office users are configured.
         */
        return collect(ReportsService::branchIds($request->query('branches')))
            ->filter(fn (int $id) => $context->maySee($id))
            ->values()
            ->all();
    }

    /** @return Collection<int, Branch> */
    protected function branches(BranchContext $context): Collection
    {
        return Branch::query()
            ->where('IsActive', true)
            ->orderBy('Name')
            ->get();
    }
}
