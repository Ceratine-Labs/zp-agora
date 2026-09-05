<?php

namespace Tests\Feature\Auth;

use App\Support\Badges\Badge;
use App\Support\Badges\BadgeProvider;
use App\Support\Badges\BadgeRegistry;
use App\Support\BranchContext;
use Modules\Core\Badges\ExceptionsOpenBadge;
use RuntimeException;
use Tests\TestCase;

/**
 * The badge contract — the durable half of T007.
 *
 * The four figures on the sign-in page are placeholders today because Imports,
 * Exceptions, Cash and Purchasing do not exist. What has to be right NOW is
 * the mechanism they plug into, because four later tasks will build against it
 * and none of them should have to change Core to do so.
 *
 * So these tests assert the properties that make that true rather than any
 * particular count: a later module can take a key over, a broken provider
 * cannot take the sign-in page down, and null is rendered as "unknown" and
 * never as zero.
 *
 * Writes nothing. The whole surface is in memory and in the cache.
 */
class BadgeRegistryTest extends TestCase
{
    private BadgeRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        // A fresh registry rather than the container's singleton: registering
        // a fake over 'exceptions.open' in the shared one would leak into
        // whatever ran next.
        $this->registry = new BadgeRegistry;
    }

    public function test_a_provider_answers_with_a_count_a_sentence_and_a_tone(): void
    {
        $this->registry->register(new FakeCountBadge(12));

        $badge = $this->registry->badge('test.fake');

        $this->assertInstanceOf(Badge::class, $badge);
        $this->assertSame(12, $badge->count);
        $this->assertSame('12 things outstanding', $badge->label);
        $this->assertSame('warn', $badge->tone);
        $this->assertSame('12', $badge->value());
        $this->assertTrue($badge->isRaised());
    }

    public function test_a_later_module_takes_a_key_over_without_touching_core(): void
    {
        // Core's placeholder, exactly as CoreServiceProvider registers it.
        $this->registry->register(ExceptionsOpenBadge::class);
        $this->assertNull($this->registry->badge('exceptions.open')?->count);

        // The Exceptions module lands and registers under the same key. This
        // is the whole contract: no edit to Core, no edit to the sign-in page.
        $this->registry->register(new RealExceptionsBadge);
        $this->registry->forget('exceptions.open');

        $badge = $this->registry->badge('exceptions.open');
        $this->assertNotNull($badge);

        $this->assertSame(9, $badge->count);
        $this->assertSame('9 exceptions open', $badge->label);
        $this->assertSame('crit', $badge->tone, 'The mockup calls anything over eight critical.');
    }

    public function test_a_provider_that_throws_does_not_break_the_page(): void
    {
        // The sign-in screen is anonymous and everybody sees it. A module that
        // is half-deployed must not be able to 500 it.
        $this->registry->register(new ExplodingBadge);

        $badge = $this->registry->badge('test.explodes');

        $this->assertNotNull($badge);
        $this->assertNull($badge->count);
        $this->assertSame('neutral', $badge->tone);
        $this->assertFalse($badge->isKnown());
    }

    public function test_an_unknown_figure_renders_as_an_em_dash_and_never_as_zero(): void
    {
        $this->registry->register(new FakeCountBadge(null));

        $badge = $this->registry->badge('test.fake');
        $this->assertNotNull($badge);

        // "All overnight loads clean" and "we have not looked" are different
        // sentences, and only one of them is safe on a sign-in screen.
        $this->assertSame('—', $badge->value());
        $this->assertFalse($badge->isRaised());
    }

    public function test_an_unknown_key_is_skipped_rather_than_throwing(): void
    {
        $this->registry->register(new FakeCountBadge(3));

        $badges = $this->registry->badges(['test.fake', 'nothing.registered.here']);

        $this->assertCount(1, $badges, 'A surface naming a figure whose module is absent renders one row short.');
        $this->assertSame('test.fake', $badges[0]->key);
    }

    public function test_a_provider_is_resolvable_by_class_name_as_well_as_by_key(): void
    {
        // agora.MenuItem.BadgeProvider stores an FQCN, so a menu row must not
        // have to know the dotted key its provider chose.
        $badge = $this->registry->badge(ExceptionsOpenBadge::class);

        $this->assertSame('exceptions.open', $badge?->key);
    }

    public function test_the_four_signin_figures_all_have_a_provider(): void
    {
        // Guards the config list against a typo: a misspelt key would silently
        // drop a row off the sign-in page and nothing else would complain.
        $registry = app(BadgeRegistry::class);

        foreach ((array) config('core.signin_state') as $key) {
            $this->assertTrue($registry->has($key), "No badge provider is registered for [{$key}].");
        }
    }
}

/** A figure whose value the test decides. */
class FakeCountBadge extends BadgeProvider
{
    public function __construct(private ?int $value) {}

    public function key(): string
    {
        return 'test.fake';
    }

    public function count(BranchContext $context): ?int
    {
        return $this->value;
    }

    public function label(?int $count): string
    {
        return $count === null ? 'Nothing known' : $count.' things outstanding';
    }
}

/** What the Exceptions module's own provider will look like. */
class RealExceptionsBadge extends BadgeProvider
{
    public function key(): string
    {
        return 'exceptions.open';
    }

    public function count(BranchContext $context): ?int
    {
        return 9;
    }

    public function label(?int $count): string
    {
        return $count.' exceptions open';
    }

    public function tone(?int $count): string
    {
        return $count > 8 ? 'crit' : 'warn';
    }
}

/** A provider whose source is not there. */
class ExplodingBadge extends BadgeProvider
{
    public function key(): string
    {
        return 'test.explodes';
    }

    public function count(BranchContext $context): ?int
    {
        throw new RuntimeException('Invalid object name agora.Exception.');
    }

    public function label(?int $count): string
    {
        return 'Exception register — not reported yet';
    }
}
