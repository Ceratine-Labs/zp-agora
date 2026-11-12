<?php

namespace Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Models\User;
use Modules\Operations\Services\OperationsWallService;
use Modules\Operations\Services\WallAccess;
use Modules\Operations\Services\WallScope;
use Modules\Reports\Services\MyQueueAccess;

class OperationsWallController extends Controller
{
    public function __construct(private WallScope $scope, private WallAccess $access, private OperationsWallService $wall, private BranchContext $context) {}

    public function index(Request $request): View
    {
        $user = $this->user($request);
        $sites = $this->scope->sites();
        $selected = $this->scope->selected($request, $sites);
        abort_if($request->query->has('branches') && $selected === [], 422, 'No permitted sites match this selection.');

        return view('operations::index', [
            'sites' => $sites, 'endpoint' => route('app.operations.snapshot', ['ws' => $this->context->workspace(), 'branch' => $this->context->id()]),
            'userKey' => (string) $user->Id,
            'scope' => ['branches' => $request->query->has('branches') ? $selected : []],
        ]);
    }

    public function snapshot(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $sites = $this->scope->sites();
        $selected = $this->scope->selected($request, $sites);

        $queues = $user->can('reports.my-queue.view') ? app(MyQueueAccess::class)->allowed($user) : [];

        return response()->json($this->wall->snapshot($selected, $this->access->sources($user), $queues))
            ->header('Cache-Control', 'private, no-store');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $this->access->wall($user), 403);

        return $user;
    }
}
