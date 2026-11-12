<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Email\VariableRegistry;
use Modules\Email\Services\TemplateService;

/** Module-owned defaults; later copy changes require a new ledger seeder. */
final class EmailTemplateSeeder extends Seeder
{
    public int $seedOrder = 85;

    public function run(): void
    {
        foreach ((new VariableRegistry)->templates() as $definition) {
            app(TemplateService::class)->seed($definition);
        }
    }
}
