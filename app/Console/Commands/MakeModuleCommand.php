<?php

namespace App\Console\Commands;

use App\Support\Modules\ModuleManager;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Scaffolds a module in the §3.2 shape.
 *
 * The shape is not negotiable per-module, so it is generated rather than
 * described in a doc that drifts. Every stub it writes is a file the module
 * actually needs — there are no placeholder directories, because an empty
 * Services/ folder tells the next person nothing.
 *
 * The migration slot (`NN`) is the module's position in domain order, from
 * plan §3.3. It is asked for rather than guessed: the number decides what
 * runs before what against a production database, and a wrong guess is only
 * visible when a foreign key fails weeks later.
 */
class MakeModuleCommand extends Command
{
    protected $signature = 'agora:make-module
        {name : PascalCase module name, e.g. StockLedger}
        {--slot= : Two-digit migration slot from plan §3.3 (Core 01, Masters 02, DayEnd 10, ...)}
        {--order=50 : Boot order; lower boots first}
        {--requires= : Comma-separated modules this one must boot after}';

    protected $description = 'Scaffold a new HMVC module under /Modules';

    public function handle(Filesystem $files, ModuleManager $modules): int
    {
        $name = str($this->argument('name'))->studly()->value();
        $alias = strtolower($name);
        $slot = $this->option('slot') ?: $this->ask('Migration slot (two digits, plan §3.3 domain order)', '50');
        $slot = str_pad((string) (int) $slot, 2, '0', STR_PAD_LEFT);
        $requires = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('requires')))));

        $base = config('agora.modules_path').'/'.$name;

        if ($files->isDirectory($base)) {
            $this->components->error("Modules/{$name} already exists.");

            return self::FAILURE;
        }

        foreach ([
            'Config', 'Database/Migrations', 'Database/Seeders', 'Http/Controllers',
            'Http/Requests', 'Models', 'Services', 'Providers', 'Routes',
            'Resources/views', 'Resources/views/components', 'Resources/lang/en',
        ] as $dir) {
            $files->makeDirectory("{$base}/{$dir}", 0755, true);
        }

        $replace = [
            '{{name}}' => $name,
            '{{alias}}' => $alias,
            '{{slot}}' => $slot,
            '{{order}}' => (string) (int) $this->option('order'),
            '{{requires}}' => json_encode($requires),
            '{{lowerName}}' => str($name)->kebab()->value(),
        ];

        $written = [];

        foreach ($this->files() as $stub => $target) {
            $contents = strtr($files->get(base_path("stubs/module/{$stub}")), $replace);
            $path = $base.'/'.strtr($target, $replace);
            $files->put($path, $contents);
            $written[] = str_replace(base_path().'/', '', $path);
        }

        $modules->forget();

        $this->components->info("Module {$name} created.");
        foreach ($written as $path) {
            $this->line("  <fg=gray>{$path}</>");
        }
        $this->line('');
        $this->components->twoColumnDetail('routes', "/app/{$replace['{{lowerName}}']}");
        $this->components->twoColumnDetail('views', "view('{$alias}::index')");
        $this->components->twoColumnDetail('migration', "v1__{$slot}_{$alias}_tables.php");

        return self::SUCCESS;
    }

    /** @return array<string, string> stub file => destination path within the module */
    protected function files(): array
    {
        return [
            'module.json.stub' => 'module.json',
            'config.php.stub' => 'Config/config.php',
            'provider.php.stub' => 'Providers/{{name}}ServiceProvider.php',
            'routes-web.php.stub' => 'Routes/web.php',
            'routes-api.php.stub' => 'Routes/api.php',
            'controller.php.stub' => 'Http/Controllers/{{name}}Controller.php',
            'service.php.stub' => 'Services/{{name}}Service.php',
            'migration.php.stub' => 'Database/Migrations/v1__{{slot}}_{{alias}}_tables.php',
            'menu-seeder.php.stub' => 'Database/Seeders/MenuSeeder.php',
            'view.blade.php.stub' => 'Resources/views/index.blade.php',
            'lang.php.stub' => 'Resources/lang/en/{{alias}}.php',
        ];
    }
}
