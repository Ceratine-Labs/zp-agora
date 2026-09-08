<?php

namespace Modules\StockRecon\Models;

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
 * @property int|null $AreaNo
 * @property Carbon $FromDate
 * @property Carbon $ToDate
 * @property string $Status
 * @property string $StampMode
 * @property string $ProcedureName
 * @property string|null $ParamsJson
 * @property int $TotalRows
 * @property int $DormantRows
 * @property int $ChainCount
 * @property int $BalanceableChains
 * @property int $BlockedChains
 * @property int $AmendedRows
 * @property int $OverRowsBefore
 * @property int $OverRowsAfter
 * @property int $ShortRowsBefore
 * @property int $ShortRowsAfter
 * @property string $OverUnitsBefore
 * @property string $OverUnitsAfter
 * @property string $ShortUnitsBefore
 * @property string $ShortUnitsAfter
 * @property string $UnitsAmended
 * @property string $NetOverUnits
 * @property string $NetOverValue
 * @property string $ShortValueAfter
 * @property int|null $PreviewMs
 * @property Carbon|null $CommittedAt
 * @property int|null $CommittedBy
 * @property int $CommittedRows
 * @property string $CommittedUnits
 * @property Carbon|null $ReversedAt
 * @property int|null $ReversedBy
 * @property string|null $ReversalReason
 * @property string|null $FailureCode
 * @property string|null $FailureMessage
 * @property string|null $Note
 * @property Carbon|null $CreatedAt
 * @property Carbon|null $UpdatedAt
 * @property int|null $CreatedBy
 * @property-read Collection<int, StockReconRunLine> $lines
 */
class StockReconRun extends BaseModel
{
    protected $table = 'StockReconRun';

    /**
     * Counts are counts; quantities and money stay strings.
     *
     * A DECIMAL(18,3) cast to float loses the exactness the column type was
     * chosen for, and every figure here is compared against what a shift
     * counted. Format::n() and Format::r() take the string.
     */
    protected $casts = [
        // sqlsrv hands integers back as strings, and BranchId is compared
        // against an int everywhere it is used.
        'BranchId' => 'integer',
        'AreaNo' => 'integer',
        'TotalRows' => 'integer',
        'DormantRows' => 'integer',
        'ChainCount' => 'integer',
        'BalanceableChains' => 'integer',
        'BlockedChains' => 'integer',
        'AmendedRows' => 'integer',
        'OverRowsBefore' => 'integer',
        'OverRowsAfter' => 'integer',
        'ShortRowsBefore' => 'integer',
        'ShortRowsAfter' => 'integer',
        'CommittedRows' => 'integer',
        'PreviewMs' => 'integer',
        'FromDate' => 'date',
        'ToDate' => 'date',
        'CommittedAt' => 'datetime',
        'ReversedAt' => 'datetime',
    ];

    /** @return HasMany<StockReconRunLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockReconRunLine::class, 'RunId', 'Id')->orderBy('LineNo');
    }

    /**
     * The arguments this run was given, decoded.
     *
     * Kept as JSON rather than a column per cap, because the caps will change
     * as the customer decides what is plausible and a run made under the old
     * ones still has to be reproducible.
     *
     * @return array<string, scalar|null>
     */
    public function params(): array
    {
        return json_decode($this->ParamsJson ?? '[]', true) ?: [];
    }

    /**
     * Was the preview run against the original counts?
     *
     * Read back rather than defaulted, because it decides which pair of columns
     * the commit re-checks against. Defaulting it at the call site would make a
     * commit compare a preview of the _Original counts against the live ones
     * and skip every row as "changed since the preview".
     */
    public function usedOriginalCounts(): bool
    {
        return (bool) ($this->params()['UseOriginalCounts'] ?? 1);
    }

    public function isCommitted(): bool
    {
        return $this->Status === 'committed';
    }

    /** A run that can still be pressed: previewed, or reversed and back on the table. */
    public function isOpen(): bool
    {
        return in_array($this->Status, ['previewed', 'reversed'], true);
    }

    /**
     * The window is the anchor at both ends, so the amount of loss it can
     * possibly report is fixed the moment it is chosen. Worth saying on screen
     * beside the dates rather than only in the method note.
     */
    public function days(): int
    {
        return (int) $this->FromDate->diffInDays($this->ToDate) + 1;
    }

    /**
     * Where this person left off — their newest run that is still open work.
     *
     * A committed run is history and is not offered as somewhere to resume; it
     * is still on the Runs tab, where history belongs.
     *
     * Null user, null run: an unauthenticated caller cannot have left off
     * anywhere, and "everyone's newest open run" is not a useful answer to
     * offer somebody as their own.
     */
    public static function openFor(?int $userId): ?self
    {
        if ($userId === null) {
            return null;
        }

        return static::query()
            ->where('CreatedBy', $userId)
            ->whereIn('Status', ['previewed', 'reversed'])
            ->orderByDesc('CreatedAt')
            ->first();
    }
}
