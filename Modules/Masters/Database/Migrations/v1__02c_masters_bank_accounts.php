<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bank accounts (T022): the customer's own bankrecon.BankAccount, read only.
 *
 * Ryan, 12 Nov 2026 (PM decision 01a1ceda-3523): the bank accounts are
 * bankrecon.BankAccount — 145 rows the customer set up in September 2026, one
 * per branch and method of payment — and NOT the plan's BRN_BankAccountSetup,
 * whose 1,491 rows are daily copies of one account at 14 sites, last written
 * September 2017. Listed, not edited. Nothing is filtered here: IsDeleted is
 * the procedure's to apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');
        $erp = config('agora.source_databases.erp');

        DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[vw_MasterBankAccount] AS
            SELECT a.BranchId,
                   a.BankAccountId,
                   a.MethodOfPaymentId,
                   a.[Type]              AS PaymentType,
                   a.BankName,
                   a.AccountName,
                   a.AccountNumber,
                   a.IsActive,
                   a.IsDeleted,
                   a.CreatedAt,
                   a.ModifiedAt
            FROM [{$erp}].bankrecon.BankAccount a;");

        MigrationHelper::recordVersion('1.50', 'Masters (T022): vw_MasterBankAccount over bankrecon.BankAccount (PM decision 01a1ceda-3523).');
    }

    /** Local only. */
    public function down(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS ['.config('agora.schema').'].[vw_MasterBankAccount];');
    }
};
