<?php

namespace Database\Seeders;

use App\Support\Seeding\SeedRunner;
use Illuminate\Database\Seeder;

/**
 * The only orchestrator, and it names nothing.
 *
 * Each seeder declares where it sits with `$seedOrder`; the catalog finds them
 * all and the runner takes them in that order, skipping whatever the ledger
 * already records. A list here would be one more thing to keep in step with
 * the modules, and the seeder someone forgets to add is the one that never
 * runs on the customer's database.
 *
 * `db:seed` and `seed:master` are therefore the same run, against the same
 * ledger, with no way for the two to disagree.
 */
class DatabaseSeeder extends Seeder
{
    public function run(SeedRunner $runner): void
    {
        $result = $runner->run(command: $this->command, onProgress: function (array $step): void {
            $this->command?->line(sprintf(
                '  %-8s %s',
                strtoupper((string) $step['status']),
                $step['seeder'],
            ));
        });

        $counts = array_count_values(array_column($result['ran'], 'status'));

        $this->command?->info(sprintf(
            'Batch %d: %d seeded, %d already recorded.',
            $result['batch'],
            $counts['seeded'] ?? 0,
            $counts['skipped'] ?? 0,
        ));
    }
}
