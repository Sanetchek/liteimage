<?php
/**
 * Tests for LiteImage\Image\ThumbnailGenerator class
 *
 * @package LiteImage
 */

use LiteImage\Image\ThumbnailGenerator;
use LiteImage\Config;

/**
 * Test ThumbnailGenerator class
 */
class ThumbnailGeneratorTest extends PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Mock WordPress functions
        if (!function_exists('add_image_size')) {
            function add_image_size($name, $width, $height, $crop = false) {
                return true;
            }
        }
    }

    public function testGetThumbSizeReturnsArrayWithDefaults()
    {
        $result = ThumbnailGenerator::get_thumb_size('full');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('size_name', $result);
        $this->assertArrayHasKey('width', $result);
        $this->assertArrayHasKey('height', $result);
        $this->assertEquals('full', $result['size_name']);
    }

    public function testGetThumbSizeWithArraySize()
    {
        $thumb_size = [800, 600];
        $result = ThumbnailGenerator::get_thumb_size($thumb_size, 123);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('size_name', $result);
        $this->assertStringStartsWith(Config::THUMBNAIL_PREFIX, $result['size_name']);
    }

    public function testGetThumbSizeNameFormat()
    {
        // Mock liteimage_downsize function
        if (!function_exists('liteimage_downsize')) {
            function liteimage_downsize($id, $size) {
                return [800, 600];
            }
        }

        $result = ThumbnailGenerator::get_thumb_size([800, 600], 123);

        // Size name should follow pattern: liteimage-WIDTHxHEIGHT
        $expected_pattern = '/^' . preg_quote(Config::THUMBNAIL_PREFIX, '/') . '\d+x\d+$/';
        $this->assertMatchesRegularExpression($expected_pattern, $result['size_name']);
    }

    public function testGetThumbSizeWithZeroDimensions()
    {
        // Mock liteimage_downsize to handle proportional sizes
        if (!function_exists('liteimage_downsize')) {
            function liteimage_downsize($id, $size) {
                // Return proportional size for [1920, 0]
                if ($size[0] > 0 && $size[1] == 0) {
                    return [1920, 1080]; // 16:9 ratio
                }
                return $size;
            }
        }

        $result = ThumbnailGenerator::get_thumb_size([1920, 0], 123);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('width', $result);
        $this->assertArrayHasKey('height', $result);
    }

    public function testGetThumbSizeIsStatic()
    {
        // Verify it's a static method
        $reflection = new ReflectionClass(ThumbnailGenerator::class);
        $method = $reflection->getMethod('get_thumb_size');

        $this->assertTrue($method->isStatic());
    }

    public function testThumbnailPrefixConstant()
    {
        $prefix = Config::THUMBNAIL_PREFIX;
        $this->assertIsString($prefix);
        $this->assertEquals('liteimage-', $prefix);
    }
}

