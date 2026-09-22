<?php

namespace Modules\Product\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Grid\GridRegistry;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Product\Http\Requests\SaveStockItemRequest;
use Modules\Product\Http\Requests\SetStockItemFlagsRequest;
use Modules\Product\Services\StockMasterService;
use Modules\Product\Support\StockItemFlags;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Setup → Trading rules → Stock recon master (T025).
 *
 * Thin, like every controller here: the listing is a GridDefinition the grid
 * service builds, and the detail panel is one call to the service. Nothing in
 * this file knows what a stock item is.
 */
class StockMasterController extends Controller
{
    public function __construct(
        private GridRegistry $registry,
        private GridService $grids,
        private StockMasterService $service,
    ) {}

    /** The listing. */
    public function index(Request $request): View
    {
        return view('product::stock.index', [
            'grid' => $this->grids->build(
                $this->registry->findOrFail('app.master.stock'),
                $request,
                $request->user()?->Id,
            ),
            // The batch action's picker. From the one list the FormRequest and
            // the test read too — see StockItemFlags.
            'flags' => StockItemFlags::choices(),
        ]);
    }

    /**
     * One stock line, whole.
     *
     * The item number is per branch, so the route carries both halves — an
     * item id alone would be ambiguous across twenty-two sites, which is the
     * shape of the legacy estate rather than a choice.
     */
    public function show(Request $request, int $branch, string $item): View
    {
        $stockItem = $this->service->find($branch, $item);

        if ($stockItem === null) {
            throw new NotFoundHttpException("No stock item {$item} at branch {$branch}.");
        }

        return view('product::stock.show', [
            'item' => $stockItem,
            'legacy' => $this->service->legacy($branch, $item),
            'area' => $this->service->area($branch, (int) $stockItem->AreaNo),
            'areas' => $this->service->areas($branch),
        ]);
    }

    /**
     * One flag, on every ticked line.
     *
     * Back to the LISTING, not to a detail page: the person is standing in
     * front of a grid with forty rows ticked and the thing they want to see
     * next is the same grid with the flag changed. `back()` keeps the page,
     * the sort, the filters and the branch selection they were looking at,
     * which a redirect to the bare index would throw away.
     *
     * A refusal lands the same way a single save's does — on the screen that
     * asked for it, as a message rather than a 500. See `update()`.
     */
    public function flags(SetStockItemFlagsRequest $request): RedirectResponse
    {
        try {
            $result = $this->service->setFlag(
                $request->pairs(),
                (string) $request->validated('flag'),
                (bool) $request->validated('value'),
                (string) $request->validated('reason'),
                $request->user()?->Id,
            );
        } catch (AgoraProcException $e) {
            return back()->withInput()->withErrors(['refusal' => $e->getMessage()]);
        }

        return back()->with('status', $result->Message ?? 'Done.');
    }

    /**
     * Save, retire, park or unpark.
     *
     * A REFUSAL COMES BACK TO THE FORM, not to an error page. Every rule lives
     * in the procedure, so the only thing this layer can usefully do with an
     * AgoraProcException is put its message where the person who typed the
     * value will read it — "another line already uses that POS code on that
     * POS system" is an instruction, and a 500 is not. Anything that is NOT a
     * refusal is a fault and is left to escape, because a deadlock or a
     * missing procedure must not be rendered as though the user did something
     * wrong.
     */
    public function update(SaveStockItemRequest $request, int $branch, string $item): RedirectResponse
    {
        try {
            $result = $this->service->save(
                $branch,
                $item,
                (string) $request->validated('action'),
                $request->validated(),
                $request->user()?->Id,
            );
        } catch (AgoraProcException $e) {
            return back()
                ->withInput()
                ->withErrors(['refusal' => $e->getMessage()]);
        }

        return redirect()
            ->route('app.master.stock.show', ['branch' => $branch, 'item' => $item])
            ->with('status', $result->Message ?? 'Saved.');
    }
}
