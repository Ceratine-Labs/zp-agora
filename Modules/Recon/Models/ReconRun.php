<?php

namespace Modules\Recon\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One press of Preview.
 *
 * @property int $BranchId
 * @property int $Id
 * @property string $GroupRef
 * @property string $ReconArea
 * @property Carbon $FromDate
 * @property Carbon $ToDate
 * @property string $Status
 * @property string $StampMode
 * @property string $ProcedureName
 * @property string|null $ParamsJson
 * @property int $TotalRows
 * @property int $MatchedRows
 * @property int $MismatchRows
 * @property int $BankOnlyRows
 * @property int $DepositOnlyRows
 * @property int $OtherRows
 * @property string $BankTotal
 * @property string $MopsTotal
 * @property string $MatchedTotal
 * @property int|null $PreviewMs
 * @property Carbon|null $CommittedAt
 * @property int|null $CommittedBy
 * @property int $CommittedRows
 * @property string $CommittedTotal
 * @property Carbon|null $ReversedAt
 * @property int|null $ReversedBy
 * @property string|null $ReversalReason
 * @property string|null $FailureCode
 * @property string|null $FailureMessage
 * @property string|null $Note
 * @property Carbon|null $CreatedAt
 * @property Carbon|null $UpdatedAt
 * @property int|null $CreatedBy
 * @property-read Collection<int, ReconRunLine> $lines
 */
class ReconRun extends BaseModel
{
    protected $table = 'ReconRun';

    /** Counts are counts; money stays a string — see ReconRunLine. */
    protected $casts = [
        // sqlsrv hands integers back as strings, and BranchId is compared
        // against an int everywhere it is used.
        'BranchId' => 'integer',
        'TotalRows' => 'integer',
        'MatchedRows' => 'integer',
        'MismatchRows' => 'integer',
        'BankOnlyRows' => 'integer',
        'DepositOnlyRows' => 'integer',
        'OtherRows' => 'integer',
        'CommittedRows' => 'integer',
        'PreviewMs' => 'integer',
        'FromDate' => 'date',
        'ToDate' => 'date',
        'CommittedAt' => 'datetime',
        'ReversedAt' => 'datetime',
    ];

    /** @return HasMany<ReconRunLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ReconRunLine::class, 'RunId', 'Id')->orderBy('LineNo');
    }

    /**
     * The arguments this run was given, decoded.
     *
     * Kept as JSON rather than a column per option because the five areas do
     * not take the same arguments and never will — CashBags has a bag-key
     * length, SmartATM has neither. What matters is that the exact arguments
     * are stored, so a figure can be reproduced.
     *
     * @return array<string, scalar|null>
     */
    public function params(): array
    {
        return json_decode($this->ParamsJson ?? '[]', true) ?: [];
    }

    /**
     * How many rows this run would stamp if it were executed.
     *
     * Not the same as MatchedRows on a legacy run and that is the entire
     * point: the live procedure reaches its matched branch whenever the
     * comparison is UNKNOWN, so its "matched" count includes bank lines with
     * no deposit behind them at all.
     */
    public function reconcilable(): int
    {
        return $this->MatchedRows;
    }

    public function isReconciled(): bool
    {
        return $this->Status === 'committed';
    }
}
