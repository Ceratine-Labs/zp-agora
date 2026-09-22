<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Who may see which entry in the menu (customer request, 22 Sep 2026).
 *
 * The customer asked for the menu to be ticked item by item: a person either
 * has a tick against an entry and sees it, or has no tick and has no access.
 * agora.MenuItem has carried a PermissionCode since the baseline, but only 4
 * of 127 items set one and nothing ever read it — the menu was rendered whole
 * to everybody, and the only real gate was the `can:` middleware on the nine
 * routes that exist.
 *
 * WHY THIS IS NOT A PERMISSION CODE PER ITEM. A permission is a fact about the
 * software — PermissionSeeder says so, and refuses to mint slugs for screens
 * that do not exist yet. The menu is the opposite: it is the customer's own
 * structure, 127 entries deep, most of them placeholders for work not yet
 * built, and they add to it themselves. Minting a speculative permission for
 * every one of them would fill the role matrix with promises. A grant against
 * the menu row itself says exactly what it means and disappears with the row.
 *
 * TWO TABLES, BECAUSE THE ANSWER IS NORMALLY A ROLE. Ticking 127 entries for
 * each of 88 people is not an administration model. RoleMenuItem is where the
 * shape of the business is set; UserMenuItem is the exception for one person,
 * and the two are UNIONed exactly the way PermissionService unions role and
 * direct grants.
 *
 * AN EMPTY SET MEANS THE WHOLE MENU, not an empty one — the same convention as
 * agora.UserBranch, and for the same reason. Nobody holds a menu grant on the
 * day this ships, and a grant-list read literally would blank the navigation
 * for all 88 people at once. Absence of rows is therefore the grant, and the
 * screen says so in words.
 *
 * ADDITIVE ONLY. There is no deny row, for the reason v1__01d gives: a deny
 * that beats a role makes "what may this person see" a question you cannot
 * answer by reading their roles. Taking an item away from one person means
 * ticking the rest for them, which is what "the user has a tick for the view
 * or no access" describes.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * BranchId is the group entity on both, like every other grant table.
         * Which SITES a person may see is agora.UserBranch and a different
         * question; the menu is the same menu at every site.
         */
        MigrationHelper::table('RoleMenuItem', function (Blueprint $table) {
            $table->bigInteger('RoleId');
            $table->bigInteger('MenuItemId');
        });
        MigrationHelper::naturalKey('RoleMenuItem', ['BranchId', 'RoleId', 'MenuItemId']);

        MigrationHelper::table('UserMenuItem', function (Blueprint $table) {
            $table->bigInteger('UserId');
            $table->bigInteger('MenuItemId');
        });
        MigrationHelper::naturalKey('UserMenuItem', ['BranchId', 'UserId', 'MenuItemId']);

        MigrationHelper::recordVersion(
            '1.8',
            'agora.RoleMenuItem and agora.UserMenuItem: the menu ticked item by item, per role and per person.'
        );
    }

    public function down(): void
    {
        MigrationHelper::drop('UserMenuItem');
        MigrationHelper::drop('RoleMenuItem');
    }
};
