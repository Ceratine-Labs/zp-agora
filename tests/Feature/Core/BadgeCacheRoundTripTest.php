<?php

namespace Tests\Feature\Core;

use App\Support\Badges\Badge;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A cached Badge must survive the round trip through a store that actually
 * serialises.
 *
 * This exists because the rest of the suite cannot see the bug it guards.
 * phpunit.xml sets CACHE_STORE=array, and the array store keeps live objects
 * in memory — nothing is ever serialised, so every assertion about caching
 * passes whatever config/cache.php says. In production the store is `file`,
 * Laravel 13 restricts unserialize to config('cache.serializable_classes'),
 * and that value ships as `false`, meaning no class deserialises at all.
 *
 * The failure is quiet, which is what makes it worth a test: BadgeRegistry
 * catches a throwing provider and logs it, so a cache hit returning
 * __PHP_Incomplete_Class becomes a TypeError, becomes a swallowed warning,
 * becomes an em dash where a figure should be. Found on the first deploy to
 * agora.ceratine.com, 6 September 2026, in the log rather than on the screen.
 */
class BadgeCacheRoundTripTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->key = 'TEST-badge-roundtrip-'.uniqid();
    }

    protected function tearDown(): void
    {
        Cache::store('file')->forget($this->key);

        parent::tearDown();
    }

    public function test_a_badge_survives_a_store_that_serialises(): void
    {
        $badge = new Badge(
            key: 'test.roundtrip',
            count: 7,
            label: 'Seven of something',
            tone: 'warn',
            route: 'app.dashboard',
        );

        Cache::store('file')->put($this->key, $badge, 60);

        $back = Cache::store('file')->get($this->key);

        // The assertion that matters: with serializable_classes => false this
        // is __PHP_Incomplete_Class, not a Badge, and every caller downstream
        // fails on a type it cannot name.
        $this->assertInstanceOf(Badge::class, $back, 'A cached Badge came back as '.get_debug_type($back).' — check config/cache.php serializable_classes.');
        $this->assertSame('test.roundtrip', $back->key);
        $this->assertSame(7, $back->count);
        $this->assertSame('warn', $back->tone);
    }

    public function test_the_allowlist_names_badge_rather_than_opening_the_gate(): void
    {
        $allowed = config('cache.serializable_classes');

        // `true` would allow every class, which is the gadget-chain hole the
        // framework's default exists to close. An explicit list is the point.
        $this->assertIsArray($allowed, 'serializable_classes must be an explicit allowlist, not a boolean.');
        $this->assertContains(Badge::class, $allowed);
    }
}
