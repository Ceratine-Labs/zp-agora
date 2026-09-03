<?php

namespace App\Support\Modules;

use RuntimeException;

/**
 * Finds the modules under /Modules and puts them in boot order.
 *
 * Discovery is a directory scan for module.json. It is cached to a PHP file in
 * production (one require instead of 20 stats and 20 json_decodes per request)
 * and re-scanned every time locally, so a module you have just created appears
 * without remembering to clear anything.
 *
 * Order is `order` from the manifest, then `requires`: a module always boots
 * after the modules it names. A cycle is thrown rather than resolved, because
 * a cycle in module dependencies is a design mistake and quietly picking an
 * order hides it.
 */
class ModuleManager
{
    /** @var array<string, Module>|null */
    protected ?array $modules = null;

    public function __construct(protected string $path, protected ?string $cacheFile = null) {}

    /** @return array<string, Module> */
    public function all(): array
    {
        return $this->modules ??= $this->sort($this->discover());
    }

    public function get(string $name): ?Module
    {
        return $this->all()[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    /** @return array<string, Module> */
    public function enabled(): array
    {
        return array_filter($this->all(), fn (Module $m) => $m->enabled);
    }

    /** @return array<int, string> */
    public function names(): array
    {
        return array_keys($this->all());
    }

    public function forget(): void
    {
        $this->modules = null;
    }

    /** @return array<string, Module> */
    protected function discover(): array
    {
        if ($this->cacheFile && ! app()->environment('local', 'testing') && file_exists($this->cacheFile)) {
            $cached = require $this->cacheFile;

            return array_map(fn (array $m) => Module::fromManifest($m['path'], $m['manifest']), $cached);
        }

        if (! is_dir($this->path)) {
            return [];
        }

        $found = [];

        foreach (glob($this->path.'/*/module.json') ?: [] as $manifestPath) {
            $manifest = json_decode(file_get_contents($manifestPath), true);

            if (! is_array($manifest)) {
                throw new RuntimeException("Module manifest is not valid JSON: {$manifestPath}");
            }

            $module = Module::fromManifest(dirname($manifestPath), $manifest);
            $found[$module->name] = $module;
        }

        return $found;
    }

    /**
     * @param  array<string, Module>  $modules
     * @return array<string, Module>
     */
    protected function sort(array $modules): array
    {
        uasort($modules, fn (Module $a, Module $b) => [$a->order, $a->name] <=> [$b->order, $b->name]);

        $sorted = [];
        $visiting = [];

        $visit = function (Module $module) use (&$visit, &$sorted, &$visiting, $modules): void {
            if (isset($sorted[$module->name])) {
                return;
            }

            if (isset($visiting[$module->name])) {
                throw new RuntimeException(
                    'Module dependency cycle through '.$module->name.'. Break the cycle by firing an event instead of requiring the other module (plan §3.2).'
                );
            }

            $visiting[$module->name] = true;

            foreach ($module->requires as $dependency) {
                if (! isset($modules[$dependency])) {
                    throw new RuntimeException("Module {$module->name} requires {$dependency}, which is not installed.");
                }

                $visit($modules[$dependency]);
            }

            unset($visiting[$module->name]);
            $sorted[$module->name] = $module;
        };

        foreach ($modules as $module) {
            $visit($module);
        }

        return $sorted;
    }
}
