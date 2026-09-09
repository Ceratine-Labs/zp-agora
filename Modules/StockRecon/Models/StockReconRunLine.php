<?php

namespace Modules\StockRecon\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One shift of one stock item, as counted and as balanced.
 *
 * @property int $BranchId
 * @property int $Id
 * @property int $RunId
 * @property int $LineNo
 * @property int $AreaNo
 * @property string $StockItemNo
 * @property Carbon $TransactionDate
 * @property int $ShiftNo
 * @property string|null $SellPrice
 * @property string $QtyOpen
 * @property string $QtyIssued
 * @property string $QtyClose
 * @property string $QtyPOS
 * @property string $QtyVar
 * @property string $QtyOpenNew
 * @property string $QtyCloseNew
 * @property string $QtyVarNew
 * @property string $AmendClose
 * @property bool $IsDormant
 * @property int $ActiveSeq
 * @property int $ActiveLen
 * @property string $ChainNetVar
 * @property bool $FlagNetOver
 * @property bool $FlagSoldMoreThanOnHand
 * @property bool $FlagCloseExceedsOnHand
 * @property bool $FlagIssueWentNowhere
 * @property bool $FlagBigAmendment
 * @property bool $FlagPctAmendment
 * @property bool $FlagNegativeClose
 * @property bool $FlagShortChain
 * @property bool $FlagDormantMoved
 * @property bool $FlagChainBroken
 * @property bool $ChainBlocked
 * @property string|null $ExceptionCode
 * @property string $Outcome
 * @property bool $WouldAmend
 * @property bool $Selected
 * @property string $CommitState
 * @property string|null $BlockReason
 * @property string|null $ItemDescription
 * @property string|null $POSCode
 * @property string|null $StockLocation
 * @property string|null $AreaDescription
 * @property string|null $EmployeeCodes
 * @property string|null $EmployeeNames
 * @property int $EmployeeCount
 * @property-read StockReconRun $run
 */
class StockReconRunLine extends BaseModel
{
    protected $table = 'StockReconRunLine';

    /**
     * Integers come back from sqlsrv as strings; quantities deliberately do not.
     *
     * A DECIMAL(18,3) cast to float loses exactness, and these figures are
     * compared against what the till said and what the shelf held.
     */
    protected $casts = [
        'BranchId' => 'integer',
        'RunId' => 'integer',
        'LineNo' => 'integer',
        'AreaNo' => 'integer',
        'ShiftNo' => 'integer',
        'ActiveSeq' => 'integer',
        'ActiveLen' => 'integer',
        'EmployeeCount' => 'integer',
        'TransactionDate' => 'date',
        'IsDormant' => 'boolean',
        'FlagNetOver' => 'boolean',
        'FlagSoldMoreThanOnHand' => 'boolean',
        'FlagCloseExceedsOnHand' => 'boolean',
        'FlagIssueWentNowhere' => 'boolean',
        'FlagBigAmendment' => 'boolean',
        'FlagPctAmendment' => 'boolean',
        'FlagNegativeClose' => 'boolean',
        'FlagShortChain' => 'boolean',
        'FlagDormantMoved' => 'boolean',
        'FlagChainBroken' => 'boolean',
        'ChainBlocked' => 'boolean',
        'WouldAmend' => 'boolean',
        'Selected' => 'boolean',
    ];

    /** @return BelongsTo<StockReconRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(StockReconRun::class, 'RunId', 'Id');
    }

    /**
     * Which of the outcomes this is, as a short key the view styles on.
     *
     * The procedure spells the outcome out in full because the customer reads
     * that column in SSMS; the screen needs something shorter and stable, and
     * deriving it here keeps the two from drifting into different vocabularies.
     */
    public function outcomeKey(): string
    {
        return match (true) {
            $this->IsDormant => 'dormant',
            $this->ExceptionCode !== null && str_starts_with($this->ExceptionCode, 'A') => 'unrecorded',
            $this->ChainBlocked => 'blocked',
            $this->WouldAmend && (float) $this->QtyVarNew < -0.005 => 'short',
            $this->WouldAmend => 'balanced',
            (float) $this->QtyVarNew < -0.005 => 'short',
            default => 'clean',
        };
    }

    /** The chip tone for that outcome. */
    public function tone(): string
    {
        return match ($this->outcomeKey()) {
            'balanced' => 'good',
            'short' => 'warn',
            'unrecorded' => 'crit',
            'blocked' => 'serious',
            default => 'neutral',
        };
    }

    /**
     * Does the commit have anything to write for this row?
     *
     * Two columns, not one. A row's closing can be pinned — the last active
     * shift always is — while its OPENING still has to follow the amended
     * closing before it. The procedure decides this and stores it; this is the
     * readable name for what it decided.
     */
    public function moves(): bool
    {
        return $this->WouldAmend;
    }

    public function isCommitted(): bool
    {
        return $this->CommitState === 'committed';
    }

    /** The amendment, as a signed movement of the closing count. */
    public function amendment(): float
    {
        return (float) $this->AmendClose;
    }

    /**
     * What to call this item on screen.
     *
     * The label the RUN recorded, then the bare number. A run made before
     * v1__14a stored none, and showing an empty cell on a screen that used to
     * show something is worse than showing the id it always had.
     */
    public function itemLabel(): string
    {
        return $this->ItemDescription ?: $this->StockItemNo;
    }

    /** The counting area, named where the run recorded one. */
    public function areaLabel(): string
    {
        return $this->AreaDescription ?: 'area '.$this->AreaNo;
    }

    /**
     * More than one person was signed on to this area for this shift.
     *
     * 90 of branch 18's 1,138 shifts, up to three people. It matters because a
     * short on a shared shift cannot be attributed to either of them, and a
     * screen that shows two names without saying they SHARED it invites
     * exactly that attribution.
     */
    public function sharedShift(): bool
    {
        return $this->EmployeeCount > 1;
    }

    /**
     * The opening moved but the closing did not.
     *
     * Worth naming on the row, because otherwise it reads as a row that is
     * being written for no reason — and somebody will eventually "fix" it by
     * leaving it out, which is what breaks the chain in the source.
     */
    public function openingOnly(): bool
    {
        return $this->WouldAmend && abs($this->amendment()) <= 0.005;
    }
}
