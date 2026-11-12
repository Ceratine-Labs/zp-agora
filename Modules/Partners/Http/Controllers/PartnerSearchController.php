<?php

namespace Modules\Partners\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Partners\Services\PartnerService;

/**
 * The Partners source for the command palette (plan §3.10): suppliers and
 * account customers matching ?q=, best first, as
 * `{results: [{kind, code, label, detail, url}]}`.
 *
 * The palette itself (T012) is not built yet; this is the endpoint its
 * registrar will call, in the shape it will render. Each kind is offered only
 * to somebody who may view it, and somebody who may view neither is refused.
 */
class PartnerSearchController extends Controller
{
    public function __invoke(Request $request, PartnerService $service): JsonResponse
    {
        abort_unless(Gate::any(['partners.suppliers.view', 'partners.customers.view']), 403);

        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);

        return response()->json(['results' => $q === '' ? [] : $service->search($q)]);
    }
}
