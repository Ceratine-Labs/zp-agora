<?php

namespace Modules\StockRecon\Models;

use App\Models\BaseModel;
use Illuminate\Support\Carbon;

/**
 * @property int $BranchId
 * @property int $Id
 * @property string $GroupRef
 * @property Carbon $FromDate
 * @property Carbon $ToDate
 * @property array<int, int> $BranchIdsJson
 * @property array<string, scalar|null> $ParamsJson
 * @property string|null $Note
 * @property int $CreatedBy
 */
class StockReconRunGroup extends BaseModel
{
    protected $table = 'StockReconRunGroup';

    protected $casts = [
        'BranchId' => 'integer',
        'CreatedBy' => 'integer',
        'FromDate' => 'date',
        'ToDate' => 'date',
        'BranchIdsJson' => 'array',
        'ParamsJson' => 'array',
    ];
}
