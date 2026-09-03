<?php

namespace App\Support\Modules;

/**
 * One discovered module, as described by its module.json.
 *
 * A value object rather than an array so a typo in a key is a method-not-found
 * at the call site instead of a silent null three layers away.
 */
class Module
{
    /**
     * @param  array<int, string>  $providers
     * @param  array<int, string>  $requires
     */
    public function __construct(
        public readonly string $name,
        public readonly string $alias,
        public readonly string $path,
        public readonly string $version,
        public readonly array $providers,
        public readonly array $requires,
        public readonly bool $enabled,
        public readonly int $order,
    ) {}

    /** @param  array<string, mixed>  $manifest */
    public static function fromManifest(string $path, array $manifest): self
    {
        $name = $manifest['name'] ?? basename($path);

        return new self(
            name: $name,
            alias: $manifest['alias'] ?? strtolower($name),
            path: $path,
            version: $manifest['version'] ?? '1.0.0',
            providers: $manifest['providers'] ?? ["Modules\\{$name}\\Providers\\{$name}ServiceProvider"],
            requires: $manifest['requires'] ?? [],
            enabled: $manifest['enabled'] ?? true,
            order: $manifest['order'] ?? 50,
        );
    }

    public function path(string $sub = ''): string
    {
        return rtrim($this->path.'/'.ltrim($sub, '/'), '/');
    }

    public function has(string $sub): bool
    {
        return file_exists($this->path($sub));
    }
}
