<?php

namespace Tests\Feature\Components;

use Modules\Core\Support\ComponentCatalogue;
use Tests\TestCase;

/**
 * The gallery renders — every component, every slot, both themes.
 *
 * This is as close as a phpunit test gets to the visual half of T013's
 * acceptance. It cannot see a pixel or a console, and the report says so. What
 * it does prove is that the page renders at all with every component on it,
 * that the theme is stamped on the way out for both light and dark, that
 * nothing has been added to the catalogue without appearing on the page, and
 * that the surface the number-format guard depends on is still where that
 * guard looks for it.
 *
 * Read-only, and unauthenticated: the gallery is deliberately outside `auth`
 * so it can be opened without a session and without a branch context.
 */
class GalleryTest extends TestCase
{
    public function test_the_gallery_renders(): void
    {
        $this->get('/dev/components')
            ->assertOk()
            ->assertSee('Components')
            ->assertSee('Not built yet');
    }

    public function test_the_old_theme_url_still_serves_the_same_page(): void
    {
        // tests/e2e/format.spec.js loads /dev/theme five times and is the only
        // guard on App\Support\Format agreeing with resources/js/format.js.
        // One page, two URLs — retiring this one would mean editing that guard.
        $theme = $this->get('/dev/theme')->assertOk()->getContent();

        // Same controller, same view — so the same landmarks. Not a byte
        // comparison: the workspace switch echoes the current URL back into
        // its links, and a tab strip's ids are generated per render.
        foreach (['id="parity"', 'class="sg-swatch"', 'class="gal-props"', 'Not built yet'] as $landmark) {
            $this->assertStringContainsString($landmark, $theme);
        }
    }

    public function test_the_parity_table_the_format_guard_reads_is_still_there(): void
    {
        $html = $this->get('/dev/components')->assertOk()->getContent();

        // The exact hooks format.spec.js queries. A gallery rewrite that
        // dropped any of them would break the number-format guard silently.
        $this->assertStringContainsString('id="parity"', $html);
        $this->assertStringContainsString('class="php-out"', $html);
        $this->assertStringContainsString('class="js-out"', $html);
        $this->assertStringContainsString('data-fn=', $html);
        $this->assertStringContainsString('class="sg-swatch"', $html);
        $this->assertGreaterThan(20, substr_count($html, 'data-args='),
            'The parity table should carry the awkward cases, not a handful.');
    }

    public function test_every_component_in_the_catalogue_is_on_the_page(): void
    {
        // The catalogue writes docs/components.md. A component listed there and
        // missing here is a documented component nobody can look at.
        $html = $this->get('/dev/components')->assertOk()->getContent();

        foreach (ComponentCatalogue::all() as $name => $spec) {
            if (! $spec['gallery']) {
                continue;
            }

            $this->assertStringContainsString(
                '&lt;'.$name.'&gt;',
                $html,
                "{$name} is in the catalogue but not rendered in the gallery."
            );
        }
    }

    public function test_every_pending_component_has_a_labelled_slot(): void
    {
        // The slots are what let another task's component be wired in without
        // the page having to be redesigned around it.
        $html = $this->get('/dev/components')->assertOk()->getContent();

        foreach (ComponentCatalogue::pending() as $item) {
            $this->assertStringContainsString('&lt;'.$item['name'].'&gt;', $html);
        }

        $this->assertStringContainsString('class="gal-slot"', $html);
    }

    public function test_the_props_tables_render(): void
    {
        $html = $this->get('/dev/components')->assertOk()->getContent();

        $this->assertStringContainsString('class="gal-props"', $html);
        // One prop picked from each end of the catalogue, so a table that
        // stopped rendering halfway is caught.
        $this->assertStringContainsString('collapsible', $html);
        $this->assertStringContainsString('confidence', $html);
    }

    public function test_the_page_stamps_the_theme_it_was_asked_for(): void
    {
        // Three states, not two: an unstamped document follows the system.
        $plain = $this->get('/dev/components')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-theme=', $plain,
            'With no stored choice the document must be unstamped, so it follows the system.');

        // withUnencryptedCookie, not withCookie: `agora_theme` is excepted from
        // cookie encryption in bootstrap/app.php because JavaScript writes it,
        // so the encrypting helper hands the view a ciphertext blob.
        $dark = $this->withUnencryptedCookie('agora_theme', 'dark')
            ->get('/dev/components')->assertOk()->getContent();
        $this->assertStringContainsString('data-theme="dark"', $dark);

        $light = $this->withUnencryptedCookie('agora_theme', 'light')
            ->get('/dev/components')->assertOk()->getContent();
        $this->assertStringContainsString('data-theme="light"', $light);
    }

    public function test_a_cookie_holding_anything_else_leaves_the_document_unstamped(): void
    {
        // The cookie is unencrypted and written by JavaScript, so it can hold
        // anything. Only light and dark may reach the attribute; everything
        // else falls back to following the system.
        $html = $this->withUnencryptedCookie('agora_theme', 'purple')
            ->get('/dev/components')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-theme=', $html);
    }

    public function test_no_blade_directive_leaks_into_the_rendered_page(): void
    {
        // Blade has @checked, @disabled, @selected, @readonly and @required —
        // it has no @open and no @hidden. Written that way they survive
        // compilation as literal text inside a tag, and the attribute they were
        // meant to set never applies. Cheap to check, and it caught two live
        // components.
        $html = $this->get('/dev/components')->assertOk()->getContent();

        foreach (['@open(', '@hidden(', '@class(', '@checked('] as $leak) {
            $this->assertStringNotContainsString($leak, $html);
        }
    }

    public function test_the_gallery_is_not_registered_in_production(): void
    {
        // A page enumerating the whole design on a production URL invites being
        // treated as documentation by people who should be looking at the real
        // screens.
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route) => $route->uri());

        $this->assertTrue($routes->contains('dev/components'), 'Expected the gallery under testing.');

        $source = file_get_contents(base_path('Modules/Core/Routes/root.php'));
        $this->assertStringContainsString("app()->environment('local', 'testing')", $source);
    }
}
