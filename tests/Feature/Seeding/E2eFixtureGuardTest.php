<?php

namespace Tests\Feature\Seeding;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Database\Seeders\E2eFixtureSeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\User;
use PHPUnit\Framework\SkippedWithMessageException;
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
        config(["database.connections.{$connection}.host" => '192.0.2.1']);

        // No database setup, cleanup or connection is permitted for this case.
        // A connection expectation catches even an attempted query, unlike a
        // later row count on an already-open connection to the local database.
        DB::shouldReceive('connection')->never();
        $command = $this->createMock(Command::class);
        $command->expects($this->once())->method('warn')->with($this->callback(
            fn (string $message): bool => str_contains($message, 'not a local database — skipped')
        ));
        $seeder = new E2eFixtureSeeder;
        $seeder->setCommand($command);
        $seeder->run();
    }

    public function test_the_writing_case_skips_before_connecting_to_a_nonlocal_database(): void
    {
        $connection = config('agora.connections.app');
        config(["database.connections.{$connection}.host" => '192.0.2.1']);
        DB::shouldReceive('connection')->never();

        // Exercise the actual writing test's entry point. Moving its guard
        // below setup would attempt a connection and fail this expectation.
        $this->expectException(SkippedWithMessageException::class);
        $this->expectExceptionMessage('local container only');
        $this->test_it_still_seeds_against_a_local_database();
    }

    public function test_it_still_seeds_against_a_local_database(): void
    {
        $this->skipUnlessLocalStub('This test runs the E2E fixture seeder.');

        // Keep the browser fixture intact, including its existing password and
        // grants. The real seeder runs with a separate TEST account inside a
        // rollback transaction; no reseeding is needed in tearDown, even after
        // an assertion fails or the local-only guard skips this case.
        $email = 'TEST-fixture-guard-'.Str::uuid().'@agora.invalid';
        $branchId = 999;
        config([
            'agora.e2e.branch_id' => $branchId,
            'agora.e2e.email' => $email,
            'agora.e2e.password' => 'TEST-fixture-guard-password',
        ]);
        $branchBefore = Branch::query()->acrossBranches()->withTrashed()
            ->where('BranchId', $branchId)->first()?->getAttributes();
        $db = DB::connection(config('agora.connections.app'));
        $db->beginTransaction();

        try {
            (new E2eFixtureSeeder)->run();

            $user = User::query()->acrossBranches()->where('EmailAddress', $email)->sole();
            $this->assertSame('TEST Playwright', $user->UserName);
            $this->assertTrue((bool) $user->IsActive);
            $branch = Branch::query()->acrossBranches()->where('BranchId', $branchId)->sole();
            $this->assertSame(E2eFixtureSeeder::BRANCH_NAME, $branch->Name);
            $this->assertFalse((bool) $branch->IsActive);
            $this->assertFalse((bool) $branch->IsTrading);
        } finally {
            $db->rollBack();
        }

        $this->assertFalse(User::query()->acrossBranches()->where('EmailAddress', $email)->withTrashed()->exists());
        $this->assertSame($branchBefore, Branch::query()->acrossBranches()->withTrashed()
            ->where('BranchId', $branchId)->first()?->getAttributes());
    }
}
