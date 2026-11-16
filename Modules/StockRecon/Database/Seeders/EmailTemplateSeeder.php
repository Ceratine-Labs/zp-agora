<?php

namespace Modules\StockRecon\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Email\Services\TemplateService;
use Modules\StockRecon\Email\VariableRegistry;

/** The stock recon mail templates (16 Nov 2026), editable afterwards under Email templates. */
final class EmailTemplateSeeder extends Seeder
{
    public int $seedOrder = 86;

    public function run(): void
    {
        foreach ((new VariableRegistry)->templates() as $definition) {
            app(TemplateService::class)->seed($definition);
        }
    }
}
