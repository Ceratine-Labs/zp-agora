<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The deposit's own id on a proposal (Ryan, 7 September 2026).
 *
 * A proposal already records `BankLineId` — the bank statement line it is
 * about, where there is exactly one. There has never been a counterpart for
 * the deposit side, because four of the five areas have no id to record: ABSA,
 * FNB, CashMachine and SmartATM all identify a deposit by a reference and a
 * date, which is why usp_Recon_Commit stores that key as JSON.
 *
 * CashBags is the exception — `BRN_DailyBankingCashBags` has
 * `DailyBankingCashBagID`, and the drill's own comment already calls it "the
 * one area whose deposit table has a key of its own".
 *
 * WHY IT IS NEEDED NOW. A CashBags proposal with no bank line — "Deposit only
 * - no bank line" — cannot be drilled at all. In `contains` mode the deposit
 * side is found by looking for the bag reference inside a bank NARRATIVE, and
 * an orphan has no narrative to look in, so the panel and both exports came
 * back empty for all eleven of them on run 53.
 *
 * A reference will not do instead. Two orphans on that run — lines 4435 and
 * 4436, R530.00 on 29 June and R471.80 on 1 July — share the DBagNo
 * 304822859458, so matching on it would show both deposits under each row and
 * double the money on screen. The bag's own id is the only thing that names
 * one bag.
 *
 * NULLABLE, and null nearly everywhere: only CashBags fills it, and only for a
 * proposal that is a single deposit. A run previewed before this column
 * existed keeps working — the drill falls back to the reference path exactly
 * as it does today.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');

        Schema::table("{$schema}.ReconRunLine", function (Blueprint $table) {
            $table->bigInteger('MopsSourceId')->nullable();
        });

        MigrationHelper::recordVersion(
            '1.7',
            'agora.ReconRunLine.MopsSourceId — the deposit\'s own id, so a CashBags orphan can be drilled.'
        );
    }

    public function down(): void
    {
        $schema = config('agora.schema');

        Schema::table("{$schema}.ReconRunLine", function (Blueprint $table) {
            $table->dropColumn('MopsSourceId');
        });
    }
};
