<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Core — the tables everything else stands on (slot 01).
 *
 * Ten tables and one view: the schema's own version stamp, the branch spine,
 * identity, per-user preferences and saved grid columns, the seed ledger, and
 * the database-driven menu. Anything that is not needed to sign in and see a navigable shell
 * belongs to a later module.
 *
 * **Consolidated 4 Sep 2026.** This file was v1__01 plus four lettered
 * follow-ons (01a seed ledger and the first view, 01b user preferences, 01c
 * and 01d reshaping the ledger twice). They were folded back in on Ryan's
 * instruction so the module has one create and no alters: a schema this young,
 * whose only rows are ones our own seeders wrote, is cheap to restate and
 * expensive to read as a pile of diffs. The folded files were removed from
 * `agora.Migration` on PumpIT at the same time, so the ledger names files that
 * exist.
 *
 * That is a one-time tidy, not a new habit. The rule in CLAUDE.md stands from
 * here: once a table holds data the business cares about, a change to it is a
 * new lettered file, never an edit to this one.
 *
 * `agora.Branch` is a MIGRATED copy of `dbo.SS_Branch`, not a view over it —
 * the branch id leads every clustered index in the system, so the one table
 * that defines that id has to be ours. It is seeded from SS_Branch and stays
 * reconcilable with it (BranchId is the same id space: SS_Branch.SSBranchId,
 * verified against the live database).
 *
 * Foreign keys are deliberately absent — plan §3.3 puts them in one late file
 * per module because the reference graph has cycles SQL Server rejects.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The version stamp the plan asks every schema change to record.
        MigrationHelper::table('SchemaVersion', function (Blueprint $table) {
            $table->string('Version', 20);
            $table->string('Note', 400)->nullable();
            $table->dateTime('AppliedAt', 0);
        });
        MigrationHelper::naturalKey('SchemaVersion', ['BranchId', 'Version']);

        // The branch spine. BranchId here IS the site id — SS_Branch.SSBranchId.
        MigrationHelper::table('Branch', function (Blueprint $table) {
            $table->string('Name', 100);
            $table->integer('BrandId')->nullable();
            $table->integer('RegionId')->nullable();
            $table->integer('ClassId')->nullable();
            // A trading site keeps a day; an administrative entity (the group,
            // the property companies, the trusts) does not, and must not appear
            // in a day-close queue.
            $table->boolean('IsTrading')->default(true);
            $table->boolean('IsActive')->default(true);
            $table->integer('SortOrder')->default(0);
        });
        MigrationHelper::naturalKey('Branch', ['BranchId']);
        MigrationHelper::rowVersion('Branch');
        MigrationHelper::softDeletes('Branch');

        // Five roles, each with its own landing page (plan §2).
        MigrationHelper::table('Role', function (Blueprint $table) {
            $table->string('Code', 40);
            $table->string('Name', 80);
            $table->string('LandingRoute', 120)->nullable();
            $table->string('Workspace', 10)->default('ho');
            $table->boolean('IsReadOnly')->default(false);
            $table->integer('SortOrder')->default(0);
        });
        MigrationHelper::naturalKey('Role', ['BranchId', 'Code']);
        MigrationHelper::rowVersion('Role');

        MigrationHelper::table('User', function (Blueprint $table) {
            $table->string('UserName', 80);
            $table->string('EmailAddress', 160);
            $table->string('PasswordHash', 255);
            $table->bigInteger('RoleId')->nullable();
            // Which site a branch user lands on. Null for head office users.
            $table->integer('HomeBranchId')->nullable();
            $table->boolean('IsActive')->default(true);
            $table->boolean('IsLocked')->default(false);
            $table->dateTime('LastSignInAt', 0)->nullable();
            $table->string('RememberToken', 100)->nullable();
            // The legacy row this user came from, so a migration from
            // dbo.SS_Users is reconcilable and re-runnable.
            $table->integer('LegacyUserId')->nullable();
        });
        MigrationHelper::naturalKey('User', ['BranchId', 'EmailAddress']);
        MigrationHelper::rowVersion('User');
        MigrationHelper::softDeletes('User');

        // Which sites a user may see. Absence of rows means "every site" —
        // resolved in ResolveBranchContext, never by leaving BranchId null.
        MigrationHelper::table('UserBranch', function (Blueprint $table) {
            $table->bigInteger('UserId');
        });
        MigrationHelper::naturalKey('UserBranch', ['BranchId', 'UserId']);

        // What a person has chosen for themselves — the light or dark choice
        // today, later a saved grid layout or a default report scope. Key and
        // value rather than a column per preference, because these arrive one
        // at a time and a column each means a migration against a production
        // database every time somebody adds a checkbox.
        //
        // Business settings do NOT live here. Thresholds, approval bands and
        // anything else the company decides go in agora.Setting (T026),
        // because those are the company's rules and must not be per-user.
        MigrationHelper::table('UserPreference', function (Blueprint $table) {
            $table->bigInteger('UserId');
            $table->string('PrefKey', 60);
            $table->string('PrefValue', 400)->nullable();
        });
        MigrationHelper::naturalKey('UserPreference', ['BranchId', 'UserId', 'PrefKey']);

        // Which columns a person chose to see, per grid.
        //
        // A grid is addressed by its ROUTE NAME (`app.cash.dropsafe`) rather
        // than by a controller class: routes are what the menu, the command
        // palette and every link already use, a controller serves several
        // screens, and a class rename would silently orphan everyone's saved
        // choice. A screen carrying two grids qualifies the key with a suffix
        // (`app.cash.dropsafe:bags`).
        //
        // The payload is JSON — the visible columns, their order and their
        // widths — because it is read and written whole by the grid component
        // and nothing in the database ever needs to query inside it. A column
        // per setting would be a migration every time the grid learns a trick.
        MigrationHelper::table('UserGridColumn', function (Blueprint $table) {
            $table->bigInteger('UserId');
            $table->string('GridKey', 160);
            $table->text('ColumnsJson');
        });
        MigrationHelper::naturalKey('UserGridColumn', ['BranchId', 'UserId', 'GridKey']);

        // Which seeders have run here — Laravel's `migrations` table, in this
        // schema's naming. The gate is the class name and nothing else: logged
        // means done, absent means run it. A seeder is a one-shot the way a
        // migration is, so there is no version column; changing what was
        // seeded means writing another seeder.
        MigrationHelper::table('SeedMaster', function (Blueprint $table) {
            $table->string('SeederClass', 200);
            $table->string('Module', 60);
            $table->integer('Batch');
            $table->dateTime('ExecutedAt', 0);
        });
        MigrationHelper::naturalKey('SeedMaster', ['BranchId', 'SeederClass']);

        // ---- the menu (plan §3.10) ----------------------------------------
        // Sections are the top-level buttons in the app bar: Today, Trade,
        // Control, Setup for head office; Today, Stock, My site, Assets for a
        // branch.
        MigrationHelper::table('MenuSection', function (Blueprint $table) {
            $table->string('Workspace', 10);
            $table->string('Code', 40);
            $table->string('Label', 60);
            $table->integer('SortOrder')->default(0);
            $table->boolean('IsActive')->default(true);
        });
        MigrationHelper::naturalKey('MenuSection', ['BranchId', 'Workspace', 'Code']);

        // Items nest through ParentId to any depth. Depth 1 is a column
        // heading in the mega panel, depth 2 a link, depth 3+ a nested group
        // that expands in place. Nothing in the schema caps the depth — the
        // customer asked for children and sub-children, and a fixed
        // heading/item split would have to be migrated away the first time
        // they want a third level.
        MigrationHelper::table('MenuItem', function (Blueprint $table) {
            $table->bigInteger('SectionId');
            $table->bigInteger('ParentId')->nullable();
            $table->string('Label', 80);
            // A named route is preferred; Url is for anything not yet built.
            $table->string('RouteName', 120)->nullable();
            $table->string('Url', 300)->nullable();
            // The small right-hand note the mockup shows ("Live", "23", "5 tabs").
            $table->string('Hint', 40)->nullable();
            $table->string('PermissionCode', 80)->nullable();
            // FQCN returning a live count for the badge next to the label.
            $table->string('BadgeProvider', 200)->nullable();
            $table->string('Icon', 40)->nullable();
            $table->integer('SortOrder')->default(0);
            $table->boolean('IsMobile')->default(true);
            $table->boolean('IsActive')->default(true);
            // The stable identity for the upsert MenuService::item() performs,
            // so re-seeding never duplicates and a relabel is just an update.
            $table->string('Path', 300);
        });
        MigrationHelper::naturalKey('MenuItem', ['BranchId', 'SectionId', 'Path']);

        /*
         * The first legacy view.
         *
         * Every legacy table Agora reads is reached through one of these, for
         * four reasons set out in plan §3.3: alias SSBranchId to BranchId so
         * one name is used everywhere; hide the _OLD / _DEFUNCT / PREPROD_
         * twins; bracket reserved-word columns; and apply whatever dedupe rule
         * the join map recorded for that table.
         *
         * Only the first applies to SS_Branch, which is precisely why it is a
         * good place to establish the pattern — nothing here is load-bearing
         * yet, so a mistake is cheap.
         *
         * The legacy database is NAMED, because Agora's objects no longer live
         * inside it: `PumpIT.dbo.SS_Branch`, on the same instance. The name
         * comes from config rather than being written in, so a restore called
         * something else does not have a view silently reading the wrong
         * estate.
         *
         * CREATE OR ALTER so re-running is safe and a change shows in the diff
         * as an edit rather than a drop and recreate.
         */
        $schema = config('agora.schema');
        $erp = config('agora.source_databases.erp');

        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_Branch] AS
            SELECT
                b.SSBranchId    AS BranchId,
                b.BranchName    AS Name,
                b.BrandId,
                b.RegionId,
                b.ClassId,
                b.IsActive
            FROM [{$erp}].dbo.SS_Branch b;
        ");

        MigrationHelper::recordVersion(
            '1.0',
            'Core baseline: the version stamp, branches, identity and user preferences, '
            .'the seed ledger, the database-driven menu, and the first legacy view.'
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS ['.config('agora.schema').'].[vw_Branch];');

        foreach ([
            'MenuItem', 'MenuSection', 'SeedMaster', 'UserGridColumn',
            'UserPreference', 'UserBranch', 'User', 'Role', 'Branch',
            'SchemaVersion',
        ] as $table) {
            MigrationHelper::drop($table);
        }
    }
};
