<?php

namespace App\Support\Seeding;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Seeder;
use ReflectionClass;

/**
 * Every seeder in the application, in the order they run.
 *
 * Discovery is filesystem-driven — `database/seeders` plus every module's
 * `Database/Seeders` — so a new seeder is picked up without anyone maintaining
 * a list. The failure mode of a hand-written manifest is that the seeder
 * someone forgets to add is the one that never runs on the customer's
 * database, and nothing locally shows it.
 *
 * Order is the one thing a seeder still has to say about itself. A migration
 * gets its order from its filename; a seeder declares `public int $seedOrder`,
 * default 50, and ties break on module then class name. It exists because the
 * dependencies are real — UserSeeder looks the admin role up by code, so
 * RoleSeeder has to have run — and because that fact belongs next to the
 * lookup rather than in a list somewhere else.
 */
class SeederCatalog
{
    /**
     * Orchestrators, not data seeders. `DatabaseSeeder` delegates to the
     * runner, so leaving it in the catalog would make the runner call itself.
     *
     * @var list<class-string>
     */
    private const EXCLUDED = [
        DatabaseSeeder::class,
    ];

    /** @var array<string, SeederEntry>|null */
    private ?array $cache = null;

    /**
     * @param  array<string, string>|null  $groups  module => directory. Null discovers
     *                                              the real tree; tests pass a fixture
     *                                              directory so the catalog under test is
     *                                              not the application's own.
     */
    public function __construct(private readonly ?array $groups = null) {}

    /**
     * Every discovered seeder, keyed by class name, in run order.
     *
     * @return array<string, SeederEntry>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $entries = [];

        foreach ($this->directories() as $module => $dir) {
            foreach ($this->phpFilesUnder($dir) as $file) {
                $class = $this->classFor($file);

                if ($class === null || in_array($class, self::EXCLUDED, true) || ! $this->isRunnableSeeder($class)) {
                    continue;
                }

                $declared = (new ReflectionClass($class))->getDefaultProperties();

                $entries[$class] = new SeederEntry(
                    class: $class,
                    module: $module,
                    order: (int) ($declared['seedOrder'] ?? 50),
                    file: $file,
                );
            }
        }

        uasort($entries, fn (SeederEntry $a, SeederEntry $b) => [$a->order, $a->module, $a->shortName()]
            <=> [$b->order, $b->module, $b->shortName()]);

        return $this->cache = $entries;
    }

    public function find(string $class): ?SeederEntry
    {
        return $this->all()[ltrim($class, '\\')] ?? null;
    }

    /**
     * Seeders that have never run here.
     *
     * @return array<string, SeederEntry>
     */
    public function pending(): array
    {
        return array_filter($this->all(), fn (SeederEntry $e) => ! $e->hasRun());
    }

    /**
     * Seeder directories, keyed by the module that owns them. `app` covers
     * `database/seeders`, which holds the orchestrator and little else —
     * modules carry their own.
     *
     * @return array<string, string>
     */
    private function directories(): array
    {
        if ($this->groups !== null) {
            return $this->groups;
        }

        $groups = ['app' => database_path('seeders')];

        foreach (glob(config('agora.modules_path').'/*', GLOB_ONLYDIR) ?: [] as $moduleDir) {
            $dir = $moduleDir.'/Database/Seeders';

            if (is_dir($dir)) {
                $groups[strtolower(basename($moduleDir))] = $dir;
            }
        }

        return array_filter($groups, 'is_dir');
    }

    /** @return list<string> */
    private function phpFilesUnder(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Resolve a file to its class by reading the namespace and class it
     * declares, rather than deriving one from the path. A silent miss here is
     * a seeder that never appears in the catalog at all.
     */
    private function classFor(string $file): ?string
    {
        $src = (string) file_get_contents($file);

        if (! preg_match('/^\s*namespace\s+([^;]+);/m', $src, $ns)) {
            return null;
        }

        if (! preg_match('/^\s*(?:final\s+|abstract\s+)*class\s+(\w+)/m', $src, $cls)) {
            return null;
        }

        $class = trim($ns[1]).'\\'.$cls[1];

        return class_exists($class) ? $class : null;
    }

    private function isRunnableSeeder(string $class): bool
    {
        $ref = new ReflectionClass($class);

        return $ref->isSubclassOf(Seeder::class) && ! $ref->isAbstract();
    }
}
