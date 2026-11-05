<?php

namespace Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Reports\Services\WasteAuthorizationService;

final class WasteAuthorizationController extends Controller
{
    public function __invoke(Request $request, BranchContext $context, WasteAuthorizationService $service): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'min:1'], 'date' => ['required', 'date_format:Y-m-d'],
            'shift' => ['required', 'integer', 'min:1'], 'area' => ['required', 'integer', 'min:0'],
            'item' => ['required', 'string', 'max:10'], 'captured_good' => ['required', 'numeric', 'min:0'],
            'captured_at' => ['nullable', 'date'],
            'decision' => ['required', 'in:authorize,withdraw'],
            'authorized_good' => ['required_if:decision,authorize', 'nullable', 'numeric', 'min:0'],
        ]);
        $branch = (int) $data['branch_id'];
        abort_unless($context->maySee($branch) && ($context->workspace() !== 'branch' || $context->id() === $branch), 403);
        $service->decide($data, (int) $request->user()->getAuthIdentifier());

        return back()->with('status', 'Waste authorization recorded.');
    }
}
