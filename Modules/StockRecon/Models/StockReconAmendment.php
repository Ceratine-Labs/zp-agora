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
 * @property string|null $PriorQtyIssued
 * @property string|null $NewQtyIssued
 * @property string|null $PriorQtyPOS
 * @property string|null $NewQtyPOS
 * @property string $StampMode
 * @property string $State
 * @property Carbon|null $ReversedAt
 * @property int|null $ReversedBy
 * @property string|null $ReversalReason the reason a chain reversal gave (v1__14q); a run-level one keeps its reason on the run
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

    /**
     * What reversing this amendment writes to PumpIT, per column: what the
     * commit left there, and the prior that goes back. The columns the
     * reversal procedures write — the counts always, the issue and the POS
     * only where the commit wrote them — and only those that differ.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public function reversalWrites(): array
    {
        $pairs = [
            'QtyOpen' => [$this->NewQtyOpen, $this->PriorQtyOpen],
            'QtyIssued' => $this->NewQtyIssued !== null ? [$this->NewQtyIssued, $this->PriorQtyIssued] : null,
            'QtyClose' => [$this->NewQtyClose, $this->PriorQtyClose],
            'QtyComputer' => $this->NewQtyPOS !== null ? [$this->NewQtyPOS, $this->PriorQtyPOS] : null,
        ];

        return array_map(
            fn (array $p) => [(string) $p[0], (string) $p[1]],
            array_filter($pairs, fn (?array $p) => $p !== null && abs((float) $p[0] - (float) $p[1]) > 0.0005),
        );
    }

    /** Did this one reach the customer's estate, or only Agora's ledger? */
    public function reachedPumpIt(): bool
    {
        return $this->StampMode === 'live';
    }
}
