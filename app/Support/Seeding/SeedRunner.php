<?php

namespace App\Support\Seeding;

use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Modules\Core\Models\SeedMaster;

/**
 * Runs the catalog through the {@see SeedMaster} ledger.
 *
 * One rule: a seeder whose class is in the ledger is skipped without being
 * invoked, and one that is not runs and is then recorded. Recording happens
 * after the seeder returns, so a seeder that throws leaves no row — the
 * exception surfaces and the next run tries it again, exactly as a failed
 * migration does.
 */
class SeedRunner
{
    public function __construct(private readonly SeederCatalog $catalog) {}

    /**
     * Run every seeder that has not run here.
     *
     * @param  list<string>|null  $only  Restrict to these classes; null means the whole catalog.
     * @param  (callable(array<string, mixed>): void)|null  $onProgress
     * @return array{batch:int, ran:list<array<string, mixed>>}
     */
    public function run(?array $only = null, ?Command $command = null, ?callable $onProgress = null): array
    {
        $batch = SeedMaster::nextBatch();
        $only = $only === null ? null : array_map(fn (string $c) => ltrim($c, '\\'), $only);
        $ran = [];

        foreach ($this->catalog->all() as $class => $entry) {
            if ($only !== null && ! in_array($class, $only, true)) {
                continue;
            }

            if ($entry->hasRun()) {
                $ran[] = $step = $this->note($entry, 'skipped');
                $onProgress && $onProgress($step);

                continue;
            }

            $started = microtime(true);
            $this->invoke($entry, $command);
            SeedMaster::record($entry->class, $entry->module, $batch);

            $ran[] = $step = $this->note($entry, 'seeded', (int) ((microtime(true) - $started) * 1000));
            $onProgress && $onProgress($step);
        }

        return ['batch' => $batch, 'ran' => $ran];
    }

    /**
     * Execute one seeder, with the calling console attached so whatever it
     * reports — including the warnings that mean it only half-worked — reaches
     * the person who asked for the run.
     */
    private function invoke(SeederEntry $entry, ?Command $command): void
    {
        /** @var Seeder $seeder */
        $seeder = app($entry->class);
        $seeder->setContainer(app());

        if ($command !== null) {
            $seeder->setCommand($command);
        }

        $seeder->__invoke();
    }

    /** @return array<string, mixed> */
    private function note(SeederEntry $entry, string $status, ?int $ms = null): array
    {
        return [
            'seeder' => $entry->shortName(),
            'class' => $entry->class,
            'module' => $entry->module,
            'status' => $status,
            'duration_ms' => $ms,
        ];
    }
}
