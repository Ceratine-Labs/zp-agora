<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Recon — room for the near-reference note (slot 13d).
 *
 * NVARCHAR(200) was sized when the note only had to say the two references
 * were a character apart. It now has to say WHY — that MOPS_EndPosition means
 * different things in different areas, and that the pairing is therefore an
 * inference rather than the configured rule. That sentence is the whole reason
 * the flag is worth having, so the column widens rather than the sentence
 * getting cut.
 *
 * A lettered follow-on: v1__13b has already run against the customer's
 * instance, and widening a column is not something to hide inside an edit to
 * a migration that has run.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ['.config('agora.schema').'].[ReconRunLine] ALTER COLUMN [NearRefNote] NVARCHAR(600) NULL;');
    }

    public function down(): void
    {
        // Narrowing would truncate whatever is already stored. Forward only.
    }
};
