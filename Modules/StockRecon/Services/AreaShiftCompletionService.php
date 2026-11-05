<?php

namespace Modules\StockRecon\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AreaShiftCompletionService
{
    public function history(int $branch, int $area)
    {
        return DB::table('agora.StockReconAreaShiftCompletion')->where('BranchId', $branch)
            ->where('AreaNo', $area)->orderByDesc('Id')->limit(100)->get();
    }

    public function record(int $branch, int $area, array $input, int $user): void
    {
        $shift = (int) $input['shift'];
        $column = [1 => 'DayShift', 2 => 'AfternoonShift', 3 => 'NightShift'][$shift];
        $enabled = DB::table('agora.vw_StockArea')->where('BranchId', $branch)->where('AreaNo', $area)->value($column);
        if (! $enabled) {
            throw ValidationException::withMessages(['shift' => 'This shift is not configured for the counting area.']);
        }
        if (DB::table('agora.StockReconAreaShiftCompletion')->where('BranchId', $branch)->where('AreaNo', $area)
            ->where('TransactionDate', $input['date'])->where('ShiftNo', $shift)->whereNull('WithdrawnAt')->exists()) {
            throw ValidationException::withMessages(['shift' => 'This area shift is already recorded as complete.']);
        }
        DB::table('agora.StockReconAreaShiftCompletion')->insert([
            'BranchId' => $branch, 'AreaNo' => $area, 'TransactionDate' => $input['date'],
            'ShiftNo' => $shift, 'CreatedAt' => now('UTC'), 'CreatedBy' => $user,
        ]);
    }

    public function withdraw(int $branch, int $area, int $id, int $user): void
    {
        $updated = DB::table('agora.StockReconAreaShiftCompletion')->where('BranchId', $branch)->where('AreaNo', $area)
            ->where('Id', $id)->whereNull('WithdrawnAt')->update(['WithdrawnAt' => now('UTC'), 'WithdrawnBy' => $user]);
        abort_unless($updated === 1, 404);
    }
}
