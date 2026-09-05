<?php

namespace App\Grid;

/**
 * Every grid in the system, by its GridKey.
 *
 * The register is `config/grids.php`, so a module adds a grid by adding a line
 * to a config file rather than by being discovered through a scan. That is
 * deliberate: the key is the route name, the class is somewhere in a module,
 * and the pairing is the thing worth being able to read on one screen — it is
 * also what lets `agora.UserGridColumn` be audited against a list of keys that
 * are supposed to exist.
 *
 * Definitions are resolved through the container and memoised for the request:
 * a grid's catalogue is asked for a dozen times while a page renders (the
 * header, the filters, the chooser, the cards, the export links) and building
 * it once is the difference between cheap and noticeable.
 */
final class GridRegistry
{
    /** @var array<string, GridDefinition> */
    private array $resolved = [];

    /** @return array<string, class-string<GridDefinition>> */
    public function all(): array
    {
        /** @var array<string, class-string<GridDefinition>> $grids */
        $grids = config('grids.grids', []);

        return $grids;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function find(string $key): ?GridDefinition
    {
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $class = $this->all()[$key] ?? null;

        if ($class === null || ! class_exists($class)) {
            return null;
        }

        $definition = app($class);

        if (! $definition instanceof GridDefinition) {
            throw new \LogicException("[{$class}] is registered as a grid but is not a GridDefinition.");
        }

        // The register and the definition must agree, or a saved layout is
        // written under one key and read under another and the user's columns
        // quietly stop persisting. Caught here rather than in a browser.
        if ($definition->key() !== $key) {
            throw new \LogicException(
                "Grid [{$class}] is registered as [{$key}] but calls itself [{$definition->key()}]. "
                .'The GridKey is what agora.UserGridColumn stores; the two must be the same string.'
            );
        }

        return $this->resolved[$key] = $definition;
    }

    public function findOrFail(string $key): GridDefinition
    {
        return $this->find($key) ?? throw new GridNotFound($key);
    }
}
