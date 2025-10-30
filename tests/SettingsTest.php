<?php
/**
 * Tests for LiteImage\Admin\Settings class
 *
 * @package LiteImage
 */

use LiteImage\Admin\Settings;

/**
 * Test Settings class
 */
class SettingsTest extends PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Mock WordPress functions
        if (!function_exists('get_option')) {
            function get_option($key, $default = null) {
                return $default;
            }
        }

        if (!function_exists('update_option')) {
            function update_option($key, $value) {
                return true;
            }
        }

        if (!function_exists('add_filter')) {
            function add_filter($hook, $callback, $priority = 10, $args = 1) {
                return true;
            }
        }
    }

    public function testGetInstanceReturnsSingleton()
    {
        $instance1 = Settings::get_instance();
        $instance2 = Settings::get_instance();

        $this->assertSame($instance1, $instance2);
    }

    public function testGetMethodExists()
    {
        $settings = Settings::get_instance();
        $this->assertTrue(method_exists($settings, 'get'));
    }

    public function testSetMethodExists()
    {
        $settings = Settings::get_instance();
        $this->assertTrue(method_exists($settings, 'set'));
    }

    public function testSaveMethodExists()
    {
        $settings = Settings::get_instance();
        $this->assertTrue(method_exists($settings, 'save'));
    }
}

