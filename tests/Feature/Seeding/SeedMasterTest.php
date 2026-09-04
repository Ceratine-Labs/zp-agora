<?php

namespace Tests\Feature\Seeding;

use App\Support\Seeding\SeederCatalog;
use App\Support\Seeding\SeedRunner;
use Modules\Core\Models\SeedMaster;
use RuntimeException;
use Tests\Fixtures\Seeders\TestFailingSeeder;
use Tests\Fixtures\Seeders\TestFirstSeeder;
use Tests\Fixtures\Seeders\TestSecondSeeder;
use Tests\Fixtures\Seeders\TestState;
use Tests\Fixtures\Seeders\TestUnorderedSeeder;
use Tests\Fixtures\SeedersB\TestFirstSeeder as OtherFirstSeeder;
use Tests\TestCase;

/**
 * The seed master does one thing: a seeder whose class is in the ledger does
 * not run again. These pin that, the order the catalog hands seeders over in,
 * and what happens when one throws.
 *
 * The catalog under test is built over `tests/Fixtures/Seeders`, never the
 * application's own. A test that asserted on the real catalog would fail the
 * day someone adds a seeder, and would have to run real seeders against the
 * customer's database to prove anything.
 *
 * No RefreshDatabase: this connection is the customer's production database.
 * Every row written here belongs to the fixture module and tearDown removes it.
 */
class SeedMasterTest extends TestCase
{
    private const FIXTURE_MODULE = 'test';

    protected function setUp(): void
    {
        parent::setUp();

        TestState::reset();
        $this->clearLedger();
    }

    protected function tearDown(): void
    {
        $this->clearLedger();

        parent::tearDown();
    }

    private function clearLedger(): void
    {
        SeedMaster::ledger()->where('Module', self::FIXTURE_MODULE)->delete();
        SeedMaster::ledger()->where('SeederClass', 'like', 'TEST-%')->delete();
    }

    private function catalog(): SeederCatalog
    {
        return new SeederCatalog([self::FIXTURE_MODULE => base_path('tests/Fixtures/Seeders')]);
    }

    private function runner(): SeedRunner
    {
        return new SeedRunner($this->catalog());
    }

    /** @param  array{batch:int, ran:list<array<string, mixed>>}  $result */
    private function statusOf(array $result, string $class): ?string
    {
        foreach ($result['ran'] as $step) {
            if ($step['class'] === $class) {
                return $step['status'];
            }
        }

        return null;
    }

    // --------------------------------------------------------------- catalog

    public function test_the_catalog_finds_the_seeders_and_skips_helpers(): void
    {
        $classes = array_keys($this->catalog()->all());

        $this->assertContains(TestFirstSeeder::class, $classes);
        $this->assertNotContains(
            TestState::class,
            $classes,
            'TestState is not a Seeder subclass, so it has no business in the catalog.'
        );
    }

    public function test_seeders_come_back_in_declared_order(): void
    {
        $order = array_values(array_map(fn ($e) => $e->class, $this->catalog()->all()));

        $this->assertSame(
            [TestFirstSeeder::class, TestSecondSeeder::class, TestUnorderedSeeder::class, TestFailingSeeder::class],
            $order,
            'Order is the one thing a seeder declares, and RoleSeeder before UserSeeder depends on it.'
        );
    }

    public function test_an_undeclared_seeder_takes_the_default_order(): void
    {
        $this->assertSame(10, $this->catalog()->find(TestFirstSeeder::class)?->order);
        $this->assertSame(50, $this->catalog()->find(TestUnorderedSeeder::class)?->order);
    }

    // ---------------------------------------------------------------- runner

    public function test_running_twice_seeds_once_and_the_second_run_skips(): void
    {
        $only = [TestFirstSeeder::class, TestSecondSeeder::class];

        $first = $this->runner()->run(only: $only);
        $second = $this->runner()->run(only: $only);

        $this->assertSame('seeded', $this->statusOf($first, TestFirstSeeder::class));
        $this->assertSame('seeded', $this->statusOf($first, TestSecondSeeder::class));
        $this->assertSame('skipped', $this->statusOf($second, TestFirstSeeder::class));
        $this->assertSame('skipped', $this->statusOf($second, TestSecondSeeder::class));

        $this->assertSame(1, TestState::count(TestFirstSeeder::class), 'The second run must not invoke the seeder.');
        $this->assertSame(1, TestState::count(TestSecondSeeder::class));
    }

