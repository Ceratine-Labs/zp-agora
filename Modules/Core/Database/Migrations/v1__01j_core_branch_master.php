<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The branch master (T021): what a branch IS, beyond its id and name.
 *
 * Plan §4 gives agora.Branch a Region, a Brand, a Format, a Status, a POS type
 * and an administrative-entity flag, and makes Region / Brand / Format small
 * masters of Agora's own — derived from PumpIT once, then edited here.
 *
 * WHAT IS ADDED
 *
 *   agora.Region, agora.Brand, agora.Format
 *       Derived from SS_Region (6), SS_Brand (8) and SS_Class (4) by
 *       usp_Migrate_Branch. LegacyId keeps the PumpIT id, so the derivation
 *       is reconcilable and re-runnable. "Format" is what PumpIT calls a
 *       class; the plan's word is used from here on.
 *   agora.BranchProfitCentre
 *       Which profit centres a site runs. Derived from the ACTIVE rows of
 *       SDK_CategoryGLCode (POS category → profit centre, per branch), the only
 *       place PumpIT records it. ProfitCentreId is SS_ProfitCentre's id, read
 *       through agora.vw_ProfitCentre until T022 gives the profit centre a
 *       master of its own.
 *   agora.MasterChange
 *       One row per field changed on a master, with the value before and
 *       after, who and why. Written by the procedures in the same transaction
 *       as the change. Entity names the master (Branch, Region, Brand,
 *       Format). There is no agora.AuditLog yet (T010 was never built); this
 *       is the master-data half of it, and T027 can fold it in.
 *   agora.Branch: FormatId, Status, POSType, IsAdminEntity, LegacyName,
 *       MigratedAt.
 *       - RegionId and BrandId now mean agora.Region.Id / agora.Brand.Id (on
 *         the first derivation those are the PumpIT ids, because the tables
 *         are filled in PumpIT id order).
 *       - ClassId stays the PumpIT class id it always held, for anything
 *         reading it today; FormatId is Agora's.
 *       - Status is Trading | Mothballed | Non-trading. IsTrading — read by
 *         every trading list and the scope bar — is kept, and set by the
 *         procedures to Status = 'Trading', so a site marked mothballed drops
 *         out of those lists without a second edit.
 *       - POSType is BRANCH | NAMOS, as PumpIT spells it (Total Hluhluwe is
 *         the one NAMOS site).
 *       - LegacyName is SS_Branch.BranchName as it was migrated, so a rename
 *         here stays reconcilable with PumpIT.
 *       - MigratedAt is the derivation's own gate: usp_Migrate_Branch only
 *         touches a branch whose MigratedAt is NULL, so a re-run never
 *         overwrites an edit made on the Branches screen.
 *
 * NEW COLUMNS ARE NOT NULL WITH A DEFAULT (or nullable), because tests and
 * fixtures insert agora.Branch rows naming only Name / IsTrading / IsActive.
 *
 * vw_Branch IS REPOINTED from PumpIT's SS_Branch to agora.Branch, carrying the
 * same six columns it always did plus the names of the groupings and the new
 * flags. Every report procedure joins it for the site name; BranchId is the
 * same id space, so nothing joined to it changes.
 *
 * Nothing here writes PumpIT. The four legacy views below only read it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');
        $erp = config('agora.source_databases.erp');

        foreach (['Region', 'Brand', 'Format'] as $grouping) {
            MigrationHelper::table($grouping, function (Blueprint $table) {
                $table->string('Name', 80);
                // The PumpIT id this row was derived from; NULL for one made here.
                $table->integer('LegacyId')->nullable();
                $table->boolean('IsActive')->default(true);
                $table->integer('SortOrder')->default(0);
            });
            MigrationHelper::naturalKey($grouping, ['BranchId', 'Name']);
            MigrationHelper::rowVersion($grouping);
            MigrationHelper::softDeletes($grouping);
        }

        // BranchId is the SITE here, not the group entity: a profit centre
        // belongs to the branch that runs it.
        MigrationHelper::table('BranchProfitCentre', function (Blueprint $table) {
            $table->integer('ProfitCentreId');
            // 'PumpIT' when derived from SDK_CategoryGLCode, 'Agora' when ticked here.
            $table->string('Source', 20)->default('Agora');
        });
        MigrationHelper::naturalKey('BranchProfitCentre', ['BranchId', 'ProfitCentreId']);

        // BranchId is the branch the change is ABOUT for a Branch row, and the
        // group entity for a Region / Brand / Format row (they are global).
        MigrationHelper::table('MasterChange', function (Blueprint $table) {
            $table->string('Entity', 40);
            $table->bigInteger('EntityId');
            $table->string('Field', 40);
            $table->string('PriorValue', 400)->nullable();
            $table->string('NewValue', 400)->nullable();
            $table->string('Reason', 400)->nullable();
            // 'edit' from a screen, 'migrate' from usp_Migrate_Branch.
            $table->string('Source', 20)->default('edit');
        });
        DB::statement("CREATE INDEX [IX_MasterChange_Entity] ON [{$schema}].[MasterChange] ([BranchId], [Entity], [EntityId], [Id]);");

        Schema::table("{$schema}.Branch", function (Blueprint $table) {
            $table->integer('FormatId')->nullable();
            $table->string('Status', 20)->default('Trading');
            $table->string('POSType', 20)->default('BRANCH');
            $table->boolean('IsAdminEntity')->default(false);
            $table->string('LegacyName', 100)->nullable();
            $table->rawColumn('MigratedAt', 'DATETIME2(0)')->nullable();
        });

        DB::statement("ALTER TABLE [{$schema}].[Branch] ADD CONSTRAINT [CK_Branch_Status]
            CHECK ([Status] IN ('Trading', 'Mothballed', 'Non-trading'));");
        DB::statement("ALTER TABLE [{$schema}].[Branch] ADD CONSTRAINT [CK_Branch_POSType]
            CHECK ([POSType] IN ('BRANCH', 'NAMOS'));");
        DB::statement("ALTER TABLE [{$schema}].[MasterChange] ADD CONSTRAINT [CK_MasterChange_Source]
            CHECK ([Source] IN ('edit', 'migrate'));");

        /*
         * The PumpIT side of the derivation, one view per source, so
         * usp_Migrate_Branch reads nothing in dbo directly (plan §3.3).
         */
        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_LegacyBranch] AS
            SELECT
                b.SSBranchId                                   AS BranchId,
                LTRIM(RTRIM(b.BranchName))                     AS Name,
                b.RegionId,
                b.BrandId,
                b.ClassId,
                b.IsActive,
                NULLIF(LTRIM(RTRIM(b.POSType)), '')            AS POSType,
                b.IsPOSImport
            FROM [{$erp}].dbo.SS_Branch b;
        ");

        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_LegacyBranchGrouping] AS
            SELECT N'Region' AS Kind, r.Regionid AS LegacyId, LTRIM(RTRIM(r.Region)) AS Name, r.IsActive
            FROM [{$erp}].dbo.SS_Region r
            UNION ALL
            SELECT N'Brand', b.Brandid, LTRIM(RTRIM(b.BrandName)), b.IsActive
            FROM [{$erp}].dbo.SS_Brand b
            UNION ALL
            SELECT N'Format', c.Classid, LTRIM(RTRIM(c.ClassName)), c.IsActive
            FROM [{$erp}].dbo.SS_Class c;
        ");

        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_ProfitCentre] AS
            SELECT p.ProfitCentreId, LTRIM(RTRIM(p.ProfitCentre)) AS Name, p.IsActive
            FROM [{$erp}].dbo.SS_ProfitCentre p;
        ");

        // SDK_CategoryGLCode names the profit centre by TEXT. SS_ProfitCentre's
        // 19 names are distinct, so the name join cannot fan out, and DISTINCT
        // collapses a branch's many categories to one row per centre.
        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_LegacyBranchProfitCentre] AS
            SELECT DISTINCT g.SSBranchId AS BranchId, p.ProfitCentreId
            FROM [{$erp}].dbo.SDK_CategoryGLCode g
            JOIN [{$erp}].dbo.SS_ProfitCentre p
              ON LTRIM(RTRIM(p.ProfitCentre)) = LTRIM(RTRIM(g.ProfitCentre))
            WHERE g.IsActive = 1;
        ");

        // Repointed: Agora's branch, not PumpIT's. The first six columns are
        // the ones every caller already reads, in the same order.
        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_Branch] AS
            SELECT
                b.BranchId,
                b.Name,
                b.BrandId,
                b.RegionId,
                b.ClassId,
                b.IsActive,
                b.FormatId,
                r.Name          AS RegionName,
                br.Name         AS BrandName,
                f.Name          AS FormatName,
                b.Status,
                b.POSType,
                b.IsAdminEntity,
                b.IsTrading
            FROM [{$schema}].[Branch] b
            LEFT JOIN [{$schema}].[Region] r  ON r.Id = b.RegionId
            LEFT JOIN [{$schema}].[Brand]  br ON br.Id = b.BrandId
            LEFT JOIN [{$schema}].[Format] f  ON f.Id = b.FormatId;
        ");

        MigrationHelper::recordVersion(
            '1.12',
            'Branch master (T021): agora.Region/Brand/Format, BranchProfitCentre, MasterChange; Branch Status, POSType, '
            .'IsAdminEntity, FormatId, LegacyName, MigratedAt; vw_Branch repointed to agora.Branch.'
        );
    }

    /**
     * Local only. Live is forward-only, and once the branch master carries an
     * edit this would lose it.
     */
    public function down(): void
    {
        $schema = config('agora.schema');
        $erp = config('agora.source_databases.erp');

        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_Branch] AS
            SELECT b.SSBranchId AS BranchId, b.BranchName AS Name, b.BrandId, b.RegionId, b.ClassId, b.IsActive
            FROM [{$erp}].dbo.SS_Branch b;
        ");
        foreach (['vw_LegacyBranchProfitCentre', 'vw_ProfitCentre', 'vw_LegacyBranchGrouping', 'vw_LegacyBranch'] as $view) {
            DB::unprepared("DROP VIEW IF EXISTS [{$schema}].[{$view}];");
        }

        DB::statement("ALTER TABLE [{$schema}].[Branch] DROP CONSTRAINT IF EXISTS [CK_Branch_Status], [CK_Branch_POSType];");
        foreach (['FormatId', 'Status', 'POSType', 'IsAdminEntity', 'LegacyName', 'MigratedAt'] as $column) {
            DB::statement("
                DECLARE @df SYSNAME = (SELECT d.name FROM sys.default_constraints d
                    JOIN sys.columns c ON c.object_id = d.parent_object_id AND c.column_id = d.parent_column_id
                    WHERE d.parent_object_id = OBJECT_ID('{$schema}.Branch') AND c.name = '{$column}');
                IF @df IS NOT NULL EXEC('ALTER TABLE [{$schema}].[Branch] DROP CONSTRAINT [' + @df + ']');
                ALTER TABLE [{$schema}].[Branch] DROP COLUMN [{$column}];
            ");
        }

        foreach (['MasterChange', 'BranchProfitCentre', 'Format', 'Brand', 'Region'] as $table) {
            MigrationHelper::drop($table);
        }
    }
};
