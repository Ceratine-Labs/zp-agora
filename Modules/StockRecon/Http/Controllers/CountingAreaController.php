<?php

namespace Modules\StockRecon\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use App\Support\BusinessDate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\StockRecon\Grids\CountingAreaGrid;
use Modules\StockRecon\Http\Requests\SaveCountingAreaRequest;
use Modules\StockRecon\Services\AreaShiftCompletionService;
use Modules\StockRecon\Services\CountingAreaService;
use Modules\StockRecon\Services\ItemRemovalService;

class CountingAreaController extends Controller
{
    public function __construct(private CountingAreaService $service, private ItemRemovalService $removals, private AreaShiftCompletionService $completions) {}

    public function index(Request $request, GridService $grids): View
    {
        return view('stockrecon::counting-areas.index', [
            'grid' => $grids->build(new CountingAreaGrid, $request, $request->user()?->Id),
        ]);
    }

    public function show(int $branch, int $area, Request $request): View
    {
        return view($request->ajax() ? 'stockrecon::counting-areas.detail' : 'stockrecon::counting-areas.show',
            $this->service->get($branch, $area) + ['removals' => $this->removals->history($branch, $area), 'completions' => $this->completions->history($branch, $area)]);
    }

    public function edit(int $branch, int $area): View
    {
        return view('stockrecon::counting-areas.edit', $this->service->get($branch, $area) + ['removals' => $this->removals->history($branch, $area), 'completions' => $this->completions->history($branch, $area)]);
    }

    public function update(int $branch, int $area, SaveCountingAreaRequest $request): RedirectResponse
    {
        try {
            $result = $this->service->save($branch, $area, $request->validated());
        } catch (AgoraProcException $e) {
            return back()->withInput()->withErrors(['refusal' => $e->getMessage()]);
        }

        return redirect()->route('app.stockrecon.countingareas.show', compact('branch', 'area'))
            ->with('status', $result->Message);
    }

    public function recordRemoval(int $branch, int $area, Request $request): RedirectResponse
    {
        $this->service->get($branch, $area);
        $input = $request->validate(['item' => 'required|string|max:10', 'date' => 'required|date_format:Y-m-d', 'shift' => 'required|integer|between:1,3']);
        $this->removals->record($branch, $area, $input, (int) $request->user()->Id);

        return redirect()->route('app.stockrecon.countingareas.show', compact('branch', 'area'))->with('status', 'Item removal recorded. Preview the reconciliation again.');
    }

    public function withdrawRemoval(int $branch, int $area, int $removal, Request $request): RedirectResponse
    {
        $this->service->get($branch, $area);
        $this->removals->withdraw($branch, $area, $removal, (int) $request->user()->Id);

        return redirect()->route('app.stockrecon.countingareas.show', compact('branch', 'area'))->with('status', 'Item removal withdrawn. Preview the reconciliation again.');
    }

    public function recordCompletion(int $branch, int $area, Request $request): RedirectResponse
    {
        $this->service->get($branch, $area);
        $input = $request->validate(['date' => 'required|date_format:Y-m-d|before_or_equal:'.BusinessDate::today()->toDateString(), 'shift' => 'required|integer|between:1,3']);
        $this->completions->record($branch, $area, $input, (int) $request->user()->Id);

        return redirect()->route('app.stockrecon.countingareas.show', compact('branch', 'area'))->with('status', 'Area shift completion recorded. Preview the reconciliation again.');
    }

    public function withdrawCompletion(int $branch, int $area, int $completion, Request $request): RedirectResponse
    {
        $this->service->get($branch, $area);
        $this->completions->withdraw($branch, $area, $completion, (int) $request->user()->Id);

        return redirect()->route('app.stockrecon.countingareas.show', compact('branch', 'area'))->with('status', 'Area shift completion withdrawn. Preview the reconciliation again.');
    }
}
