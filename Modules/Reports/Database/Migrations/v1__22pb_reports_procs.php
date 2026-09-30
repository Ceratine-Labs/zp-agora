<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Reports' stored procedures (slot 22pb) — for T036, 27 Sep 2026.
 *
 * usp_Reports_GridUnallocatedZReads and usp_Reports_GridMyQueue read
 * agora.vw_ZRead, which knows nothing of Agora's own Z-read allocations: a
 * Z-read saved on Cash -> ZREAD Allocation stayed in the Unallocated Z-reads
 * queue and in My queue's count. Both now read agora.vw_CashZread (Cash, v1__12)
 * with IsAllocated = 0 AND IsPartlyAllocated = 0 — sp_MissingZREAD's
 * all-three-empty rule with Agora's allocations taken out — and the queue
 * returns Location, which is part of a Z-read's key.
 *
 * It depends on v1__12_cash_tables having run, which the slot order guarantees
 * (12 before 22). Every other procedure in the directory is rewritten to itself.
 */
return new class extends ProcedureMigration {};
