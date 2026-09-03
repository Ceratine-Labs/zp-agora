<?php

namespace Modules\Core\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A site, or one of the six administrative entities.
 *
 * BranchId is the site's own id — the same id space as dbo.SS_Branch.SSBranchId
 * and MIST_BranchId, so a join to legacy data needs no translation table.
 *
 * IsTrading is the distinction the legacy system never made explicitly: the
 * group, the property companies and the trusts are branches for accounting
 * purposes but never keep a trading day, and putting them in a day-close queue
 * is how the queue stops meaning anything.
 */
class Branch extends BaseModel
{
    use SoftDeletes;

    protected $table = 'Branch';

    public const DELETED_AT = 'DeletedAt';

    protected $casts = [
        'IsTrading' => 'boolean',
        'IsActive' => 'boolean',
    ];

    public function scopeTrading($query)
    {
        return $query->where('IsTrading', true)->where('IsActive', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('SortOrder')->orderBy('Name');
    }
}
