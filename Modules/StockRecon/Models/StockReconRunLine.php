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
 * @property string|null $UOMCode
 * @property-read StockReconRun $run
 */
class StockReconRunLine extends BaseModel
{
    /**
     * READS COME FROM THE VIEW, and that is the fix for what Ryan saw on
     * 9 September 2026: a proposals row reading `10` over `area 1` while the
     * exceptions grid, three clicks away, named the same product.
     *
     * v1__14a stores the labels on the line at preview time, which is right —
     * a run is a record of what was true when it was made. What was wrong was
     * leaving every reader to fall back on its own: the procedures did it and
     * this model did not, so a run made before 14a had a label everywhere
     * except here. agora.vw_StockReconRunLine (v1__14b) is the table with
     * `ISNULL(stored, estate)` over the six label columns and nothing else —
     * no filtering, no aggregation, same grain, same ids.
     *
     * WRITES STILL GO TO THE TABLE. The view joins, so SQL Server cannot
     * update through it. There is exactly one Eloquent writer —
     * StockReconService::select(), the operator's tick boxes — and it names
     * agora.StockReconRunLine directly and says so. Everything else that
     * writes a line is a procedure.
     */
    protected $table = 'vw_StockReconRunLine';

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

    /**
     * The outcome as a LABEL, with the explanation left for the hover.
     *
     * The procedure writes an outcome as "Label: explanation", because the
     * customer reads that column in SSMS and a bare label there would make them
     * go and read the procedure to find out what it meant. On screen the same
     * sentence is a status pill, and the longest of the fifteen is 67
     * characters — "Implausible amendment: the correction is too large to be a
     * miscount". A pill cannot wrap without ceasing to look like a pill, so
     * that one column was claiming 327px of a 1546px table and pinning the item
     * name at its floor.
     *
     * So the pill carries the label and `title` carries the whole sentence.
     * Ryan asked for exactly this on the live screen, 9 Sep 2026: "maybe
     * shorten the description of the outcome, on hover show the full value".
     *
     * SPLIT ON THE COLON RATHER THAN TRUNCATED. An ellipsis at 22 characters
     * gives "Opening follows the am…", which is worse than the number it
     * replaced; the procedure has already written a good short form and it is
     * the part before the colon. An outcome with no colon is already short —
     * "Balanced to zero", "No change needed" — and is returned whole.
     */
    public function outcomeLabel(): string
    {
        $at = strpos($this->Outcome, ':');

        return $at === false ? $this->Outcome : rtrim(substr($this->Outcome, 0, $at));
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
     * The view has already tried the stored label and then the stock master,
     * so this is the last resort only: an item the master has no row for at
     * all. Showing the number then is right — showing a blank cell would hide
     * that the item is missing from the master, which is itself a finding.
     */
    public function itemLabel(): string
    {
        return $this->ItemDescription ?: $this->StockItemNo;
    }

    /** The counting area — stored, then the area master, then the bare number. */
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
