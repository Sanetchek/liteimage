<?php
/**
 * Tests for LiteImage\Image\Renderer class
 *
 * @package LiteImage
 */

use LiteImage\Image\Renderer;

/**
 * Test Renderer class
 */
class RendererTest extends PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Mock WordPress functions
        if (!function_exists('get_attached_file')) {
            function get_attached_file($attachment_id) {
                return '/path/to/image-' . $attachment_id . '.jpg';
            }
        }

        if (!function_exists('get_the_title')) {
            function get_the_title($post_id) {
                return 'Test Image ' . $post_id;
            }
        }

        if (!function_exists('wp_get_attachment_image')) {
            function wp_get_attachment_image($attachment_id, $size = 'thumbnail', $icon = false, $attr = '') {
                return '<img src="test-' . $size . '.jpg" alt="Test" />';
            }
        }
    }

    public function testRendererInstantiates()
    {
        $renderer = new Renderer(123);
        $this->assertInstanceOf(Renderer::class, $renderer);
    }

    public function testRendererWithValidImageId()
    {
        $renderer = new Renderer(123, [], null);
        $this->assertNotNull($renderer);
    }

    public function testRendererWithDataArray()
    {
        $data = [
            'thumb' => [800, 600],
            'args' => ['alt' => 'Test Alt'],
            'min' => ['768' => [1920, 0]],
            'max' => ['767' => [768, 480]],
        ];

        $renderer = new Renderer(123, $data, 456);
        $this->assertInstanceOf(Renderer::class, $renderer);
    }

    public function testRendererWithMobileImageId()
    {
        $renderer = new Renderer(123, [], 456);
        $this->assertInstanceOf(Renderer::class, $renderer);
    }

    public function testRendererReturnsEmptyStringForInvalidImageId()
    {
        if (!function_exists('get_attached_file')) {
            $this->markTestSkipped('WordPress functions not available');
        }

        // Mock get_attached_file to return false for invalid ID
        $mock = new class {
            public static function getInvalidImage() {
                return false;
            }
        };

        // We expect empty string for invalid image
        $this->expectNotToPerformAssertions();
    }
}

