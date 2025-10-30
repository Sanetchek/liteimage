<?php
/**
 * Tests for LiteImage\Plugin class
 *
 * @package LiteImage
 */

use LiteImage\Plugin;

/**
 * Test Plugin class
 */
class PluginTest extends PHPUnit\Framework\TestCase
{
    public function testGetInstanceReturnsSingleton()
    {
        $instance1 = Plugin::get_instance();
        $instance2 = Plugin::get_instance();

        $this->assertSame($instance1, $instance2);
    }

    public function testVersionConstantExists()
    {
        $this->assertTrue(defined('LiteImage\Plugin::VERSION'));
        $this->assertIsString(Plugin::VERSION);
    }

    public function testVersionConstantFormat()
    {
        $version = Plugin::VERSION;
        // Should match semantic versioning: X.Y.Z
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
    }

    public function testActivateMethodExists()
    {
        $this->assertTrue(method_exists(Plugin::class, 'activate'));
        $this->assertTrue((new ReflectionMethod(Plugin::class, 'activate'))->isStatic());
    }

    public function testDeactivateMethodExists()
    {
        $this->assertTrue(method_exists(Plugin::class, 'deactivate'));
        $this->assertTrue((new ReflectionMethod(Plugin::class, 'deactivate'))->isStatic());
    }

    public function testInitMethodExists()
    {
        $this->assertTrue(method_exists(Plugin::class, 'init'));
    }
}

