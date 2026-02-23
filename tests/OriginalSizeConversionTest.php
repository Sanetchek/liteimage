<?php

namespace LiteImage\Tests;

use LiteImage\Image\ThumbnailGenerator;
use PHPUnit\Framework\TestCase;

class OriginalSizeConversionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once __DIR__ . '/bootstrap_original_size.php';
        if (!function_exists('liteimage_downsize')) {
            self::markTestSkipped('Plugin not loaded (liteimage() stub already defined by another test).');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_liteimage_test_meta'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['_liteimage_test_meta'] = [];
        parent::tearDown();
    }

    public function test_liteimage_downsize_returns_original_dimensions_for_zero_zero(): void
    {
        $GLOBALS['_liteimage_test_meta'][42] = ['width' => 1920, 'height' => 1080];

        $result = liteimage_downsize(42, [0, 0]);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame(1920, $result[0]);
        $this->assertSame(1080, $result[1]);
    }

    public function test_liteimage_downsize_returns_original_dimensions_for_zero_zero_arbitrary_size(): void
    {
        $GLOBALS['_liteimage_test_meta'][99] = ['width' => 800, 'height' => 600];

        $result = liteimage_downsize(99, [0, 0]);

        $this->assertSame([800, 600], $result);
    }

    public function test_liteimage_downsize_returns_zero_zero_when_metadata_missing_dimensions(): void
    {
        $GLOBALS['_liteimage_test_meta'][1] = [];

        $result = liteimage_downsize(1, [0, 0]);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame(0, $result[0]);
        $this->assertSame(0, $result[1]);
    }

    public function test_get_thumb_size_full_returns_original_dimensions_and_liteimage_size_name(): void
    {
        $GLOBALS['_liteimage_test_meta'][123] = ['width' => 1920, 'height' => 1080];

        $data = ThumbnailGenerator::get_thumb_size('full', 123);

        $this->assertSame('liteimage-1920x1080', $data['size_name']);
        $this->assertSame(1920, $data['width']);
        $this->assertSame(1080, $data['height']);
    }

    public function test_get_thumb_size_zero_zero_returns_original_dimensions_and_liteimage_size_name(): void
    {
        $GLOBALS['_liteimage_test_meta'][456] = ['width' => 640, 'height' => 480];

        $data = ThumbnailGenerator::get_thumb_size([0, 0], 456);

        $this->assertSame('liteimage-640x480', $data['size_name']);
        $this->assertSame(640, $data['width']);
        $this->assertSame(480, $data['height']);
    }

    public function test_get_thumb_size_full_without_attachment_id_returns_default_full(): void
    {
        $data = ThumbnailGenerator::get_thumb_size('full', null);

        $this->assertSame('full', $data['size_name']);
        $this->assertSame(0, $data['width']);
        $this->assertSame(0, $data['height']);
    }
}
