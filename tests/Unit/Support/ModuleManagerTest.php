<?php

namespace Tests\Unit\Support;

use App\Support\Modules\ModuleManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Module discovery and boot ordering, against fixtures on disk.
 *
 * Ordering is worth a test rather than a reading: it decides which module's
 * service provider has run when another's boots, and getting it wrong shows up
 * as a null binding somewhere unrelated.
 */
class ModuleManagerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/agora-modules-'.uniqid();
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*/module.json') ?: [] as $file) {
            unlink($file);
            rmdir(dirname($file));
        }
        @rmdir($this->root);

        parent::tearDown();
    }

    private function module(string $name, array $manifest = []): void
    {
        mkdir($this->root.'/'.$name);
        file_put_contents(
            $this->root.'/'.$name.'/module.json',
            json_encode(array_merge(['name' => $name, 'alias' => strtolower($name)], $manifest))
        );
    }

    public function test_it_discovers_modules_by_manifest(): void
    {
        $this->module('Core', ['order' => 1]);
        $this->module('Cash', ['order' => 12]);

        $manager = new ModuleManager($this->root);

        $this->assertSame(['Core', 'Cash'], $manager->names());
        $this->assertTrue($manager->has('Cash'));
        $this->assertSame('cash', $manager->get('Cash')->alias);
    }

    public function test_a_module_boots_after_the_modules_it_requires(): void
    {
        // Cash sorts first by order, but requires Core — so Core must still
        // come out in front.
        $this->module('Cash', ['order' => 1, 'requires' => ['Core']]);
        $this->module('Core', ['order' => 90]);

        $manager = new ModuleManager($this->root);

        $this->assertSame(['Core', 'Cash'], $manager->names());
    }

    public function test_a_dependency_cycle_is_reported_not_resolved(): void
    {
        $this->module('A', ['requires' => ['B']]);
        $this->module('B', ['requires' => ['A']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cycle/i');

        (new ModuleManager($this->root))->names();
    }

    public function test_a_missing_dependency_is_reported(): void
    {
        $this->module('Cash', ['requires' => ['Nothing']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not installed/');

        (new ModuleManager($this->root))->names();
    }

    public function test_a_disabled_module_is_discovered_but_not_enabled(): void
    {
        $this->module('Core');
        $this->module('Parked', ['enabled' => false]);

        $manager = new ModuleManager($this->root);

        $this->assertCount(2, $manager->all());
        $this->assertSame(['Core'], array_keys($manager->enabled()));
    }
}
