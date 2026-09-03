<?php

namespace Modules\Core\Http\Middleware;

use App\Support\BranchContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides, once per request, which sites the caller is looking at.
 *
 * Order matters and is the whole point: the branch global scope is consulted
 * by the first model query in the request, so the context has to be populated
 * before a controller runs. Doing it in a controller — or worse, lazily on
 * first use — is how a query slips out unscoped.
 *
 * Workspace comes from the URL first (so a link can carry it, per plan §3.10),
 * then the session, then the user's role. A branch user is pinned to their
 * home site and cannot switch to head office by editing the query string.
 */
class ResolveBranchContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(BranchContext::class);
        $user = Auth::user();

        if (! $user) {
            return $next($request);
        }

        $role = $user->role;
        $forced = $role?->Workspace === 'branch' || $user->HomeBranchId !== null;

        $workspace = $forced
            ? 'branch'
            : ($request->query('ws') ?? $request->session()->get('agora.workspace') ?? $role?->Workspace ?? 'ho');

        $context->setWorkspace($workspace);
        $request->session()->put('agora.workspace', $context->workspace());

        // An empty grant list means every branch — head office users are not
        // granted 31 rows that would need maintaining as sites open.
        $context->setAllowed($user->allowedBranchIds());

        $branchId = $request->query('branch')
            ?? $request->session()->get('agora.branch')
            ?? $user->HomeBranchId;

        // A query string cannot widen access: an unauthorised branch id is
        // dropped back to the user's home site rather than honoured.
        if ($branchId !== null && $context->maySee((int) $branchId)) {
            $context->set((int) $branchId);
            $request->session()->put('agora.branch', (int) $branchId);
        } else {
            $context->set($user->HomeBranchId ? (int) $user->HomeBranchId : null);
        }

        return $next($request);
    }
}
