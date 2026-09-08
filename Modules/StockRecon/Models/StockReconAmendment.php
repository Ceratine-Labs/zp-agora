<?php

namespace Modules\StockRecon\Models;

use App\Models\BaseModel;
use Illuminate\Support\Carbon;

/**
 * What a commit actually did to one shift, with what was there before.
 *
 * The table that makes a reversal exact, and the whole difference from
 * dbo.sp_UpdateAUTOStockReconBalancing, which records nothing and can undo
 * nothing. In journal mode it is also the output: the worklist an admin
 * applies by hand, with the prior pair beside the new one.
 *
 * @property int $BranchId
 * @property int $Id
 * @property int $RunId
 * @property int $RunLineId
 * @property int $AreaNo
 * @property string $StockItemNo
 * @property Carbon $TransactionDate
 * @property int $ShiftNo
 * @property string $PriorQtyOpen
 * @property string $PriorQtyClose
 * @property string $NewQtyOpen
 * @property string $NewQtyClose
 * @property string $StampMode
 * @property string $State
 * @property Carbon|null $ReversedAt
 * @property int|null $ReversedBy
 */
class StockReconAmendment extends BaseModel
{
    protected $table = 'StockReconAmendment';

    protected $casts = [
        'BranchId' => 'integer',
        'RunId' => 'integer',
        'RunLineId' => 'integer',
        'AreaNo' => 'integer',
        'ShiftNo' => 'integer',
        'TransactionDate' => 'date',
        'ReversedAt' => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->State === 'active';
    }

    /** Did this one reach the customer's estate, or only Agora's ledger? */
    public function reachedPumpIt(): bool
    {
        return $this->StampMode === 'live';
    }
}
