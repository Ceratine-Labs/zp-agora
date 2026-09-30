<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Cash — deductions and the payroll file (T041, slot 12e).
 *
 * The legacy deduction schedule is not a table: it is five report procedures
 * a person runs for a site and a period, and the numbers exist only on the
 * screen they print to (dbo.sp_RPT_CashShortDeductionPerEmployee, the stock
 * loss PAYROLL / PAYROLLDETAIL / LeniencySummary set and the non-integrated
 * POS pair). There is no legacy counterpart to adopt, so — per PM decision
 * 01a0e22a-97be — the schedule Agora BUILDS for a pay period lives in Agora:
 *
 *   agora.DeductionRun   one build of one site's period: the leniency it was
 *                        built with, the totals, and whether the payroll file
 *                        has been taken from it. A rebuild supersedes the
 *                        previous run rather than rewriting it.
 *   agora.Deduction      one row per thing that was short — a cashup, a pump
 *                        shortage, a cash-bag short, a person's stock loss in
 *                        an area group, a non-integrated area — with what it
 *                        was, the allowance, and what is deducted. One row per
 *                        source (UX_Deduction_Natural), so a short reaches the
 *                        schedule exactly once.
 *
 * And the one legacy table the reports read that Agora had no view of:
 *
 *   agora.vw_EmployeePost   BRN_EmployeePost — the post printed beside a
 *                           name, and an INNER JOIN in the legacy stock-loss
 *                           and non-integrated tabs, which the port keeps.
 *
 * Nothing here writes to the customer's databases.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');
        $erp = (string) config('agora.source_databases.erp');

        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_EmployeePost] AS
            SELECT p.SSBranchId       AS BranchId,
                   p.PostNo,
                   p.PostDescription
            FROM [{$erp}].dbo.BRN_EmployeePost p;
        ");

        /*
         *   Status       built | superseded | exported
         *   CashLeniency the R-amount a cashier short had to exceed to be
         *                deducted when this run was built (config
         *                cash.deductions.cash_leniency, R3.00 in the legacy)
         *   the totals   over the run's rows, negative as the legacy prints
         *                them: Shorts is everything that was short, WrittenOff
         *                the part inside an allowance, Deducted the rest
         */
        MigrationHelper::table('DeductionRun', function (Blueprint $table) {
            $table->date('FromDate');
            $table->date('ToDate');
            $table->string('Status', 12)->default('built');
            MigrationHelper::money($table, 'CashLeniency');
            $table->integer('Lines')->default(0);
            $table->integer('Employees')->default(0);
            MigrationHelper::money($table, 'Shorts');
            MigrationHelper::money($table, 'WrittenOff');
            MigrationHelper::money($table, 'Deducted');
            MigrationHelper::money($table, 'CashDeducted');
            MigrationHelper::money($table, 'StockDeducted');
            MigrationHelper::money($table, 'NonIntDeducted');
            $table->dateTime('SupersededAt', 0)->nullable();
            $table->unsignedBigInteger('SupersededBy')->nullable();
            $table->dateTime('ExportedAt', 0)->nullable();
            $table->integer('ExportedBy')->nullable();
            $table->string('Note', 400)->nullable();
        });

        DB::statement("
            ALTER TABLE [{$schema}].[DeductionRun]
                ADD CONSTRAINT [CK_DeductionRun_Status] CHECK ([Status] IN ('built', 'superseded', 'exported')),
                    CONSTRAINT [CK_DeductionRun_Period] CHECK ([ToDate] >= [FromDate]);
        ");
        DB::statement("CREATE INDEX [IX_DeductionRun_Period] ON [{$schema}].[DeductionRun] ([BranchId], [FromDate], [ToDate], [Status]);");

        /*
         *   Source      cashier_short  a cashup's variance (legs - Z-read)
         *               pump_short     a line of a cashup's pump shortages leg
         *               cashbag_short  a staff short a cash-bag match raised and
         *                              an approver passed — never on a cashup
         *                              (PM decision 01a0e5b5)
         *               stock_loss     a person's stock loss in one area group
         *                              for the period, against its leniency
         *               nonint_pos     a person's loss on a Lotto / Electricity /
         *                              Airtime area for the period
         *   SourceKey   what makes the row unique within its source: the cashup
         *               (date|till|shift), the pump line (…|employee), the staff
         *               short number, the area group, the area
         *   Amount      what was short, negative
         *   Allowance   the leniency it was measured against, negative (0 where
         *               there is none — pump, cash-bag and non-integrated
         *               shorts are deducted in full)
         *   Deducted    what the payroll takes, negative; 0 when written off
         */
        MigrationHelper::table('Deduction', function (Blueprint $table) {
            $table->unsignedBigInteger('RunId');
            $table->string('Source', 16);
            $table->string('SourceKey', 80);
            $table->string('EmployeeCode', 22);
            $table->string('EmployeeName', 120)->nullable();
            $table->string('PostDescription', 100)->nullable();
            $table->date('SourceDate')->nullable();
            $table->integer('TillNo')->nullable();
            $table->integer('ShiftNo')->nullable();
            $table->integer('AreaNo')->nullable();
            $table->string('AreaGroup', 50)->nullable();
            $table->string('SourceRef', 60)->nullable();
            MigrationHelper::money($table, 'Amount');
            MigrationHelper::money($table, 'Allowance');
            MigrationHelper::money($table, 'Deducted');
            $table->boolean('IsWrittenOff')->default(false);
            $table->string('Note', 400)->nullable();
        });

        DB::statement("
            ALTER TABLE [{$schema}].[Deduction]
                ADD CONSTRAINT [CK_Deduction_Source]
                    CHECK ([Source] IN ('cashier_short', 'pump_short', 'cashbag_short', 'stock_loss', 'nonint_pos'));
        ");
        MigrationHelper::naturalKey('Deduction', ['BranchId', 'RunId', 'Source', 'SourceKey', 'EmployeeCode']);
        DB::statement("CREATE INDEX [IX_Deduction_Employee] ON [{$schema}].[Deduction] ([BranchId], [RunId], [EmployeeCode]) INCLUDE ([Source], [Deducted]);");
    }

    /** Local sandbox only. Live and staging are forward-only — see CLAUDE.md. */
    public function down(): void
    {
        $schema = config('agora.schema');

        MigrationHelper::drop('Deduction');
        MigrationHelper::drop('DeductionRun');
        DB::statement("DROP VIEW IF EXISTS [{$schema}].[vw_EmployeePost];");
    }
};
