<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Point "Users and access" at the screen that now exists (T028).
 *
 * The menu is data, and MenuSeeder has already run everywhere — a seeder is a
 * one-shot, so changing what it seeded means writing another one. That is the
 * same rule migrations follow and it is why this file exists rather than an
 * edit to MenuSeeder.
 *
 * The item was seeded with no Route, which is what makes the shell render it
 * as inert with a "Not built yet" tooltip. It has carried a "Live" hint since
 * the menu was first seeded, which was optimistic: the hint said the data was
 * real before there was a page to show it on.
 */
class UsersMenuRouteSeeder extends Seeder
{
    public int $seedOrder = 46;

    public function run(): void
    {
        $branchId = (int) config('agora.group_branch_id');
        $schema = config('agora.schema');
        $connection = config('agora.connections.app');

        $wired = [
            'people-assets/users' => 'app.setup.users.index',
        ];

        $done = 0;

        foreach ($wired as $path => $route) {
            $done += DB::connection($connection)
                ->table($schema.'.MenuItem')
                ->where('BranchId', $branchId)
                ->where('Path', $path)
                ->whereNull('RouteName')
                ->update(['RouteName' => $route, 'UpdatedAt' => now()]);
        }

        $this->command?->info("Menu: {$done} item(s) pointed at a live route.");
    }
}
