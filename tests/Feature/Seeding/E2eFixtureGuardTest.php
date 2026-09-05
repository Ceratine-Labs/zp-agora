<?php

namespace Tests\Feature\Seeding;

use Modules\Core\Database\Seeders\E2eFixtureSeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * The fixture seeder must not write to a database that is not local.
 *
 * Agora got a database of its own on the customer's instance on 4 September
 * 2026, which made it possible to point a LOCAL checkout — APP_ENV=local,
 * APP_DEBUG=true, everything about it saying "development" — straight at
 * Zululand Petroleum's production server. At that moment the seeder's
 * APP_ENV guard stopped meaning anything, and what it creates is a working
 * sign-in credential and a branch that does not exist.
 *
 * This is a guard test, not a feature test: it asserts that nothing happens.
 */
class E2eFixtureGuardTest extends TestCase
{
    public function test_it_skips_when_the_app_connection_is_not_local(): void
    {
        $connection = config('agora.connections.app');

        $branchId = (int) config('agora.e2e.branch_id');
        $email = config('agora.e2e.email');

        // A known starting point: "it did nothing" can only be shown against
        // an empty one. forceDelete on both — Branch soft-deletes, and a
        // soft-deleted row still occupies its natural key, so a plain delete
        // here would leave the row in the unique index and break the seeder
        // the next time it ran.
        Branch::query()->acrossBranches()->withTrashed()->where('BranchId', $branchId)->forceDelete();
        User::query()->acrossBranches()->withTrashed()->where('EmailAddress', $email)->forceDelete();

        config(["database.connections.{$connection}.host" => '105.247.172.179']);

        (new E2eFixtureSeeder)->run();

        // The guard reads config before any query, so nothing was sent
        // anywhere — including to the local database this test can see.
        config(["database.connections.{$connection}.host" => '127.0.0.1']);

        $this->assertSame(
            0,
            Branch::query()->acrossBranches()->where('BranchId', $branchId)->count(),
            'A remote target must not get the fixture branch.'
        );
        $this->assertSame(
            0,
            User::query()->acrossBranches()->where('EmailAddress', $email)->withTrashed()->count(),
            'A remote target must not get a working sign-in credential.'
        );
    }

    public function test_it_still_seeds_against_a_local_database(): void
    {
        if (! config('agora.e2e.password')) {
            $this->markTestSkipped('AGORA_E2E_PASSWORD is not set, so there is no fixture to seed.');
        }

        (new E2eFixtureSeeder)->run();

        $this->assertSame(
            1,
            User::query()->acrossBranches()->where('EmailAddress', config('agora.e2e.email'))->count()
        );
    }

    /**
     * Put the fixture back however this file left it.
     *
     * The browser suite signs in as this account, so a run of these two tests
     * must not be what takes it away.
     */
    protected function tearDown(): void
    {
        if ($this->app !== null && config('agora.e2e.password')) {
            (new E2eFixtureSeeder)->run();
        }

        parent::tearDown();
    }
}
