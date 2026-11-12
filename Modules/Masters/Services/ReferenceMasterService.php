<?php

namespace Modules\Masters\Services;

use App\Grid\GridRegistry;
use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\Branch;
use Modules\Masters\Grids\ReferenceMasterGrid;

/**
 * The reference masters' reads (T022). There are no writes: every one of these
 * is PumpIT's, listed rather than edited (v1__02a).
 *
 * One record is read by calling the listing's own procedure with @Key, so the
 * detail panel cannot describe a different record from the row it opened.
 * With @Key the procedure may hand back further result sets after its total —
 * the pumps on a tank, the categories under a standard category — which the
 * definition titles through related().
 */
class ReferenceMasterService
{
    public function __construct(
        private GridRegistry $registry,
        private ProcedureService $procedures,
        private BranchContext $context,
    ) {}

    public function definition(string $slug): ReferenceMasterGrid
    {
        $definition = $this->registry->findOrFail('app.setup.'.$slug);
        abort_unless($definition instanceof ReferenceMasterGrid, 404);

        return $definition;
    }

    /**
     * One record and what hangs off it, or a 404 when it does not exist, is
     * at a branch the caller may not see, or the caller may not see the master.
     *
     * @return array{row: object, related: array<int, Collection<int, object>>}
     */
    public function record(ReferenceMasterGrid $definition, int $branch, string $key): array
    {
        abort_if($key === '' || mb_strlen($key) > 200, 404);
        abort_unless($this->context->maySee($branch) || ! $definition->perBranch(), 404);

        $sets = $this->procedures->callSets($definition->procedureName(), [
            // A global master's record is not about one site; its counts are
            // over every site the caller may see, as on the listing.
            'BranchIds' => $definition->perBranch() ? (string) $branch : null,
            'Page' => 1,
            'PageSize' => 1,
            'Key' => $key,
            'UserId' => Auth::user()?->Id,
            'Authorized' => (int) Gate::allows($definition->permission()),
        ]);

        $row = ($sets[0] ?? collect())->first();
        abort_if($row === null, 404);

        return ['row' => $row, 'related' => array_values(array_slice($sets, 2))];
    }

    /**
     * The sites the branches selector offers, or null where it does not
     * appear: a global master, or the branch workspace, where the site is
     * already pinned (feature-rules §3.3).
     *
     * @return Collection<int, Branch>|null
     */
    public function branches(ReferenceMasterGrid $definition): ?Collection
    {
        if (! $definition->perBranch() || $this->context->isBranchWorkspace()) {
            return null;
        }

        return Branch::query()->visibleTo($this->context->allowed())->ordered()->get();
    }
}
