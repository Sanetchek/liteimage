<?php

/**
 * Gutenberg block registration for LiteImage.
 *
 * @package LiteImage
 */

namespace LiteImage\Blocks;

use LiteImage\Support\Logger;

defined('ABSPATH') || exit;

/**
 * Class LiteImageBlock
 *
 * Registers and renders the LiteImage Gutenberg block.
 */
class LiteImageBlock
{
    private const BLOCK_NAME = 'liteimage/image';
    private const HANDLE_EDITOR_SCRIPT = 'liteimage-block-editor';

    /**
     * Register block assets and block type.
     *
     * @return void
     */
    public static function register(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }

        $block_dir = trailingslashit(LITEIMAGE_DIR) . 'blocks/liteimage';

        if (!file_exists($block_dir . '/block.json')) {
            return;
        }

        self::register_assets();

        if (function_exists('register_block_type_from_metadata')) {
            register_block_type_from_metadata(
                $block_dir,
                [
                    'render_callback' => [self::class, 'render_block'],
                ]
            );
            return;
        }

        $metadata = json_decode(file_get_contents($block_dir . '/block.json'), true);
        if (!is_array($metadata) || empty($metadata['name'])) {
            return;
        }

        register_block_type(
            $metadata['name'],
            [
                'render_callback' => [self::class, 'render_block'],
                'attributes' => $metadata['attributes'] ?? [],
                'editor_script' => self::HANDLE_EDITOR_SCRIPT,
                'title' => $metadata['title'] ?? '',
                'category' => $metadata['category'] ?? 'widgets',
                'icon' => $metadata['icon'] ?? 'format-image',
                'description' => $metadata['description'] ?? '',
                'supports' => $metadata['supports'] ?? [],
                'keywords' => $metadata['keywords'] ?? [],
                'textdomain' => $metadata['textdomain'] ?? 'liteimage',
            ]
        );
    }

    /**
     * Server-side render callback for the block.
     *
     * @param array $attributes Block attributes.
     * @return string
     */
    public static function render_block(array $attributes): string
    {
        if (!function_exists('liteimage')) {
            Logger::log([
                'context' => 'LiteImageBlock::render_block',
                'event' => 'missing_liteimage_function',
            ]);
            return '';
        }

        $prepared = self::prepare_render_args($attributes);
        $image_id = $prepared['image_id'];

        if ($image_id <= 0) {
            Logger::log([
                'context' => 'LiteImageBlock::render_block',
                'event' => 'invalid_image_id',
                'attributes' => $attributes,
            ]);
            return '';
        }

        $data = $prepared['data'];
        $mobile_id = $prepared['mobile_image_id'];

        Logger::log([
            'context' => 'LiteImageBlock::render_block',
            'event' => 'rendering_liteimage',
            'image_id' => $image_id,
            'mobile_image_id' => $mobile_id,
            'data' => $data,
        ]);

        return liteimage($image_id, $data, $mobile_id);
    }

    /**
     * Prepare render arguments from raw block attributes.
     *
     * @param array $attributes Raw block attributes.
     * @return array{
     *     image_id:int,
     *     mobile_image_id:int|null,
     *     data:array<string,mixed>
     * }
     */
    public static function prepare_render_args(array $attributes): array
    {
        $image_id = isset($attributes['desktopImageId']) ? absint($attributes['desktopImageId']) : 0;

        $mobile_id = isset($attributes['mobileImageId']) ? absint($attributes['mobileImageId']) : null;
        if ($mobile_id === 0) {
            $mobile_id = null;
        }

        $data = [];
        $thumb = self::sanitize_dimension_pair($attributes['thumb'] ?? null);
        if ($thumb !== null) {
            $data['thumb'] = $thumb;
        }

        $args = self::sanitize_html_attributes($attributes['htmlAttributes'] ?? []);
        if (!empty($args)) {
            $data['args'] = $args;
        }

        $mode = isset($attributes['breakpointMode']) && $attributes['breakpointMode'] === 'max' ? 'max' : 'min';
        $raw_breakpoints = $attributes['breakpoints'] ?? null;

        $breakpoints = is_array($raw_breakpoints) ? self::sanitize_breakpoints($raw_breakpoints) : [];

        if (empty($breakpoints)) {
            $legacy_min = self::sanitize_breakpoints($attributes['minBreakpoints'] ?? []);
            $legacy_max = self::sanitize_breakpoints($attributes['maxBreakpoints'] ?? []);

            if (!empty($legacy_min)) {
                $breakpoints = $legacy_min;
                $mode = 'min';
            } elseif (!empty($legacy_max)) {
                $breakpoints = $legacy_max;
                $mode = 'max';
            }
        }

        if (!empty($breakpoints)) {
            if ($mode === 'max') {
                $data['max'] = $breakpoints;
            } else {
                $data['min'] = $breakpoints;
            }
        }

        $result = [
            'image_id' => $image_id,
            'mobile_image_id' => $mobile_id,
            'data' => $data,
        ];

        Logger::log([
            'context' => 'LiteImageBlock::prepare_render_args',
            'result' => $result,
            'raw_attributes' => $attributes,
        ]);

        return $result;
    }

    /**
     * Register editor assets for the block.
     *
     * @return void
     */
    private static function register_assets(): void
    {
        $script_path = 'assets/js/block.js';
        $script_file = trailingslashit(LITEIMAGE_DIR) . $script_path;

        $asset_dependencies = [
            'wp-blocks',
            'wp-i18n',
            'wp-element',
            'wp-components',
            'wp-block-editor',
            'wp-data',
        ];

        if (file_exists($script_file)) {
            wp_register_script(
                self::HANDLE_EDITOR_SCRIPT,
                plugins_url($script_path, trailingslashit(LITEIMAGE_DIR) . 'liteimage.php'),
                $asset_dependencies,
                self::get_asset_version($script_file),
                true
            );

            wp_set_script_translations(self::HANDLE_EDITOR_SCRIPT, 'liteimage', trailingslashit(LITEIMAGE_DIR) . 'languages');
        } else {
            wp_register_script(
                self::HANDLE_EDITOR_SCRIPT,
                '',
                $asset_dependencies,
                '1.0.0'
            );
        }
    }

    /**
     * Sanitize breakpoint definitions.
     *
     * @param array $breakpoints Breakpoint definitions.
     * @return array<string, array{int,int}>
     */
    private static function sanitize_breakpoints($breakpoints): array
    {
        if (!is_array($breakpoints)) {
            return [];
        }

        $sanitized = [];
        foreach ($breakpoints as $item) {
            if (!is_array($item)) {
                continue;
            }
            $width = isset($item['width']) ? absint($item['width']) : 0;
            if ($width === 0) {
                continue;
            }

            $image_width = isset($item['imageWidth']) ? absint($item['imageWidth']) : 0;
            $image_height = isset($item['imageHeight']) ? absint($item['imageHeight']) : 0;

            $sanitized[(string) $width] = [$image_width, $image_height];
        }

        return $sanitized;
    }

    /**
     * Sanitize a dimension pair.
     *
     * @param mixed $value Dimension pair array.
     * @return array<int,int>|null
     */
    private static function sanitize_dimension_pair($value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $width = isset($value['width']) ? absint($value['width']) : 0;
        $height = isset($value['height']) ? absint($value['height']) : 0;

        if ($width === 0 && $height === 0) {
            return null;
        }

        return [$width, $height];
    }

    /**
     * Sanitize arbitrary HTML attributes.
     *
     * @param mixed $attributes Attribute pairs.
     * @return array<string,string>
     */
    private static function sanitize_html_attributes($attributes): array
    {
        if (!is_array($attributes)) {
            return [];
        }

        $sanitized = [];
        foreach ($attributes as $item) {
            if (!is_array($item)) {
                continue;
            }

            $key = isset($item['key']) ? trim((string) $item['key']) : '';
            $value = isset($item['value']) ? (string) $item['value'] : '';

            if ($key === '') {
                continue;
            }

            if (!self::is_attribute_key_allowed($key)) {
                continue;
            }

            $sanitized[$key] = self::sanitize_text($value);
        }

        return $sanitized;
    }

    /**
     * Check whether attribute key is allowed.
     *
     * @param string $key Attribute key.
     * @return bool
     */
    private static function is_attribute_key_allowed(string $key): bool
    {
        if (str_starts_with($key, 'data-') || str_starts_with($key, 'aria-')) {
            return true;
        }

        return (bool) preg_match('/^[a-zA-Z][a-zA-Z0-9:_\\-\\.]*$/', $key);
    }

    /**
     * Sanitize text value with WordPress helpers when available.
     *
     * @param string $value Raw value.
     * @return string
     */
    private static function sanitize_text(string $value): string
    {
        if (function_exists('sanitize_text_field')) {
            return sanitize_text_field($value);
        }

        return trim(strip_tags($value));
    }

    /**
     * Get asset version for cache busting.
     *
     * @param string $file_path File path.
     * @return string
     */
    private static function get_asset_version(string $file_path): string
    {
        $modified_time = filemtime($file_path);

        return $modified_time === false ? '1.0.0' : (string) $modified_time;
    }
}


