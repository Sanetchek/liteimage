<?php

namespace {
    if (!function_exists('absint')) {
        /**
         * Lightweight replacement for WordPress absint during tests.
         *
         * @param mixed $maybeint Potential integer.
         * @return int
         */
        function absint($maybeint)
        {
            return (int) max(0, (int) $maybeint);
        }
    }

    if (!function_exists('sanitize_text_field')) {
        /**
         * Minimal sanitize_text_field polyfill.
         *
         * @param string $value Raw input.
         * @return string
         */
        function sanitize_text_field($value)
        {
            $value = (string) $value;
            $value = wp_strip_all_tags($value, true);
            $value = preg_replace('/[\r\n\t ]+/', ' ', $value);
            return trim($value);
        }
    }

    if (!function_exists('wp_strip_all_tags')) {
        /**
         * Polyfill for wp_strip_all_tags.
         *
         * @param string $string String to strip.
         * @param bool   $remove_breaks Whether to remove line breaks.
         * @return string
         */
        function wp_strip_all_tags($string, $remove_breaks = false)
        {
            $string = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $string);
            $string = strip_tags($string);

            if ($remove_breaks) {
                $string = preg_replace('/[\r\n\t ]+/', ' ', $string);
            }

            return trim($string);
        }
    }
}

namespace LiteImage\Tests\Blocks {

use LiteImage\Blocks\LiteImageBlock;
use PHPUnit\Framework\TestCase;

class LiteImageBlockTest extends TestCase
{
    public function testPrepareRenderArgsSanitizesInput(): void
    {
        $attributes = [
            'desktopImageId' => '42',
            'mobileImageId' => '0',
            'thumb' => [
                'width' => '1920',
                'height' => '0',
            ],
            'htmlAttributes' => [
                ['key' => 'class', 'value' => '  hero-image  '],
                ['key' => 'data-test', 'value' => 'example'],
                ['key' => '', 'value' => 'should be skipped'],
            ],
            'breakpointMode' => 'max',
            'breakpoints' => [
                ['width' => '767', 'imageWidth' => '768', 'imageHeight' => '432'],
                ['width' => 'abc', 'imageWidth' => '0', 'imageHeight' => '0'],
            ],
        ];

        $result = LiteImageBlock::prepare_render_args($attributes);

        $this->assertSame(42, $result['image_id']);
        $this->assertNull($result['mobile_image_id']);

        $this->assertSame([1920, 0], $result['data']['thumb']);
        $this->assertSame(
            [
                'class' => 'hero-image',
                'data-test' => 'example',
            ],
            $result['data']['args']
        );

        $this->assertArrayNotHasKey('min', $result['data']);
        $this->assertArrayHasKey('max', $result['data']);
        $this->assertSame(
            [
                '767' => [768, 432],
            ],
            $result['data']['max']
        );
    }

    public function testPrepareRenderArgsUsesLegacyBreakpointsWhenNewAttributeMissing(): void
    {
        $attributesMin = [
            'desktopImageId' => '51',
            'minBreakpoints' => [
                ['width' => '1024', 'imageWidth' => '1920', 'imageHeight' => '1080'],
            ],
        ];

        $resultMin = LiteImageBlock::prepare_render_args($attributesMin);

        $this->assertArrayHasKey('min', $resultMin['data']);
        $this->assertArrayNotHasKey('max', $resultMin['data']);
        $this->assertSame(
            [
                '1024' => [1920, 1080],
            ],
            $resultMin['data']['min']
        );

        $attributesMax = [
            'desktopImageId' => '51',
            'maxBreakpoints' => [
                ['width' => '767', 'imageWidth' => '768', 'imageHeight' => '432'],
            ],
        ];

        $resultMax = LiteImageBlock::prepare_render_args($attributesMax);

        $this->assertArrayHasKey('max', $resultMax['data']);
        $this->assertArrayNotHasKey('min', $resultMax['data']);
        $this->assertSame(
            [
                '767' => [768, 432],
            ],
            $resultMax['data']['max']
        );
    }
}

}

