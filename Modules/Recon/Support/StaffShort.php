<?php

namespace Modules\Recon\Support;

/**
 * What a line of a cash-bag split says about its staff short, in words.
 *
 * agora.ReconAllocation.ShortStatus is a code (v1__13k). Every place a person
 * reads it — the run page, the trace, the deposit drill, the extracts — says
 * the same words for the same code, from here, so the run page cannot call a
 * short "raised" that the trace calls "due".
 *
 * 'pending' is said honestly: a short is due and NOTHING HAS RAISED IT. Since
 * T040 a live cash-bag match raises every such short in the same press, through
 * the Cash module's usp_Cash_RaiseStaffShort, so a live line is 'raised' (with
 * its StaffShortsNo) and 'pending' is only ever seen on a line written before
 * that, or briefly inside the match's own transaction. 'cancelled' is set by
 * usp_Recon_Reverse and by nothing else: the reversal declines a short still
 * waiting for approval itself, and refuses one already approved until it has
 * been withdrawn in Cash (usp_Cash_WithdrawStaffShort, PM decision 01a0e5b5).
 * A short an approver declines WITHOUT a reversal stays 'raised' here — it was
 * raised, and its decision lives with the short in Cash. The words never
 * promise more than the table records.
 */
final class StaffShort
{
    /**
     * The words, and the chip tone they are shown in.
     *
     * @return array{0: string, 1: string} [tone, words]
     */
    public static function describe(?string $status, mixed $share = null, mixed $staffShortsNo = null): array
    {
        return match ($status) {
            'pending' => ['warn', 'short due, not yet raised'],
            'raised' => ['crit', 'short raised'.($staffShortsNo !== null && $staffShortsNo !== '' ? ' #'.$staffShortsNo : '')],
            'journal' => ['neutral', 'journal — never raised'],
            'cancelled' => ['neutral', 'cancelled by the reversal'],
            'no_cashier' => ['warn', 'no cashier — none raised'],
            'none' => ['good', is_numeric($share) && (float) $share > 0 ? 'over — none due' : 'none due'],
            default => ['neutral', '—'],
        };
    }

    public static function words(?string $status, mixed $share = null, mixed $staffShortsNo = null): ?string
    {
        return $status === null ? null : self::describe($status, $share, $staffShortsNo)[1];
    }

    public static function tone(?string $status, mixed $share = null): string
    {
        return self::describe($status, $share)[0];
    }
}
