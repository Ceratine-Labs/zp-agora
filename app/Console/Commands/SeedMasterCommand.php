<?php

namespace App\Console\Commands;

use App\Support\Seeding\SeederCatalog;
use App\Support\Seeding\SeedRunner;
use Illuminate\Console\Command;
use Modules\Core\Models\SeedMaster;

/**
 * The seed master, from the command line.
 *
 *   php artisan seed:master --status     # which seeders have run here, and which have not
 *   php artisan seed:master              # run the ones that have not
 *   php artisan seed:master --only=RoleSeeder
 *   php artisan seed:master --forget=MenuSeeder    # reopen the gate; does not undo what it wrote
 *
 * `db:seed` runs the same thing through DatabaseSeeder, so the two cannot
 * disagree about what has been seeded.
 */
class SeedMasterCommand extends Command
{
    protected $signature = 'seed:master
        {--status : Show every seeder and whether it has run here, without running anything}
        {--only=* : Restrict the run to these seeders (short or fully-qualified class name)}
        {--forget=* : Clear ledger rows for these seeders so they run again}
        {--rollback-batch : Clear the most recent batch\'s ledger rows}';

    protected $description = 'Run each seeder once, recorded in the agora.SeedMaster ledger';

    public function handle(SeederCatalog $catalog, SeedRunner $runner): int
    {
        if ($this->option('rollback-batch')) {
            $n = SeedMaster::rollbackLastBatch();
            $this->info("Cleared {$n} ledger row(s) from the last batch. What they wrote is untouched.");

            return self::SUCCESS;
        }

        /** @var list<string> $forget */
        $forget = $this->option('forget');

        if ($forget !== []) {
            foreach ($this->resolveClasses($forget, $catalog) as $class) {
                $n = SeedMaster::forget($class);
                $this->info("Forgot {$n} ledger row(s) for ".class_basename($class).'.');
            }

            return self::SUCCESS;
        }

        if ($this->option('status')) {
            return $this->showStatus($catalog);
        }

        /** @var list<string> $onlyOption */
        $onlyOption = $this->option('only');
        $only = $onlyOption === [] ? null : $this->resolveClasses($onlyOption, $catalog);

        $result = $runner->run(
            only: $only,
            command: $this,
            onProgress: function (array $step): void {
                $this->line(sprintf(
                    '  %-8s %-12s %-30s %s',
                    strtoupper((string) $step['status']),
                    $step['module'],
                    $step['seeder'],
                    $step['duration_ms'] === null ? '' : $step['duration_ms'].' ms',
                ));
            },
        );

        $counts = array_count_values(array_column($result['ran'], 'status'));

        $this->newLine();
        $this->info(sprintf(
            'Batch %d: %d seeded, %d already recorded.',
            $result['batch'],
            $counts['seeded'] ?? 0,
            $counts['skipped'] ?? 0,
        ));

        return self::SUCCESS;
    }

    private function showStatus(SeederCatalog $catalog): int
    {
        $rows = [];

        foreach ($catalog->all() as $entry) {
            $last = $entry->lastRun();

            $rows[] = [
                $entry->order,
                $entry->module,
                $entry->shortName(),
                $last === null ? 'PENDING' : 'seeded',
                $last === null ? '' : ($last->ExecutedAt?->format('Y-m-d H:i') ?? ''),
                $last === null ? '' : (string) $last->Batch,
            ];
        }

        $this->table(['Order', 'Module', 'Seeder', 'State', 'Ran at', 'Batch'], $rows);

        $this->info(sprintf(
            '%d seeders known, %d pending.',
            count($catalog->all()),
            count($catalog->pending()),
        ));

        return self::SUCCESS;
    }

    /**
     * Accept a short class name or a fully-qualified one, so the command is
     * usable without typing namespaces.
     *
     * A short name that matches more than one seeder is REFUSED rather than
     * resolved to whichever sorted first. Every module ships a `MenuSeeder` by
     * convention, so this is the normal case and not a corner: silently
     * picking one would mean `--forget=MenuSeeder` re-opening the gate on a
     * module nobody was touching.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function resolveClasses(array $names, SeederCatalog $catalog): array
    {
        $resolved = [];

        foreach ($names as $name) {
            $name = ltrim($name, '\\');

            if ($exact = $catalog->find($name)) {
                $resolved[] = $exact->class;

                continue;
            }

            $candidates = array_values(array_filter(
                $catalog->all(),
                fn ($e) => strcasecmp($e->shortName(), $name) === 0,
            ));

            if ($candidates === []) {
                $this->warn("Unknown seeder: {$name} — skipped.");

                continue;
            }

            if (count($candidates) > 1) {
                $this->warn("Ambiguous seeder: {$name} matches ".count($candidates).' seeders — skipped. Name the module:');

                foreach ($candidates as $candidate) {
                    $this->line("    {$candidate->class}");
                }

                continue;
            }

            $resolved[] = $candidates[0]->class;
        }

        return $resolved;
    }
}
