<?php

namespace Modules\Core\Http\Controllers;

use App\Grid\GridColumnState;
use App\Grid\GridDefinition;
use App\Grid\GridNotFound;
use App\Grid\GridRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Models\UserGridColumn;

/**
 * Where a grid's column chooser, resize and text size are saved.
 *
 * Thin on purpose. Everything that decides what is allowed to be stored lives
 * in GridColumnState, so the endpoint cannot become a second, laxer copy of the
 * whitelist — the shape ZP's had, where the rules lived at the boundary and the
 * next endpoint added did not get them.
 *
 * Two things are refused rather than accepted quietly:
 *
 *  - a GridKey no grid in `config/grids.php` claims. A layout stored under one
 *    is a row nothing will ever read again, and it would also let anyone fill
 *    the table with keys of their own choosing.
 *  - anything outside GridColumnState::KEYS. The response says what survived,
 *    so a caller sending something unrecognised can see it did not land rather
 *    than assume it did.
 *  - a request with no user. A layout is a person's.
 */
class GridStateController extends Controller
{
    public function __construct(private GridRegistry $registry) {}

    public function store(string $grid, Request $request): JsonResponse
    {
        $definition = $this->definition($grid);

        // The route is behind `auth`, so there is always a user here. A guard
        // for the null case would be a branch nothing can reach and a test
        // nothing can write — the 401 belongs to the middleware and is asserted
        // there.
        $userId = (int) $request->user()->Id;

        /** @var array<string, mixed> $input */
        $input = $request->all();
        $state = GridColumnState::sanitise($input, $definition);

        UserGridColumn::put($userId, $definition->key(), $state);

        return response()->json(['saved' => true, 'state' => $state]);
    }

    /** Back to the catalogue's own layout — order, widths, visibility, text size. */
    public function destroy(string $grid, Request $request): JsonResponse
    {
        $definition = $this->definition($grid);
        $userId = (int) $request->user()->Id;

        UserGridColumn::forget($userId, $definition->key());

        return response()->json(['saved' => true, 'state' => new \stdClass]);
    }

    private function definition(string $grid): GridDefinition
    {
        try {
            return $this->registry->findOrFail($grid);
        } catch (GridNotFound $e) {
            abort(404, $e->getMessage());
        }
    }
}
