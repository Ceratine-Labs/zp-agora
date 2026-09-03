<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Core — the tables everything else stands on (slot 01).
 *
 * Six tables and no more: the schema's own version stamp, the branch spine,
 * identity, and the database-driven menu. Anything that is not needed to sign
 * in and see a navigable shell belongs to a later module.
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
    }

    public function down(): void
    {
        foreach (['MenuItem', 'MenuSection', 'UserBranch', 'User', 'Role', 'Branch', 'SchemaVersion'] as $table) {
            MigrationHelper::drop($table);
        }
    }
};
