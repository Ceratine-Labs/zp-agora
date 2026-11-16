<?php

namespace Modules\StockRecon\Models;

use App\Models\BaseModel;
use Illuminate\Support\Carbon;

/**
 * A support request raised on a chain (Ryan, 16 Nov 2026): the chain as it was
 * when raised, the person's message, an optional annotated screenshot, and
 * whether head office has dealt with it. Branch-scoped like every BaseModel,
 * so a site sees its own requests and head office sees the sites it selects.
 *
 * @property int $Id
 * @property int $BranchId
 * @property int $RunId
 * @property int|null $RunLineId
 * @property int $AreaNo
 * @property string|null $AreaDescription
 * @property string $StockItemNo
 * @property string|null $ItemDescription
 * @property Carbon $ChainFrom
 * @property Carbon $ChainTo
 * @property string $Message
 * @property string|null $ScreenshotPath
 * @property string $Status
 * @property Carbon|null $ResolvedAt
 * @property int|null $ResolvedBy
 * @property string|null $ResolutionNote
 * @property Carbon $CreatedAt
 * @property int|null $CreatedBy
 */
class StockReconSupportRequest extends BaseModel
{
    protected $table = 'StockReconSupportRequest';

    protected $casts = [
        'ChainFrom' => 'date',
        'ChainTo' => 'date',
        'ResolvedAt' => 'datetime',
        'CreatedAt' => 'datetime',
    ];

    public function isOpen(): bool
    {
        return $this->Status === 'open';
    }
}
