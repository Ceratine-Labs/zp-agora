<?php

namespace Modules\Core\Http\Middleware;

use App\Support\BranchContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Models\Branch;
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
            // `->` not `?->`: PHP's ?? already suppresses a read on null, so
            // the nullsafe would be redundant. The `?->` on the line above is
            // NOT redundant — it is compared, not coalesced.
            : ($request->query('ws') ?? $request->session()->get('agora.workspace') ?? $role->Workspace ?? 'ho');

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

        /*
         * The branch workspace is never contextless.
         *
         * It means "I am working at one site", and the scope bar's site
         * selector is how you say which. A user with no home branch — an
         * administrator dropping into a site to look at something — would
         * otherwise land with a null context while the selector displayed the
         * first option, so the bar would name a site the request was not
         * actually scoped to. Falling back to the first site they may see
         * makes the two agree.
         */
        if ($context->isBranchWorkspace() && $context->id() === null) {
            $first = $this->firstVisibleBranch($context);

            if ($first !== null) {
                $context->set($first);
                $request->session()->put('agora.branch', $first);
            }
        }

        return $next($request);
    }

    /**
     * The first trading site this caller may see, in the order the scope bar
     * lists them — so "the first option" and "the branch in context" are the
     * same site rather than two guesses that happen to agree.
     */
    protected function firstVisibleBranch(BranchContext $context): ?int
    {
        $id = Branch::query()
            ->acrossBranches()
            ->when(
                $context->allowed() !== [],
                fn ($query) => $query->whereIn('BranchId', $context->allowed())
            )
            ->trading()
            ->ordered()
            ->value('BranchId');

        return $id === null ? null : (int) $id;
    }
}
