<?php

namespace Modules\Recon\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One press of Preview across every site.
 *
 * The runs underneath are ordinary ReconRuns, each about its own branch and
 * each stamped with this group's GroupRef. Nothing about a run changes because
 * it was launched from here — which is the point: a group is a way of pressing
 * the button, not a second kind of reconciliation.
 *
 * @property int $BranchId
 * @property int $Id
 * @property string $GroupRef
 * @property string $ReconArea
 * @property Carbon $FromDate
 * @property Carbon $ToDate
 * @property string|null $ParamsJson
 * @property string|null $Note
 * @property string $Status
 * @property int $BranchCount
 * @property int $CompletedCount
 * @property int $FailedCount
 * @property Carbon|null $CompletedAt
 * @property Carbon|null $CommittedAt
 * @property int|null $CommittedBy
 * @property int $CommittedBranches
 * @property int $CommittedRows
 * @property string $CommittedTotal
 * @property Carbon|null $CreatedAt
 * @property Carbon|null $UpdatedAt
 * @property int|null $CreatedBy
 */
class ReconRunGroup extends BaseModel
{
    protected $table = 'ReconRunGroup';

    protected $casts = [
        'BranchId' => 'integer',
        'BranchCount' => 'integer',
        'CompletedCount' => 'integer',
        'FailedCount' => 'integer',
        'CommittedBranches' => 'integer',
        'CommittedRows' => 'integer',
        'FromDate' => 'date',
        'ToDate' => 'date',
        'CompletedAt' => 'datetime',
        'CommittedAt' => 'datetime',
    ];

    /**
     * NO ACCESSOR ON GroupRef, AND THAT IS LOAD-BEARING.
     *
     * There was one, lowercasing it, because SQL Server hands a
     * UNIQUEIDENTIFIER back uppercase while Str::uuid() makes it lowercase —
     * so a freshly created group and the same group read back produced two
     * different URLs. Cosmetic, and the fix for it silently emptied the
     * relation below.
     *
     * Eloquent matches a HasMany to its parent IN PHP: it builds a dictionary
     * keyed on the child's foreign key — uppercase, straight from the column —
     * and looks it up by the parent's local key, which the accessor had
     * lowercased. The DATABASE never disagreed, because the collation is
     * case-insensitive and `$group->runs()->count()` returned 25 all along.
     * Only `$group->runs` came back empty, which is what every row on the
     * group screen renders from: twenty-five sites that had all previewed,
     * every one of them reading "Waiting…", above a stat strip that correctly
     * said 21 previewed and 4 refused. Ryan caught it on live.
     *
     * The URL is uppercase now, consistently, because ReconGroupService::start()
     * returns the model as the database has it. A cosmetic inconsistency is
     * worth less than a relation that works.
     */

    /**
     * The runs launched under this press.
     *
     * acrossBranches(), and it has to be: the group row carries the GROUP
     * entity's id and every run carries its own SITE, so the branch scope
     * applied to this relation would return nothing at all. The runs are
     * still narrowed — by GroupRef, which is stricter than a branch — and
     * every caller of this is a head-office screen.
     *
     * @return HasMany<ReconRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(ReconRun::class, 'GroupRef', 'GroupRef')
            ->acrossBranches()
            ->orderBy('BranchId');
    }

    /** @return array<string, scalar|null> */
    public function params(): array
    {
        return json_decode($this->ParamsJson ?? '[]', true) ?: [];
    }

    /**
     * The runs this group holds, keyed by the site they are about.
     *
     * @return Collection<int, ReconRun>
     */
    public function runsByBranch(): Collection
    {
        return $this->runs->keyBy('BranchId');
    }

    /** Every site attempted, whatever the answer was. */
    public function isFinished(): bool
    {
        return $this->CompletedCount + $this->FailedCount >= $this->BranchCount;
    }

    public function isCommitted(): bool
    {
        return $this->CommittedAt !== null;
    }
}
