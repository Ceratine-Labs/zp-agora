<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Explicit measurement metadata: customer confirmed cumulative litres on 25 Oct 2026. */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');
        DB::unprepared("CREATE TABLE [{$schema}].[StockReconMeter] (
            [BranchId] INT NOT NULL,
            [AreaNo] INT NOT NULL,
            [StockItemNo] NVARCHAR(20) NOT NULL,
            [Label] NVARCHAR(100) NOT NULL,
            [Unit] NVARCHAR(8) NOT NULL CONSTRAINT [DF_StockReconMeter_Unit] DEFAULT 'L',
            [IsActive] BIT NOT NULL CONSTRAINT [DF_StockReconMeter_IsActive] DEFAULT 1,
            CONSTRAINT [PK_StockReconMeter] PRIMARY KEY ([BranchId], [AreaNo], [StockItemNo]),
            CONSTRAINT [CK_StockReconMeter_Unit] CHECK ([Unit] = 'L')
        );
        INSERT INTO [{$schema}].[StockReconMeter] ([BranchId], [AreaNo], [StockItemNo], [Label])
        VALUES (3, 25, '346', 'Refill water meter'), (9, 25, '378', 'Refill water meter'),
               (15, 25, '297', 'Refill water meter'), (24, 25, '312', 'Refill water meter');
        ALTER TABLE [{$schema}].[StockReconRun] ADD [ExcludedMeterRows] INT NOT NULL
            CONSTRAINT [DF_StockReconRun_ExcludedMeterRows] DEFAULT 0;");

        MigrationHelper::recordVersion('1.41',
            'Stock recon: explicit cumulative water-meter metadata and visible exclusion from new physical balancing previews.');
    }

    /** Local sandbox only; production migrations are forward-only. */
    public function down(): void
    {
        $schema = config('agora.schema');
        DB::unprepared("ALTER TABLE [{$schema}].[StockReconRun] DROP CONSTRAINT [DF_StockReconRun_ExcludedMeterRows];
            ALTER TABLE [{$schema}].[StockReconRun] DROP COLUMN [ExcludedMeterRows];
            DROP TABLE [{$schema}].[StockReconMeter];");
    }
};
