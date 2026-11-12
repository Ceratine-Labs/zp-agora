<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * System defaults and thresholds (T026): agora.Setting.
 *
 * ONE ROW PER KEY PER BRANCH. The group entity's row (BranchId 2, never NULL)
 * is the estate's value; a site's row with the same key overrides it for that
 * site. Settings::for($branchId, 'key') reads the site's row, then the
 * group's, then the code default in Modules\Core\Support\SettingCatalogue —
 * so a setting nobody has seeded yet still behaves exactly as it did when it
 * was a module config literal.
 *
 * What a row carries:
 *
 *   SettingKey    module.area.name, e.g. cash.dropsafe.reason_threshold
 *   Value         the value as text, normalised by type (2000.00, 0.7500,
 *                 1/0, certain); NULL only where IsNullable says it may be
 *   Type          int | decimal | money | litres | share | bool | string | choice
 *   Choices       for a choice, the allowed values separated by |
 *   MinValue / MaxValue   bounds for a number, refused outside them
 *   AllowsBranch  0 where a site may not override — an estate-wide report
 *                 takes one value for every site (the payroll leniency) and a
 *                 site override would make it disagree with itself
 *   UsedBy        what reads it, in words; NULL means nothing reads it yet
 *                 (a placeholder a later task fills)
 *   Source        seed (Agora's own default), pumpit (a copy of PumpIT's
 *                 SS_SysDefaults / BRN_Config), edit (changed on the screen)
 *   LegacySource  for a pumpit row, the PumpIT table and column it came from
 *
 * Every change is audited in agora.MasterChange (Entity 'Setting', one row per
 * value changed, with the value before and after, who and why), written by
 * agora.usp_Core_SaveSetting in the same transaction. A site override that is
 * removed is soft-deleted, never deleted, and a second override for the same
 * site revives that row.
 *
 * THE TWO LEGACY VIEWS read PumpIT and nothing else. vw_LegacySysDefault lists
 * the eight business columns of SS_SysDefaults' single row; the SMTP, FTP and
 * upgrade credentials in the same row are deliberately not in it, so they can
 * never be copied into Agora. vw_LegacyBranchConfig lists BRN_Config without
 * its two import cursors (LastBranchImportedDay, LastPECImportedDebtorTRX),
 * which are PumpIT's own moving state rather than configuration: a copy would
 * be wrong by the next import. agora.usp_Migrate_Setting copies what the views
 * show into agora.Setting once; PumpIT is never written.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');
        $erp = config('agora.source_databases.erp');

        MigrationHelper::table('Setting', function (Blueprint $table) {
            $table->string('SettingKey', 120);
            $table->string('Value', 400)->nullable();
            $table->string('Type', 20);
            $table->string('Description', 400)->nullable();
            $table->string('Choices', 200)->nullable();
            $table->decimal('MinValue', 18, 4)->nullable();
            $table->decimal('MaxValue', 18, 4)->nullable();
            $table->boolean('IsNullable')->default(false);
            $table->boolean('AllowsBranch')->default(true);
            $table->string('UsedBy', 200)->nullable();
            $table->string('Source', 20)->default('seed');
            $table->string('LegacySource', 120)->nullable();
        });
        MigrationHelper::naturalKey('Setting', ['BranchId', 'SettingKey']);
        MigrationHelper::rowVersion('Setting');
        MigrationHelper::softDeletes('Setting');
        DB::statement("CREATE INDEX [IX_Setting_Key] ON [{$schema}].[Setting] ([SettingKey], [BranchId]) INCLUDE ([Value], [Type], [DeletedAt]);");

        DB::statement("ALTER TABLE [{$schema}].[Setting] ADD CONSTRAINT [CK_Setting_Type]
            CHECK ([Type] IN ('int', 'decimal', 'money', 'litres', 'share', 'bool', 'string', 'choice'));");
        DB::statement("ALTER TABLE [{$schema}].[Setting] ADD CONSTRAINT [CK_Setting_Source]
            CHECK ([Source] IN ('seed', 'pumpit', 'edit'));");

        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_LegacySysDefault] AS
            SELECT v.ColumnName, v.Value, v.Description
            FROM (SELECT TOP 1 * FROM [{$erp}].dbo.SS_SysDefaults ORDER BY Autoidx) d
            CROSS APPLY (VALUES
                (N'SupplierInvoiceNoOfDays', CONVERT(NVARCHAR(40), d.SupplierInvoiceNoOfDays), N'PumpIT: days of supplier invoices offered'),
                (N'CashBagsDaysTOADD',       CONVERT(NVARCHAR(40), d.CashBagsDaysTOADD),       N'PumpIT: days added to the cash bags window'),
                (N'SmartATMDaysTOADD',       CONVERT(NVARCHAR(40), d.SmartATMDaysTOADD),       N'PumpIT: days added to the Smart ATM window'),
                (N'CashDevicesDaysTOADD',    CONVERT(NVARCHAR(40), d.CashDevicesDaysTOADD),    N'PumpIT: days added to the cash devices window'),
                (N'ABSADaysTOADD',           CONVERT(NVARCHAR(40), d.ABSADaysTOADD),           N'PumpIT: days added to the ABSA window'),
                (N'ZapperDaysTOADD',         CONVERT(NVARCHAR(40), d.ZapperDaysTOADD),         N'PumpIT: days added to the Zapper window'),
                (N'YumbiDaysTOADD',          CONVERT(NVARCHAR(40), d.YumbiDaysTOADD),          N'PumpIT: days added to the Yumbi window'),
                (N'InfinityDaysTOADD',       CONVERT(NVARCHAR(40), d.InfinityDaysTOADD),       N'PumpIT: days added to the Infinity window')
            ) v (ColumnName, Value, Description);
        ");

        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_LegacyBranchConfig] AS
            SELECT c.SSBranchId AS BranchId,
                   LTRIM(RTRIM(c.ConfigDescription)) AS ConfigName,
                   LTRIM(RTRIM(c.ValueType)) AS ValueType,
                   c.IValue, c.SValue, c.BValue,
                   CONVERT(NVARCHAR(400), c.[Description]) AS [Description]
            FROM [{$erp}].dbo.BRN_Config c
            WHERE LTRIM(RTRIM(c.ConfigDescription)) NOT IN (N'LastBranchImportedDay', N'LastPECImportedDebtorTRX');
        ");

        MigrationHelper::recordVersion(
            '1.13',
            'System defaults and thresholds (T026): agora.Setting (group value, site overrides, typed, audited in MasterChange); '
            .'vw_LegacySysDefault and vw_LegacyBranchConfig over PumpIT, read-only.'
        );
    }

    /** Local only. Live is forward-only, and once a setting is edited this would lose it. */
    public function down(): void
    {
        $schema = config('agora.schema');
        DB::unprepared("DROP VIEW IF EXISTS [{$schema}].[vw_LegacyBranchConfig];");
        DB::unprepared("DROP VIEW IF EXISTS [{$schema}].[vw_LegacySysDefault];");
        MigrationHelper::drop('Setting');
    }
};
