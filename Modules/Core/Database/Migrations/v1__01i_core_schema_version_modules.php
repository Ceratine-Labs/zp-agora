<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // The helper upgrades and stamps together, even when up() is invoked
        // directly without the migrator's outer schema transaction.
        MigrationHelper::recordVersion('1.11',
            'SchemaVersion key: BranchId + Module + Version. Surviving pre-upgrade rows retained as LegacyUnattributed; overwritten historical notes cannot be recovered from this ledger.');
    }

    public function down(): void
    {
        // Forward-only: removing Module would merge unrelated migration stamps.
    }
};
