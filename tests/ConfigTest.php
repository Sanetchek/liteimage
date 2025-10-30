<?php
/**
 * Tests for LiteImage\Config class
 *
 * @package LiteImage
 */

use LiteImage\Config;

/**
 * Test Config class constants
 */
class ConfigTest extends PHPUnit\Framework\TestCase
{
    public function testConstantsExist()
    {
        $this->assertIsInt(Config::MOBILE_BREAKPOINT);
        $this->assertIsInt(Config::WEBP_QUALITY);
        $this->assertIsInt(Config::CLEANUP_BATCH_SIZE);
        $this->assertIsInt(Config::RATE_LIMIT_SECONDS);
        $this->assertIsInt(Config::CACHE_EXPIRATION);

        $this->assertIsString(Config::THUMBNAIL_PREFIX);
        $this->assertIsString(Config::LOG_DATE_FORMAT);
        $this->assertIsArray(Config::ALLOWED_MIME_TYPES);
    }

    public function testMobileBreakpoint()
    {
        $this->assertEquals(768, Config::MOBILE_BREAKPOINT);
    }

    public function testWebpQuality()
    {
        $this->assertEquals(85, Config::WEBP_QUALITY);
        $this->assertGreaterThan(0, Config::WEBP_QUALITY);
        $this->assertLessThanOrEqual(100, Config::WEBP_QUALITY);
    }

    public function testThumbnailPrefix()
    {
        $this->assertEquals('liteimage-', Config::THUMBNAIL_PREFIX);
    }

    public function testAllowedMimeTypes()
    {
        $allowed = Config::ALLOWED_MIME_TYPES;
        $this->assertContains('image/jpeg', $allowed);
        $this->assertContains('image/png', $allowed);
        $this->assertContains('image/gif', $allowed);
        $this->assertContains('image/webp', $allowed);
    }
}

