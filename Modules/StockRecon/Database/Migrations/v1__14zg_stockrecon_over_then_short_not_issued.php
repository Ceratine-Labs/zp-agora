<?php

use App\Support\Database\ProcedureMigration;

/**
 * Stock recon — an exact over that a later short takes back balances with it;
 * it is not captured as a missing issue (slot 14zg).
 *
 * OK Ngwelezane, MARLBORO BEYOND RED 20'S (branch 3, Tobacco, item 297,
 * multiple 10), 2–8 Nov 2026 (customer, 16 Nov: "The actual short for the week
 * shows -7 units, but the Amended shows -17"). The 3 Nov day counted 10 where
 * nothing was due, over 10; the night counted 0 with nothing sold, short 10 —
 * a closing counted high. The pre-balancing issue stage captured the over as a
 * missing issue of 10, the short was paired with the 5 Nov day's over instead,
 * and the 5 Nov night's short of 10 stayed against staff: the week -17.
 *
 * The mirror of 14pzz (an over an EARLIER short needs), and what Ryan ruled on
 * 12 Nov: "balance both ways".
 *
 * usp_StockRecon_PreviewBalancing:
 *   - an exact over also gives up the whole multiples of the deepest the chain
 *     falls after it, once the earlier short has taken its share, and
 *     captures only the rest.
 * usp_StockRecon_Commit:
 *   - STALE_LATER_SHORT_ISSUE refuses a saved run that still captures the
 *     whole of such an over as an issue.
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
