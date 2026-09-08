<?php

namespace Modules\Recon\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One proposal from a preview — matched or not.
 *
 * @property int $BranchId
 * @property int $Id
 * @property int $RunId
 * @property int $LineNo
 * @property string $ReconArea
 * @property string|null $Population
 * @property string|null $KeyRef
 * @property string|null $KeyRef2
 * @property Carbon|null $BankDate
 * @property Carbon|null $WindowFrom
 * @property Carbon|null $WindowTo
 * @property string|null $BankNarrative
 * @property string|null $DeviceRefs
 * @property int|null $BankLineId
 * @property int $BankLines
 * @property string $BankTotal
 * @property string|null $BankCC
 * @property string|null $BankDD
 * @property int $MopsTxns
 * @property string $MopsTotal
 * @property string $DiffAmount
 * @property string $Outcome
 * @property bool $WouldReconcile
 * @property int|null $UsedProcessOrder
 * @property int|null $UsedBankStart
 * @property int|null $UsedBankLen
 * @property int|null $RulesInGroup
 * @property bool $Selected
 * @property string $CommitState
 * @property int|null $ReconBatchNo
 * @property string|null $BlockReason
 * @property int|null $NearRefLineId
 * @property string|null $NearRefNote
 * @property string|null $MopsKeyRef
 * @property int|null $MopsSourceId the deposit's own id — only CashBags has one
 * @property int|null $PairedFromLineId
 * @property-read ReconRun $run
 */
class ReconRunLine extends BaseModel
{
    protected $table = 'ReconRunLine';

    /**
     * Integers come back from sqlsrv as strings; money deliberately does not.
     *
     * A DECIMAL(18,2) cast to float loses the exactness the column type was
     * chosen for, and every figure here is compared against what the till
     * said. Format::r() takes the string.
     */
    protected $casts = [
        // sqlsrv hands integers back as strings, and BranchId is compared
        // against an int everywhere it is used.
        'BranchId' => 'integer',
        'BankLines' => 'integer',
        'MopsTxns' => 'integer',
        'LineNo' => 'integer',
        'RunId' => 'integer',
        'UsedProcessOrder' => 'integer',
        'UsedBankStart' => 'integer',
        'UsedBankLen' => 'integer',
        'RulesInGroup' => 'integer',
        'BankLineId' => 'integer',
        'MopsSourceId' => 'integer',
        'NearRefLineId' => 'integer',
        'PairedFromLineId' => 'integer',
        'ReconBatchNo' => 'integer',
        'BankDate' => 'date',
        'WindowFrom' => 'datetime',
        'WindowTo' => 'datetime',
        'WouldReconcile' => 'boolean',
        'Selected' => 'boolean',
    ];

    /** @return BelongsTo<ReconRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ReconRun::class, 'RunId', 'Id');
    }

    /**
     * Which of the four outcomes this is, as a short key the view can style
     * on without matching English prose.
     *
     * The procedures spell the outcome out in full because the customer reads
     * that column in SSMS. The screen needs something shorter and stable, and
     * deriving it here keeps the two from drifting into different vocabularies.
     */
    public function outcomeKey(): string
    {
        return match (true) {
            $this->WouldReconcile => 'matched',
            str_starts_with($this->Outcome, 'Deposit only') => 'deposit-only',
            str_starts_with($this->Outcome, 'Bank only') => 'bank-only',
            str_starts_with($this->Outcome, 'Standalone') => 'standalone',
            str_starts_with($this->Outcome, 'Ambiguous') => 'ambiguous',
            str_starts_with($this->Outcome, 'Bank line') => 'unparseable',
            str_starts_with($this->Outcome, 'Amount mismatch') => 'mismatch',
            default => 'mismatch',
        };
    }

    /** The chip tone for that outcome. */
    public function tone(): string
    {
        return match ($this->outcomeKey()) {
            'matched' => 'good',
            'mismatch' => 'warn',
            'bank-only' => 'serious',
            'ambiguous', 'unparseable' => 'crit',
            default => 'neutral',
        };
    }

    /**
     * This row and another in the same run are almost certainly the same
     * reconciliation, split in two by an extraction that is a character out.
     *
     * Worth more than it looks: without it, one reconciliation is reported as
     * two unrelated findings at opposite ends of the screen, and nothing on
     * either says they belong together.
     */
    public function hasNearReference(): bool
    {
        return $this->NearRefLineId !== null;
    }

    /**
     * The reference the DEPOSIT side is under, where that differs from the
     * bank's — `697440` against the bank's `69744`.
     *
     * Null on an ordinary row, because the two sides agreed and there is only
     * one reference to show.
     */
    public function pairedReference(): ?string
    {
        return $this->MopsKeyRef;
    }

    /**
     * More than one criteria rule contributed to this row.
     *
     * Worth a second look before anything is stamped: it means the bank lines
     * that were added together did not all resolve to the same extraction
     * rule, so the reference they were grouped under may not mean the same
     * thing on each of them.
     */
    /**
     * Has this line actually been stamped?
     *
     * The line's own state, not the run's: a committed run can carry lines it
     * skipped, and those still have to drill live because nothing was written
     * for them.
     */
    public function isCommitted(): bool
    {
        return $this->CommitState === 'committed';
    }

    public function mixedRules(): bool
    {
        return ($this->RulesInGroup ?? 1) > 1;
    }
}
