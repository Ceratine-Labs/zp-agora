<?php

namespace Modules\People\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Grid\GridRegistry;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Core\Models\Branch;
use Modules\People\Http\Requests\AddPostRequest;
use Modules\People\Services\EmployeeService;
use Modules\People\Services\EmployeeWriteService;

/**
 * Setup → People and assets → Employee posts (T023). Read-only.
 *
 * With exactly one site in scope the page also says what that site's next
 * post number would be (agora.usp_People_NextPost) — the number a new post
 * will take once adding one is built.
 */
class EmployeePostController extends Controller
{
    public function __construct(private EmployeeService $service, private BranchContext $context) {}

    public function index(Request $request, GridRegistry $registry, GridService $grids): View
    {
        $grid = $grids->build($registry->findOrFail('app.setup.employee-posts'), $request, $request->user()?->Id);
        $selected = array_values(array_filter($grid->gridQuery->branchIds, fn (int $id) => $id > 0));
        $branch = count($selected) === 1 ? Branch::query()->visibleTo($this->context->allowed())->where('BranchId', $selected[0])->first() : null;

        return view('people::posts.index', [
            'grid' => $grid,
            'branches' => Branch::query()->visibleTo($this->context->allowed())->ordered()->get(),
            'branch' => $branch,
            'nextPost' => $branch === null ? null : $this->service->nextPost((int) $branch->BranchId),
            'writable' => EmployeeWriteService::live(),
        ]);
    }

    /** Add a post at one branch — a named write to BRN_EmployeePost (PM decision 01a1ceba-4d74). */
    public function store(AddPostRequest $request, EmployeeWriteService $writes): RedirectResponse
    {
        abort_unless(EmployeeWriteService::live(), 404);
        $branch = (int) $request->validated('BranchId');

        try {
            $result = $writes->addPost($branch, $request->validated());
        } catch (AgoraProcException $e) {
            return back()->withInput()->withErrors(['refusal' => $e->getMessage()]);
        }

        return redirect()->route('app.setup.employee-posts.index', ['branches' => [$branch]])->with('status', $result->Message);
    }
}
