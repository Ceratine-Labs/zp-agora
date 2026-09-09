<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stock recon — name the item, and put the shift's employee on the line (slot 14a).
 *
 * A lettered follow-on: v1__14 has run against the customer's instance and the
 * tables now hold real runs, so the create is closed.
 *
 * TWO THINGS RYAN ASKED FOR ON THE LIVE SCREEN, 9 September 2026, and the
 * second one turns out to be bigger than it sounds.
 *
 * 1. THE ITEM COLUMN SHOWED A NUMBER. `10`, over `area 1`. The proposals table
 *    is the one screen in this module that is NOT powered by a procedure — it
 *    reads the recorded run through Eloquent, because its tick boxes decide
 *    what a commit writes — so there was no join to hang a description on.
 *
 * 2. THE ESTATE RECORDS WHO WAS ON THE SHIFT, and docs/stock-recon.md said it
 *    did not. `dbo.STK_StockReconEmployees` is keyed on exactly the grain a
 *    recon line is — (branch, date, shift, area) — and on branch 18 across
 *    August and early September it covers ALL 1,138 shifts, with every one of
 *    its 45 distinct codes resolving against BRN_Employee. That closes the
 *    second of the two things the method note listed as unknowable:
 *
 *      "The cashier is not in the recon line. Shift number is a poor proxy for
 *       a person. Joining the cashier on duty to each shift is what turns D1
 *       from an item-level observation into an accountability record."
 *
 *    It is not a proxy any more. D1 — persistent short, the class that carries
 *    a charge — can now name the person it is about.
 *
 * WHY THE LABELS ARE STORED ON THE LINE RATHER THAN JOINED AT READ TIME. The
 * same reason SellPrice already is: a run is a RECORD of what was true when it
 * was made. An item gets renamed, an employee leaves, an area is renumbered —
 * and a run read six months later has to still say what the operator was
 * looking at when they pressed the button. It also means the extract carries
 * them, which matters more since live writes went on: in journal mode that
 * extract IS the worklist, and a worklist of item numbers is not one.
 *
 * NULL ON EVERY EXISTING ROW, deliberately. Runs Ryan made before this are not
 * back-filled — they did not have this information and pretending otherwise
 * would be inventing it. Every reader falls back to the item number.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');
        $erp = config('agora.source_databases.erp');

        DB::statement("
            ALTER TABLE [{$schema}].[StockReconRunLine]
                ADD [ItemDescription] NVARCHAR(100) NULL,
                    [POSCode]         NVARCHAR(100) NULL,
                    [StockLocation]   NVARCHAR(20)  NULL,
                    [AreaDescription] NVARCHAR(100) NULL,
                    /*
                     * The employee is a LIST, not a name, and that is the
                     * finding rather than a defensive nullable.
                     *
                     * 90 of branch 18's 1,138 shifts have more than one person
                     * signed on to the area, up to three. A short on a shift
                     * two people worked cannot be attributed to either of them,
                     * so the count travels beside the names and the screen says
                     * so. Collapsing it to a single name would manufacture an
                     * accountability the data does not support.
                     */
                    [EmployeeCodes]   NVARCHAR(200) NULL,
                    [EmployeeNames]   NVARCHAR(400) NULL,
                    [EmployeeCount]   INT NOT NULL CONSTRAINT [DF_StockReconRunLine_EmployeeCount] DEFAULT 0;
        ");

        /*
         * Who was on a shift, read-only, by three-part name.
         *
         * SSBranchId becomes BranchId like every other agora.vw_*, and every
         * other column keeps its legacy name. EmployeeCode is NVARCHAR(22) on
         * the source and is padded in places, so it is trimmed here — the join
         * to BRN_Employee is on that value and a trailing space is a silent
         * miss.
         */
        DB::statement("
            CREATE VIEW [{$schema}].[vw_StockReconEmployee] AS
                SELECT e.SSBranchId               AS BranchId,
                       e.TransactionDate,
                       e.ShiftNo,
                       e.AreaNo,
                       LTRIM(RTRIM(e.EmployeeCode)) AS EmployeeCode
                FROM [{$erp}].dbo.STK_StockReconEmployees e;
        ");
    }

    /** Local sandbox only. Live and staging are forward-only — see CLAUDE.md. */
    public function down(): void
    {
        $schema = config('agora.schema');

        DB::statement("DROP VIEW IF EXISTS [{$schema}].[vw_StockReconEmployee];");
        DB::statement("
            ALTER TABLE [{$schema}].[StockReconRunLine]
                DROP CONSTRAINT [DF_StockReconRunLine_EmployeeCount];
        ");
        DB::statement("
            ALTER TABLE [{$schema}].[StockReconRunLine]
                DROP COLUMN [ItemDescription], [POSCode], [StockLocation],
                            [AreaDescription], [EmployeeCodes], [EmployeeNames], [EmployeeCount];
        ");
    }
};
