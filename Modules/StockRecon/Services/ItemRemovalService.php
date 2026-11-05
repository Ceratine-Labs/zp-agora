<?php

namespace Modules\StockRecon\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ItemRemovalService
{
    public function history(int $branch, int $area)
    {
        return DB::table('agora.StockReconItemRemoval')->where('BranchId', $branch)->where('AreaNo', $area)
            ->orderByDesc('Id')->get();
    }

    public function record(int $branch, int $area, array $input, int $user): void
    {
        $exists = DB::table('agora.vw_StockReconLine')->where('BranchId', $branch)->where('AreaNo', $area)
            ->where('StockItemNo', $input['item'])->whereDate('TransactionDate', $input['date'])
            ->where('ShiftNo', $input['shift'])->exists();
        if (! $exists) {
            throw ValidationException::withMessages(['item' => 'The last count must exist for this item, area, date and shift.']);
        }
        if (DB::table('agora.StockReconItemRemoval')->where('BranchId', $branch)->where('AreaNo', $area)
            ->where('StockItemNo', $input['item'])->where('LastDate', $input['date'])->where('LastShift', $input['shift'])
            ->whereNull('WithdrawnAt')->exists()) {
            throw ValidationException::withMessages(['item' => 'This item removal is already recorded.']);
        }
        DB::table('agora.StockReconItemRemoval')->insert([
            'BranchId' => $branch, 'AreaNo' => $area, 'StockItemNo' => $input['item'],
            'LastDate' => $input['date'], 'LastShift' => $input['shift'], 'CreatedAt' => now('UTC'), 'CreatedBy' => $user,
        ]);
    }

    public function withdraw(int $branch, int $area, int $id, int $user): void
    {
        $updated = DB::table('agora.StockReconItemRemoval')->where('BranchId', $branch)->where('AreaNo', $area)
            ->where('Id', $id)->whereNull('WithdrawnAt')->update(['WithdrawnAt' => now('UTC'), 'WithdrawnBy' => $user]);
        abort_unless($updated === 1, 404);
    }
}
