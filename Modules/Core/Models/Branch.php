<?php

namespace Modules\Core\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A site, or one of the six administrative entities.
 *
 * @property int $BranchId
 * @property int $Id
 * @property string $Name
 * @property int|null $BrandId agora.Brand.Id
 * @property int|null $RegionId agora.Region.Id
 * @property int|null $ClassId PumpIT's SS_Class id, as migrated
 * @property int|null $FormatId agora.Format.Id
 * @property string $Status Trading | Mothballed | Non-trading
 * @property string $POSType BRANCH | NAMOS
 * @property bool $IsAdminEntity
 * @property string|null $LegacyName SS_Branch.BranchName as migrated
 * @property Carbon|null $MigratedAt
 * @property bool $IsTrading Status = 'Trading', kept by the procedures
 * @property bool $IsActive
 * @property int $SortOrder
 * @property Carbon|null $CreatedAt
 * @property Carbon|null $UpdatedAt
 * @property Carbon|null $DeletedAt
 *
 * BranchId is the site's own id — the same id space as dbo.SS_Branch.SSBranchId
 * and MIST_BranchId, so a join to legacy data needs no translation table.
 *
 * IsTrading is the distinction the legacy system never made explicitly: the
 * group, the property companies and the trusts are branches for accounting
 * purposes but never keep a trading day, and putting them in a day-close queue
 * is how the queue stops meaning anything.
 *
 * Status (T021) is the reason a branch does or does not trade, and IsTrading
 * follows it: agora.usp_Masters_SaveBranch and agora.usp_Migrate_Branch set
 * IsTrading = (Status = 'Trading'). Edit a branch through that procedure, not
 * through this model, or the two drift apart and the change is not audited.
 */
class Branch extends BaseModel
{
    use SoftDeletes;

    protected $table = 'Branch';

    public const DELETED_AT = 'DeletedAt';

    public const STATUSES = ['Trading', 'Mothballed', 'Non-trading'];

    public const POS_TYPES = ['BRANCH', 'NAMOS'];

    protected $casts = [
        'IsTrading' => 'boolean',
        'IsActive' => 'boolean',
        'IsAdminEntity' => 'boolean',
        'MigratedAt' => 'datetime',
    ];

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeTrading($query)
    {
        return $query->where('IsTrading', true)->where('IsActive', true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('SortOrder')->orderBy('Name');
    }

    /**
     * The sites one person may see, given their grants in agora.UserBranch.
     *
     * AN EMPTY GRANT LIST MEANS EVERY TRADING SITE, not none — head office
     * people are granted nothing individually, so absence of rows is the
     * grant. `BranchContext::maySee()` reads it the same way.
     *
     * A grant, when there IS one, is honoured whether or not the branch
     * trades. The trading filter exists to keep the group, the property
     * companies and the trusts out of a list nobody would pick them from; an
     * administrator who granted one has said the opposite in the clearest way
     * available, and silently dropping it would leave that person's scope bar
     * empty with nothing on screen to explain why.
     *
     * The nav and ResolveBranchContext both go through here, because the site
     * the scope bar OFFERS FIRST and the site the request is SCOPED TO have to
     * be the same one. Two copies of this rule is how they stop agreeing.
     *
     * @param  Builder<static>  $query
     * @param  array<int, int>  $allowed
     * @return Builder<static>
     */
    public function scopeVisibleTo($query, array $allowed)
    {
        return $allowed === []
            ? $query->trading()
            : $query->whereIn('BranchId', $allowed);
    }
}
