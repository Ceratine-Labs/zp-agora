<?php

use App\Support\Database\ProcedureMigration;

/**
 * Stock recon — a short before an over balances with it; the over is not
 * captured as a missing issue (slot 14pzz).
 *
 * Two pieces of customer feedback, 12 Nov 2026, one rule.
 *
 * Pongola Convenience Centre, REWORK LOSE (item 376, Tobacco, 4–10 Nov): "the
 * 60 should not have been inserted as a issue, had to be balance with the
 * short". The 6 Nov night closed 12 where 74 was due (short 62), the 7 Nov
 * day did not trade and signed off 12 / 12, and the 7 Nov night opened 72.
 * The opening-break pair rule of 5 Nov read the row immediately before the
 * opening — the dormant one — so it missed the pair and the 60 was proposed
 * as a missing issue beside a short of 62.
 *
 * Engen Manguzi, ENGEN DIESELUBE 500ML (item 31, Lubes, multiple 10): "the -10
 * amendment is wrong". No break: the 3 Nov night counted 16 where 26 was due,
 * the 4 Nov night counted 23 from an opening of 14. The over was exactly one
 * issue multiple, so the pre-balancing issue stage captured it as a missing
 * issue and left the short of 10 standing.
 *
 * usp_StockRecon_PreviewBalancing:
 *   - the opening-break pair is the opening and the last shift that TRADED
 *     before it; the dormant shifts between join the pair's stretch, and are
 *     not judged by the share cap against the miscount they carried;
 *   - an exact over gives up every whole issue multiple the chain is already
 *     short of when it arrives, and captures only the rest.
 * usp_StockRecon_Commit:
 *   - STALE_OPENING_ISSUE reads "preceding" the same way, and only while the
 *     preceding short still stands in the run;
 *   - STALE_SHORT_ISSUE refuses a saved run that still captures an issue an
 *     earlier short on a chain with no break needs.
 */
return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return array_map(fn (string $name): string => $this->path().'/'.$name.'.sql', [
            'usp_StockRecon_PreviewBalancing', 'usp_StockRecon_Commit',
        ]);
    }
};
