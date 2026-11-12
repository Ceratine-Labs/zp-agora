<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Partners — the ledger of what a supplier or customer edit wrote to PumpIT
 * (T024, slot 04a).
 *
 * A NAMED WRITE (CLAUDE.md rule 1; Ryan's standing rule of 12 Nov 2026,
 * "agora is replacing pumpit, always write back to pumpit with confirmation",
 * PM decision 01a1cecf-8e08). Agora's partner edits write back into the
 * customer's tables, because the till, the cashups and PumpIT's own screens
 * read them. Exactly these, and nothing else:
 *
 *   BRN_Suppliers  UPDATE SupplierName (every site carrying the code),
 *                  ContactPerson, Department, isActive, IsCash, IsStock
 *                  (one site's row)                       usp_Partners_SaveSupplier
 *                  INSERT one code at every site carrying the list
 *                                                         usp_Partners_AddSupplier
 *   BRN_Customers  UPDATE CustomerName, CustomerContact, Department, JobTitle,
 *                  TelNoA, TelNoB (one row)               usp_Partners_SaveCustomer
 *                  INSERT one row                         usp_Partners_AddCustomer
 *
 * and the reversal of an edit, usp_Partners_ReverseWrite. Nothing is deleted:
 * an added partner is not reversible (that would be a DELETE, which needs its
 * own go-ahead) — it is switched off instead.
 *
 *   agora.PartnersWrite  one row per PumpIT value a press changed, or per row
 *                        it inserted: the site, the code, the column, its
 *                        PRIOR value exactly as stored and the NEW one. Rows of
 *                        one press share a WriteId — a supplier's name change
 *                        spans every site carrying the code, so a press is
 *                        found by WriteId, not by site. The reversal undoes a
 *                        press as a whole and refuses where PumpIT has moved
 *                        since (MOVED).
 *
 * Read by agora.vw_PumpItChange (Reports v1__22n), as every named write's
 * ledger is — tests/Feature/Reports/AuditTrailTest finds it by its name.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationHelper::table('PartnersWrite', function (Blueprint $table) {
            $table->uuid('WriteId');
            /*
             * supplier      a BRN_Suppliers column on one site's row
             * supplier-add  a BRN_Suppliers row inserted (Field = 'Row')
             * customer      a BRN_Customers column
             * customer-add  a BRN_Customers row inserted (Field = 'Row')
             */
            $table->string('Verb', 14);
            $table->string('PumpItTable', 40);
            $table->string('PartnerCode', 10);
            $table->string('Field', 40);
            $table->string('PriorValue', 200)->nullable();
            $table->string('NewValue', 200)->nullable();
            $table->string('Reason', 400);
            $table->string('WriteMode', 10);
            $table->string('State', 10)->default('active');
            MigrationHelper::stamp($table, 'ReversedAt');
            $table->integer('ReversedBy')->nullable();
            $table->string('ReversalReason', 400)->nullable();
            $table->index(['WriteId'], 'IX_PartnersWrite_Write');
            $table->index(['PartnerCode', 'BranchId'], 'IX_PartnersWrite_Partner');
        });

        MigrationHelper::recordVersion(
            '1.1',
            'Partners (T024): agora.PartnersWrite, the ledger of supplier and customer edits written back to '
            .'BRN_Suppliers and BRN_Customers (PM decision 01a1cecf-8e08).'
        );
    }

    /** Local sandbox only. Live is forward-only. */
    public function down(): void
    {
        MigrationHelper::drop('PartnersWrite');
    }
};
