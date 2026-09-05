<?php

namespace App\Support\Badges;

use App\Support\BranchContext;

/**
 * One live count, wherever it is shown.
 *
 * Plan §3.10 gives the contract as `count(BranchContext): ?string` with a
 * 60-second cache. That is what a menu item wants; the bell and the sign-in
 * panel want a sentence and a tone as well, so the count stays the one thing a
 * subclass MUST write and the rest hangs off it. A provider is therefore three
 * short methods, not a class with a render in it.
 *
 * **Adding one from another module takes no change to this file or to Core.**
 * Extend this class, then in that module's service provider:
 *
 *     $this->app->make(BadgeRegistry::class)->register(OpenExceptionsBadge::class);
 *
 * Registering under a key Core already holds REPLACES it, which is how the
 * placeholders on the sign-in page get their real answers as each module
 * lands. The `agora.MenuItem.BadgeProvider` column holds a class name for the
 * same reason — the registry resolves an FQCN as readily as a key.
 *
 * Two rules the registry enforces so a provider does not have to:
 *
 *  - **It is cached for 60 seconds**, per key and per branch scope.
 *  - **It may not break the page.** A provider that throws is logged and
 *    treated as "nothing known". The sign-in screen is anonymous and reachable
 *    by everyone; a query against a module that is half-deployed must not be
 *    able to 500 it.
 *
 * `count()` may therefore be written as if the source is present. If it is
 * not, throw or return null — both come out as an em dash.
 */
abstract class BadgeProvider
{
    /** Stable, dotted, and the thing a later module registers over: 'exceptions.open'. */
    abstract public function key(): string;

    /**
     * The figure, for this branch scope. NULL means not known — which is not
     * the same as zero, and is rendered differently.
     */
    abstract public function count(BranchContext $context): ?int;

    /** The sentence a person reads: '12 exceptions open', 'All Z-reads allocated'. */
    abstract public function label(?int $count): string;

    /** neutral | good | warn | serious | crit. Neutral is the honest default for null. */
    public function tone(?int $count): string
    {
        return $count === null ? 'neutral' : ($count > 0 ? 'warn' : 'good');
    }

    /** Where the figure leads. A named route; null renders as plain text. */
    public function route(): ?string
    {
        return null;
    }

    final public function badge(BranchContext $context): Badge
    {
        $count = $this->count($context);

        return new Badge(
            key: $this->key(),
            count: $count,
            label: $this->label($count),
            tone: $this->tone($count),
            route: $this->route(),
        );
    }

    /** The badge a provider produces when it knows nothing. Used by the registry on failure. */
    final public function unknown(): Badge
    {
        return new Badge(
            key: $this->key(),
            count: null,
            label: $this->label(null),
            tone: 'neutral',
            route: $this->route(),
        );
    }
}
