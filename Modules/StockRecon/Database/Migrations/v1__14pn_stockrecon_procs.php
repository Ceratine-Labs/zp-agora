<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys StockRecon's stored procedures (slot 14pn), 7 Oct 2026: an
 * unrecorded issue no longer holds a chain back (ZRP: "don't hold chains
 * back"; Ryan: "it needs to balance anyways"). U1–U4 and S1 are still raised
 * and reported, the chain balances around them, and a stretch that ends over
 * is balanced by the mirror of the method — the reverse running minimum — so
 * its shorts go to nil and the over that is left stays on the shifts that
 * carry it, as the missing issue to capture. The rest redeploy unchanged.
 */
return new class extends ProcedureMigration {};
