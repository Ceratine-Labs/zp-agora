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
        $view = $report === 'my-queue' ? 'reports::my-queue' : 'reports::show';
        $input = [
            'branch_ids' => $this->scopedBranchIds($request, $context),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'search' => $request->query('q'),
            'sort' => $request->query('sort'),
            'dir' => $request->query('dir'),
            'page' => $request->query('page'),
        ];

        try {
            $definition = $this->service->definition($report)
                ?? throw new ReportNotFound($report);

            $result = $this->service->run($report, $input);
        } catch (ReportNotFound $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        } catch (AgoraProcException $e) {
            // A procedure that REFUSES — a range it cannot usefully answer, so
            // far the only one — is a message to the user, not a 500. An
            // unexpected fault is still a QueryException and still a 500.
            return view($view, [
                'report' => $this->service->definition($report),
                'rows' => collect(),
                'total' => 0,
                'params' => $this->service->parametersFor($report, $input),
                'summaries' => collect(),
                'summariesAvailable' => false,
                'refusal' => $e->getMessage(),
                'branches' => $this->branches($context),
                'context' => $context,
                'ceiling' => (int) config('reports.export_ceiling'),
            ]);
        }

        return view($view, [
            'report' => $definition,
            'rows' => $result['rows'],
            'total' => $result['total'],
            'params' => $result['params'],
            'summaries' => $result['summaries'],
            'summariesAvailable' => $result['summariesAvailable'],
            'refusal' => null,
            'branches' => $this->branches($context),
            'context' => $context,
            'ceiling' => (int) config('reports.export_ceiling'),
        ]);
    }

    /**
     * One audit trail change, opened under its row (row-detail.js) or on a page
     * of its own when the link is followed directly.
     */
    public function auditChange(string $ledger, int $id, Request $request, BranchContext $context): View
    {
        $found = $this->service->auditChange($ledger, $id, $this->allowedBranchIds($context))
            ?? throw new NotFoundHttpException('No such change, or not one you may see.');

        return view($request->ajax() ? 'reports::audit.change' : 'reports::audit.page', $found);
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
            return $this->allowedBranchIds($context);
        }

        $selection = ReportsService::branchSelection($request->query('branches'));
        if (! $selection['hasSelection']) {
            return $context->allowed();
        }

        $selected = collect($selection['ids'])
            ->filter(fn (int $id) => $context->maySee($id))
            ->values()
            ->all();

        // [] becomes SQL NULL/all sites. An explicitly rejected selection
        // must stay a nonmatching filter instead of falling back to all.
        return $selected === [] ? [-1] : $selected;
    }

    /** @return array<int, int> */
    protected function allowedBranchIds(BranchContext $context): array
    {
        if ($context->workspace() === 'branch') {
            $id = $context->id();

            return [$id !== null && $id > 0 ? $id : -1];
        }

        return $context->allowed();
    }

    /** @return Collection<int, Branch> */
    protected function branches(BranchContext $context): Collection
    {
        $allowed = $this->allowedBranchIds($context);

        // BaseModel also exposes the group branch for shared reference data.
        // A report picker must offer only the sites the caller may report on.
        return Branch::query()->acrossBranches()
            ->where('IsActive', true)
            ->when($allowed !== [], fn ($query) => $query->whereIn('BranchId', $allowed))
            ->orderBy('Name')
            ->get();
    }
}
