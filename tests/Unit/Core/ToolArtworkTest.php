<?php

namespace Tests\Unit\Core;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\NavigationArtwork;
use RuntimeException;
use Tests\TestCase;

class ToolArtworkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_keys(config('database.connections')) as $connection) {
            DB::connection($connection)->beforeExecuting(function (): void {
                throw new RuntimeException('Tool artwork must not query the database.');
            });
        }
    }

    public function test_every_active_tool_renders_a_distinct_decorative_svg_ready_for_loader_cloning(): void
    {
        $routes = [
            'app.cash.zread', 'app.cash.banking', 'app.cash.cashups', 'app.cash.cashups.nocashup',
            'app.cash.tillbalance', 'app.cash.pumpshortages', 'app.cash.dropsafe', 'app.cash.dropsafe.bags',
            'app.cash.staffshorts', 'app.cash.staffshorts.list', 'app.cash.deductions',
            'app.cash.deductions.stockloss', 'app.cash.deductions.nonint', 'app.cash.deductions.payroll',
            'app.recon.index', 'app.recon.dashboard', 'app.recon.trace',
            'app.stockrecon.index', 'app.stockrecon.variance', 'app.stockrecon.weekly', 'app.stockrecon.countingareas', 'app.stockrecon.water',
            'app.master.stock.index', 'app.master.critical.index', 'app.setup.users.index', 'app.setup.roles.index',
            'app.debtors.index', 'app.assets.register', 'app.assets.movement', 'app.assets.repairs', 'app.assets.masters', 'app.utilities.index',
        ];
        $destinations = array_map(fn ($route) => [$route, []], $routes);
        foreach (['CashBags', 'CashMachine', 'SmartATM'] as $area) {
            $destinations[] = ['app.recon.area', compact('area')];
        }
        $drawings = [];
        foreach ($destinations as [$route, $parameters]) {
            $art = app(NavigationArtwork::class)->forRoute($route, $parameters);
            $this->assertSame('tool', $art['kind'], $route);
            $html = Blade::render('<x-feature-art :art="$art" />', compact('art'));
            $document = new DOMDocument;
            @$document->loadHTML($html);
            $xpath = new DOMXPath($document);
            $svg = $xpath->query('//svg[@data-tool-art]')->item(0);
            $this->assertInstanceOf(DOMElement::class, $svg, $route);
            $this->assertSame($art['variant'], $svg->getAttribute('data-tool-art'));
            $this->assertSame($art['variant'], $svg->getAttribute('data-module-art'));
            $this->assertSame('false', $svg->getAttribute('data-module-art-animated'));
            $this->assertSame('true', $svg->getAttribute('aria-hidden'));
            $this->assertSame('false', $svg->getAttribute('focusable'));
            $this->assertStringContainsString('loader-art', $svg->getAttribute('class'));
            $this->assertSame(0, $xpath->query('//*[@id] | //script | //image | //foreignObject')->length);
            $scene = $xpath->query('.//g[@class="tool-art-scene"]', $svg)->item(0);
            $this->assertNotNull($scene, $route);
            $drawings[$art['variant']] = hash('sha256', $scene->C14N());
        }
        $this->assertCount(count($destinations), $drawings);
        $this->assertCount(count($destinations), array_unique($drawings));
    }

    public function test_tool_animation_is_explicit_and_unknown_variants_use_safe_report_art(): void
    {
        $html = Blade::render('<x-feature-art :art="$art" animated />', ['art' => ['kind' => 'tool', 'variant' => 'cash-allocation']]);
        $this->assertStringContainsString('data-module-art-animated="true"', $html);
        foreach (['../../private', ['cash-allocation']] as $variant) {
            $html = Blade::render('<x-tool-art :variant="$variant" />', compact('variant'));
            $this->assertStringContainsString('data-tool-art="reports"', $html);
            $this->assertStringContainsString('data-report-art="reports"', $html);
            $this->assertStringNotContainsString('private', $html);
        }
    }
}
