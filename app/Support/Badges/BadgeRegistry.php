<?php

namespace App\Support\Badges;

use App\Support\BranchContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Every live count in the system, addressed by key.
 *
 * One registry rather than a provider looked up by class name at each call
 * site, for three reasons:
 *
 *  - **A module can replace a figure without anyone editing the surface that
 *    shows it.** The sign-in panel asks for `exceptions.open`; Core answers it
 *    with a placeholder today and the Exceptions module answers it for real
 *    the day it registers under the same key. Neither the panel nor Core
 *    changes.
 *  - **The 60-second cache lives in one place** (plan §3.10). The sign-in
 *    screen is anonymous and the bell polls; without a cache both would run
 *    four counts per page view against the customer's database.
 *  - **A provider cannot break the page.** Everything is wrapped: a throw is
 *    logged once and comes back as "not known". The alternative is a
 *    half-deployed module taking down the screen people sign in on.
 *
 * The cache key carries the branch scope, because these are branch-scoped
 * counts — caching "exceptions open" without the branch would show head office
 * a site's number for up to a minute.
 */
class BadgeRegistry
{
    /** Plan §3.10 says 60 seconds, and a figure a minute stale is a figure, not a lie. */
    public const TTL = 60;

    /** @var array<string, BadgeProvider> */
    protected array $providers = [];

    /**
     * Register a provider, or replace the one holding its key.
     *
     * Last registration wins on purpose: Core seeds a placeholder for each of
     * the four sign-in figures, and the module that owns one takes it over by
     * registering the same key. Module providers boot after Core's, which is
     * the order that makes this work.
     *
     * @param  BadgeProvider|class-string<BadgeProvider>  $provider
     */
    public function register(BadgeProvider|string $provider): self
    {
        $instance = is_string($provider) ? $this->instantiate($provider) : $provider;

        $this->providers[$instance->key()] = $instance;

        return $this;
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->providers);
    }

    /**
     * One badge, by key or by provider class name.
     *
     * The class name form is what `agora.MenuItem.BadgeProvider` holds — the
     * column stores an FQCN, and a menu row must not have to know the dotted
     * key its provider chose.
     */
    public function badge(string $keyOrClass, ?BranchContext $context = null): ?Badge
    {
        $provider = $this->resolve($keyOrClass);

        if (! $provider) {
            return null;
        }

        $context ??= app(BranchContext::class);

        try {
            return Cache::remember(
                $this->cacheKey($provider->key(), $context),
                self::TTL,
                fn () => $provider->badge($context),
            );
        } catch (Throwable $e) {
            // Once, with the key, and then out of the way. A figure that
            // cannot be counted is not a reason for a person to see a 500 on
            // the page they sign in on.
            Log::warning('agora.badge.failed', [
                'key' => $provider->key(),
                'message' => $e->getMessage(),
            ]);

            return $provider->unknown();
        }
    }

    /**
     * Several, in the order asked for. An unknown key is skipped rather than
     * throwing: a surface naming a figure whose module is not installed should
     * render one row short, not fail.
     *
     * @param  array<int, string>  $keys
     * @return array<int, Badge>
     */
    public function badges(array $keys, ?BranchContext $context = null): array
    {
        $context ??= app(BranchContext::class);

        return array_values(array_filter(
            array_map(fn (string $key) => $this->badge($key, $context), $keys)
        ));
    }

    /** Drop a key's cached value — for a test, or for a screen that just changed the figure. */
    public function forget(string $key, ?BranchContext $context = null): void
    {
        $provider = $this->resolve($key);

        if ($provider) {
            Cache::forget($this->cacheKey($provider->key(), $context ?? app(BranchContext::class)));
        }
    }

    protected function resolve(string $keyOrClass): ?BadgeProvider
    {
        if (isset($this->providers[$keyOrClass])) {
            return $this->providers[$keyOrClass];
        }

        foreach ($this->providers as $provider) {
            if ($provider::class === $keyOrClass) {
                return $provider;
            }
        }

        // A class named by a menu row but never registered is still usable —
        // registration is how a module OVERRIDES a key, not a precondition for
        // being callable.
        if (is_a($keyOrClass, BadgeProvider::class, true)) {
            return $this->instantiate($keyOrClass);
        }

        return null;
    }

    /** @param  class-string<BadgeProvider>|string  $class */
    protected function instantiate(string $class): BadgeProvider
    {
        if (! is_a($class, BadgeProvider::class, true)) {
            throw new InvalidArgumentException("[{$class}] is not a ".BadgeProvider::class.'.');
        }

        /** @var BadgeProvider */
        return app($class);
    }

    protected function cacheKey(string $key, BranchContext $context): string
    {
        return 'agora.badge.'.$key.'.'.$context->workspace().'.'.($context->id() ?? 'all');
    }
}
