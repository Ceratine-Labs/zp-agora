<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');
        // Existing roles and assignments remain historical. Only newly created
        // managed roles participate in access; no old grant is reactivated.
        DB::statement("ALTER TABLE [{$schema}].[Role] ADD [IsManaged] BIT NOT NULL CONSTRAINT [DF_Role_IsManaged] DEFAULT 0 WITH VALUES");
        MigrationHelper::recordVersion('1.10-core-roles', 'Customer-managed permission and menu roles; historical roles remain inert.');
    }

    public function down(): void
    {
        // Forward only: preserving customer-defined roles is intentional.
    }
};
