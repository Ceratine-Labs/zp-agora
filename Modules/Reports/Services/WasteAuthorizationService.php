<?php

namespace Modules\Reports\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Append-only decisions against the exact captured quantity and source time. */
final class WasteAuthorizationService
{
    /** @param array<string, mixed> $data */
    public function decide(array $data, int $userId): void
    {
        $db = DB::connection(config('agora.connections.app'));
        $schema = config('agora.schema');
        $source = $db->table($schema.'.vw_StockWasteLine')
            ->where('BranchId', $data['branch_id'])->whereDate('TransactionDate', $data['date'])
            ->where('ShiftNo', $data['shift'])->where('AreaNo', $data['area'])
            ->where('StockItemNo', $data['item'])->first();
        $sourceTime = $source?->CreateDateTime ? Carbon::parse($source->CreateDateTime)->format('Y-m-d H:i:s.v') : null;
        $sentTime = ! empty($data['captured_at']) ? Carbon::parse($data['captured_at'])->format('Y-m-d H:i:s.v') : null;
        if ($source === null || $sourceTime !== $sentTime || abs((float) $source->QtyGoodWaste - (float) $data['captured_good']) > 0.00005) {
            throw ValidationException::withMessages(['waste' => 'The captured waste changed or no longer exists. Reload before deciding.']);
        }
        $approved = $data['decision'] === 'authorize' ? (float) $data['authorized_good'] : 0.0;
        if (($data['decision'] === 'authorize' && $approved <= 0) || $approved > (float) $source->QtyGoodWaste + 0.00005) {
            throw ValidationException::withMessages(['authorized_good' => 'Authorize a positive quantity no greater than the captured good waste.']);
        }
        $db->table($schema.'.StockWasteAuthorization')->insert([
            'BranchId' => (int) $data['branch_id'], 'TransactionDate' => $data['date'],
            'ShiftNo' => (int) $data['shift'], 'AreaNo' => (int) $data['area'], 'StockItemNo' => $data['item'],
            'CapturedGood' => $source->QtyGoodWaste, 'CapturedAt' => $source->CreateDateTime,
            'AuthorizedGood' => $approved, 'IsAuthorized' => $data['decision'] === 'authorize',
            'CreatedAt' => now('UTC'), 'CreatedBy' => $userId,
        ]);
        Cache::store('file')->forever('operations.wall.waste-revision', (string) Str::uuid());
    }
}
