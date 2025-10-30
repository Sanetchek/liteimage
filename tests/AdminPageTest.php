<?php
/**
 * Tests for LiteImage\Admin\AdminPage class
 *
 * @package LiteImage
 */

use LiteImage\Admin\AdminPage;

/**
 * Test AdminPage class
 */
class AdminPageTest extends PHPUnit\Framework\TestCase
{
    public function testInitMethodExists()
    {
        $this->assertTrue(method_exists(AdminPage::class, 'init'));
        $this->assertTrue((new ReflectionMethod(AdminPage::class, 'init'))->isStatic());
    }

    public function testAddSettingsLinkMethodExists()
    {
        $this->assertTrue(method_exists(AdminPage::class, 'add_settings_link'));
        $this->assertTrue((new ReflectionMethod(AdminPage::class, 'add_settings_link'))->isStatic());
    }

    public function testAddSettingsPageMethodExists()
    {
        $this->assertTrue(method_exists(AdminPage::class, 'add_settings_page'));
        $this->assertTrue((new ReflectionMethod(AdminPage::class, 'add_settings_page'))->isStatic());
    }

    public function testRegisterSettingsMethodExists()
    {
        $this->assertTrue(method_exists(AdminPage::class, 'register_settings'));
        $this->assertTrue((new ReflectionMethod(AdminPage::class, 'register_settings'))->isStatic());
    }

    public function testShowAdminNoticesMethodExists()
    {
        $this->assertTrue(method_exists(AdminPage::class, 'show_admin_notices'));
        $this->assertTrue((new ReflectionMethod(AdminPage::class, 'show_admin_notices'))->isStatic());
    }

    public function testAddThumbnailSizesColumnMethodExists()
    {
        $this->assertTrue(method_exists(AdminPage::class, 'add_thumbnail_sizes_column'));
        $this->assertTrue((new ReflectionMethod(AdminPage::class, 'add_thumbnail_sizes_column'))->isStatic());
    }
}

