<?php

namespace Modules\Recon\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Grid\GridRegistry;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use App\Support\BusinessDate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Recon\Grids\ReconOutstandingGrid;
use Modules\Recon\Http\Controllers\Concerns\AreaWorkbench;
use Modules\Recon\Services\ReconDashboardService;

/**
 * The bank recon dashboard: what is still outstanding, everywhere.
 *
 * Ryan, 13 Oct 2026: "a link under the bank recon that leads to a controller
 * that per branch shows what is outstanding … a summary view and a per branch
 * view … a dropdown default on all and can select multiple branches". And the
 * reason it has to be careful: "i think it will be a lot, excel struggles".
 *
 * THREE VIEWS OF ONE SCOPE. Sites (default: every site the person is granted)
 * and an optional date window, chosen once:
 *
 *   summary  the totals, the ageing, and one row per site
 *   sites    each site's areas, bank against PumpIT, with ageing
 *   items    the rows themselves, a page at a time (ReconOutstandingGrid)
 *
 * The first two are one procedure call that adds everything up on the server;
 * the third is a grid procedure that pages. No view loads the rows it does not
 * show.
 */
class ReconDashboardController extends Controller
{
    use AreaWorkbench;

    public const VIEWS = ['summary' => 'Summary', 'sites' => 'Per site', 'items' => 'Items'];

    public function __construct(
        protected ReconDashboardService $service,
        protected GridRegistry $registry,
        protected GridService $grids,
    ) {}

    public function index(Request $request, BranchContext $context): View
    {
        $view = array_key_exists((string) $request->query('view'), self::VIEWS) ? (string) $request->query('view') : 'summary';
        $asAt = BusinessDate::today()->toDateString();
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));
        $selected = $this->selected($request, $context);

        $data = [
            'view' => $view,
            'views' => self::VIEWS,
            'asAt' => $asAt,
            'from' => $from,
            'to' => $to,
            'selected' => $selected,
            'branches' => $context->isBranchWorkspace() ? null : $this->branches(),
            'area' => ReconOutstandingGrid::area(),
            'side' => ReconOutstandingGrid::side(),
            'areaLabels' => collect((array) config('recon.areas'))->map(fn (array $a) => $a['label'])
                ->put('Unclassified', 'Unclassified bank lines')->all(),
            'refusal' => null,
            'figures' => null,
            'grid' => null,
        ];

        if ($view === 'items') {
            $data['grid'] = $this->grids->build(
                $this->registry->findOrFail('app.recon.outstanding'),
                $request,
                $request->user()?->Id,
            );

            return view('recon::dashboard', $data);
        }

        try {
            $rows = $this->service->outstanding($selected, $context->allowed(), $from, $to, $asAt);
            $data['figures'] = $this->service->shape($rows);
        } catch (AgoraProcException $e) {
            $data['refusal'] = $e->getMessage();
        }

        return view('recon::dashboard', $data);
    }

    /**
     * The sites picked, checked against the grant. In the branch workspace the
     * site is pinned and the query string is ignored (feature-rules §3.3).
     *
     * @return array<int, int>
     */
    private function selected(Request $request, BranchContext $context): array
    {
        if ($context->isBranchWorkspace()) {
            return array_values(array_filter([$context->id()]));
        }

        $given = $request->query('branches');
        $ids = is_array($given) ? $given : ($given === null ? [] : explode(',', (string) $given));

        return collect($ids)
            ->reject(fn (mixed $id) => is_array($id) || is_object($id))
            ->map(fn (mixed $id) => (int) trim((string) $id))
            ->filter()
            ->unique()
            ->filter(fn (int $id) => $context->maySee($id))
            ->values()
            ->all();
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
