<?php

namespace App\Support\Seeding;

use Modules\Core\Models\SeedMaster;

/** One row of the catalog: a seeder class and where it sits in the run. */
class SeederEntry
{
    public function __construct(
        public readonly string $class,
        public readonly string $module,
        public readonly int $order,
        public readonly string $file,
    ) {}

    public function shortName(): string
    {
        return class_basename($this->class);
    }

    public function hasRun(): bool
    {
        return SeedMaster::hasRun($this->class);
    }

    public function lastRun(): ?SeedMaster
    {
        return SeedMaster::ledger()->where('SeederClass', $this->class)->first();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'short_name' => $this->shortName(),
            'module' => $this->module,
            'order' => $this->order,
            'has_run' => $this->hasRun(),
        ];
    }
}
