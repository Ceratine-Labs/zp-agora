<?php

namespace Modules\StockRecon\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\StockRecon\Grids\CountingAreaGrid;
use Modules\StockRecon\Http\Requests\SaveCountingAreaRequest;
use Modules\StockRecon\Services\CountingAreaService;

class CountingAreaController extends Controller
{
    public function __construct(private CountingAreaService $service) {}

    public function index(Request $request, GridService $grids): View
    {
        return view('stockrecon::counting-areas.index', [
            'grid' => $grids->build(new CountingAreaGrid, $request, $request->user()?->Id),
        ]);
    }

    public function show(int $branch, int $area, Request $request): View
    {
        return view($request->ajax() ? 'stockrecon::counting-areas.detail' : 'stockrecon::counting-areas.show',
            $this->service->get($branch, $area));
    }

    public function edit(int $branch, int $area): View
    {
        return view('stockrecon::counting-areas.edit', $this->service->get($branch, $area));
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
}