    public function test_the_ledger_records_the_class_the_module_and_the_batch(): void
    {
        $result = $this->runner()->run(only: [TestFirstSeeder::class]);

        $row = SeedMaster::ledger()->where('SeederClass', TestFirstSeeder::class)->firstOrFail();

        $this->assertSame(self::FIXTURE_MODULE, $row->Module);
        $this->assertSame($result['batch'], $row->Batch);
        $this->assertNotNull($row->ExecutedAt);
        $this->assertSame((int) config('agora.group_branch_id'), $row->BranchId);
    }

    public function test_a_seeder_that_throws_records_nothing_and_runs_again(): void
    {
        // The exception surfaces rather than being swallowed, which is what a
        // failed migration does — and the absence of a row is what makes the
        // retry automatic.
        try {
            $this->runner()->run(only: [TestFailingSeeder::class]);
            $this->fail('The failing seeder should have thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('on purpose', $e->getMessage());
        }

        $this->assertFalse(SeedMaster::hasRun(TestFailingSeeder::class));

        try {
            $this->runner()->run(only: [TestFailingSeeder::class]);
        } catch (RuntimeException) {
            // expected again
        }

        $this->assertSame(2, TestState::count(TestFailingSeeder::class));
    }

    public function test_forget_reopens_the_gate_without_touching_what_was_written(): void
    {
        $this->runner()->run(only: [TestFirstSeeder::class]);
        $this->assertTrue(SeedMaster::hasRun(TestFirstSeeder::class));

        $this->assertSame(1, SeedMaster::forget(TestFirstSeeder::class));
        $this->assertFalse(SeedMaster::hasRun(TestFirstSeeder::class));

        $this->runner()->run(only: [TestFirstSeeder::class]);
        $this->assertSame(2, TestState::count(TestFirstSeeder::class));
    }

    public function test_rolling_back_the_last_batch_reopens_everything_that_ran_together(): void
    {
        $result = $this->runner()->run(only: [TestFirstSeeder::class, TestSecondSeeder::class]);

        $this->assertSame(
            2,
            SeedMaster::ledger()->where('Module', self::FIXTURE_MODULE)->where('Batch', $result['batch'])->count(),
            'Seeders that ran together share a batch.'
        );

        SeedMaster::rollbackLastBatch();

        $this->assertFalse(SeedMaster::hasRun(TestFirstSeeder::class));
        $this->assertFalse(SeedMaster::hasRun(TestSecondSeeder::class));
    }

    // ------------------------------------------------------------- command

    public function test_an_ambiguous_short_name_is_refused_rather_than_guessed(): void
    {
        // Every module ships a MenuSeeder, so this is the normal case. Picking
        // whichever sorted first would mean --forget=MenuSeeder re-opening the
        // gate on a module nobody was touching.
        $this->app->bind(SeederCatalog::class, fn () => new SeederCatalog([
            self::FIXTURE_MODULE => base_path('tests/Fixtures/Seeders'),
            'other' => base_path('tests/Fixtures/SeedersB'),
        ]));

        $this->artisan('seed:master', ['--forget' => ['TestFirstSeeder']])
            ->expectsOutputToContain('Ambiguous seeder: TestFirstSeeder matches 2 seeders')
            ->expectsOutputToContain(TestFirstSeeder::class)
            ->expectsOutputToContain(OtherFirstSeeder::class)
            ->assertSuccessful();
    }

    public function test_a_fully_qualified_name_still_resolves_when_the_short_one_is_ambiguous(): void
    {
        $this->app->bind(SeederCatalog::class, fn () => new SeederCatalog([
            self::FIXTURE_MODULE => base_path('tests/Fixtures/Seeders'),
            'other' => base_path('tests/Fixtures/SeedersB'),
        ]));

        $this->artisan('seed:master', ['--forget' => [TestFirstSeeder::class]])
            ->doesntExpectOutputToContain('Ambiguous')
            ->assertSuccessful();
    }

    public function test_pending_lists_only_what_has_not_run(): void
    {
        $this->runner()->run(only: [TestFirstSeeder::class]);

        $pending = array_keys($this->catalog()->pending());

        $this->assertNotContains(TestFirstSeeder::class, $pending);
        $this->assertContains(TestSecondSeeder::class, $pending);
    }
}
