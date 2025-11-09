<?php

/**
 * Image Renderer class for LiteImage plugin
 *
 * @package LiteImage
 * @since 3.2.0
 */

namespace LiteImage\Image;

use LiteImage\Config;
use LiteImage\Support\Logger;

defined('ABSPATH') || exit;

/**
 * Class Renderer
 *
 * Renders responsive images with WebP support
 */
class Renderer
{
    /**
     * Image ID
     *
     * @var int
     */
    private $image_id;

    /**
     * Configuration data
     *
     * @var array
     */
    private $data;

    /**
     * Mobile image ID
     *
     * @var int|null
     */
    private $mobile_image_id;

    /**
     * Cached metadata
     *
     * @var array
     */
    private $metadata_cache = [];

    /**
     * Constructor
     *
     * @param int $image_id Image attachment ID
     * @param array $data Configuration data
     * @param int|null $mobile_image_id Optional mobile image ID
     */
    public function __construct($image_id, $data = [], $mobile_image_id = null)
    {
        $this->image_id = $image_id;
        $this->data = $data;
        $this->mobile_image_id = $mobile_image_id;
    }

    /**
     * Render the image
     *
     * @return string HTML output
     */
    public function render()
    {
        Logger::log([
            'context' => 'Renderer::render',
            'event' => 'start',
            'image_id' => $this->image_id,
            'mobile_image_id' => $this->mobile_image_id,
            'data' => $this->data,
        ]);

        if (!$this->image_id) {
            Logger::log("Invalid image ID: {$this->image_id}");
            return '';
        }

        $file_path_desktop = get_attached_file($this->image_id);
        if (!$file_path_desktop) {
            Logger::log("Missing file path for image ID: {$this->image_id}");
            return '';
        }
        Logger::log([
            'context' => 'Renderer::render',
            'event' => 'desktop_file_resolved',
            'image_id' => $this->image_id,
            'path' => $file_path_desktop,
        ]);

        $orig_ext_desktop = strtolower(pathinfo($file_path_desktop, PATHINFO_EXTENSION));
        $file_path_mobile = ($this->mobile_image_id && $this->mobile_image_id !== $this->image_id)
            ? get_attached_file($this->mobile_image_id)
            : null;
        $orig_ext_mobile = $file_path_mobile ? strtolower(pathinfo($file_path_mobile, PATHINFO_EXTENSION)) : null;
        Logger::log([
            'context' => 'Renderer::render',
            'event' => 'mobile_file_resolved',
            'mobile_image_id' => $this->mobile_image_id,
            'path' => $file_path_mobile,
        ]);

        // Fast path: vector/modern formats we don't process
        if (in_array($orig_ext_desktop, ['svg', 'avif'], true)) {
            Logger::log([
                'context' => 'Renderer::render',
                'event' => 'using_simple_renderer',
                'extension' => $orig_ext_desktop,
            ]);
            return $this->render_simple_image($orig_ext_desktop);
        }

        Logger::log([
            'context' => 'Renderer::render',
            'event' => 'using_picture_renderer',
            'desktop_extension' => $orig_ext_desktop,
            'mobile_extension' => $orig_ext_mobile,
        ]);

        return $this->render_picture_element(
            $file_path_desktop,
            $orig_ext_desktop,
            $file_path_mobile,
            $orig_ext_mobile
        );
    }

    /**
     * Render simple image (SVG, AVIF)
     *
     * @param string $extension File extension
     * @return string HTML output
     */
    private function render_simple_image($extension)
    {
        $args = $this->get_array_value($this->data, 'args', []);
        $thumb = $this->get_array_value($this->data, 'thumb', 'full');

        if ($extension === 'svg') {
            $img_args = array_merge([
                'alt' => $this->build_alt($args, get_the_title($this->image_id)),
                'loading' => 'lazy',
                'decoding' => 'async',
            ], $args);

            $image_url = wp_get_attachment_image_url($this->image_id, 'full');
            if (!$image_url) {
                Logger::log("Failed to get SVG URL for image ID: {$this->image_id}");
                return '';
            }

            $attributes = $this->build_attributes($img_args);

            if (is_array($thumb)) {
                list($final_width, $final_height) = $this->calculate_proportional_dimensions($this->image_id, $thumb);
                if ($final_width > 0) {
                    $attributes .= ' width="' . esc_attr($final_width) . '"';
                }
                if ($final_height > 0) {
                    $attributes .= ' height="' . esc_attr($final_height) . '"';
                }
            }

            return '<img src="' . esc_url($image_url) . '"' . $attributes . ' />';
        } else {
            $img_args = array_merge([
                'alt' => $this->build_alt($args, get_the_title($this->image_id)),
                'loading' => 'lazy',
                'decoding' => 'async',
            ], $args);

            Logger::log("Skipping processing for {$extension}");
            return wp_get_attachment_image($this->image_id, ($thumb ?: 'full'), false, $img_args);
        }
    }

    /**
     * Render picture element with responsive sources
     *
     * @param string $file_path_desktop Desktop file path
     * @param string $orig_ext_desktop Desktop extension
     * @param string|null $file_path_mobile Mobile file path
     * @param string|null $orig_ext_mobile Mobile extension
     * @return string HTML output
     */
    private function render_picture_element($file_path_desktop, $orig_ext_desktop, $file_path_mobile, $orig_ext_mobile)
    {
        $thumb = $this->get_array_value($this->data, 'thumb', [1920, 0]);
        $args = $this->get_array_value($this->data, 'args', []);
        $min = $this->get_array_value($this->data, 'min', []);
        $max = $this->get_array_value($this->data, 'max', []);

        Logger::log([
            'context' => 'Renderer::render_picture_element',
            'event' => 'inputs',
            'thumb' => $thumb,
            'min_breakpoints' => $min,
            'max_breakpoints' => $max,
        ]);

        if (!is_array($min)) {
            $min = [];
        }
        if (!is_array($max)) {
            $max = [];
        }

        $thumb_data = ThumbnailGenerator::get_thumb_size($thumb, $this->image_id);
        $thumb_size_name = $thumb_data['size_name'];

        // Build generation maps
        $sizes_to_generate_desktop = [$thumb_size_name => [$thumb_data['width'], $thumb_data['height']]];
        $sizes_to_generate_mobile = [];

        foreach (['min' => $min, 'max' => $max] as $type => $sizes) {
            foreach ($sizes as $width => $dim) {
                if (!is_array($dim) || count($dim) !== 2) {
                    continue;
                }

                $width = (int) $width;
                $is_mobile_breakpoint = ($width > 0 && $width <= Config::MOBILE_BREAKPOINT) ||
                                       ($type === 'max' && $width <= Config::MOBILE_BREAKPOINT);

                if ($this->mobile_image_id && $is_mobile_breakpoint) {
                    $sizes_to_generate_mobile[$type . '-' . $width] = $dim;
                } else {
                    $sizes_to_generate_desktop[$type . '-' . $width] = $dim;
                }
            }
        }

        Logger::log([
            'context' => 'Renderer::render_picture_element',
            'event' => 'thumbnail_plan',
            'desktop_sizes' => $sizes_to_generate_desktop,
            'mobile_sizes' => $sizes_to_generate_mobile,
        ]);

        // Generate thumbnails
        if (!empty($sizes_to_generate_desktop)) {
            ThumbnailGenerator::generate_thumbnails($this->image_id, $file_path_desktop, $sizes_to_generate_desktop);
            Logger::log([
                'context' => 'Renderer::render_picture_element',
                'event' => 'desktop_thumbnails_generated',
                'image_id' => $this->image_id,
                'count' => count($sizes_to_generate_desktop),
            ]);
        }
        if ($file_path_mobile && !empty($sizes_to_generate_mobile)) {
            ThumbnailGenerator::generate_thumbnails($this->mobile_image_id, $file_path_mobile, $sizes_to_generate_mobile);
            Logger::log([
                'context' => 'Renderer::render_picture_element',
                'event' => 'mobile_thumbnails_generated',
                'image_id' => $this->mobile_image_id,
                'count' => count($sizes_to_generate_mobile),
            ]);
        }

        // Refresh metadata
        $metadata_desktop = $this->get_attachment_metadata($this->image_id);
        $metadata_mobile = ($this->mobile_image_id && $this->mobile_image_id !== $this->image_id)
            ? $this->get_attachment_metadata($this->mobile_image_id)
            : null;

        Logger::log([
            'context' => 'Renderer::render_picture_element',
            'event' => 'metadata_loaded',
            'has_desktop_metadata' => !empty($metadata_desktop),
            'has_mobile_metadata' => !empty($metadata_mobile),
        ]);

        return $this->build_picture(
            $min,
            $max,
            $metadata_desktop,
            $metadata_mobile,
            $orig_ext_desktop,
            $orig_ext_mobile,
            $thumb_size_name,
            $args
        );
    }

    /**
     * Build picture element HTML
     *
     * @param array $min Min-width breakpoints
     * @param array $max Max-width breakpoints
     * @param array $metadata_desktop Desktop metadata
     * @param array|null $metadata_mobile Mobile metadata
     * @param string $orig_ext_desktop Desktop extension
     * @param string|null $orig_ext_mobile Mobile extension
     * @param string $thumb_size_name Thumbnail size name
     * @param array $args Image arguments
     * @return string HTML output
     */
    private function build_picture($min, $max, $metadata_desktop, $metadata_mobile, $orig_ext_desktop, $orig_ext_mobile, $thumb_size_name, $args)
    {
        $sets = [
            'max' => $max,
            'min' => $min,
        ];

        $img_args = array_merge([
            'alt' => $this->build_alt($args, get_the_title($this->image_id)),
            'loading' => 'lazy',
            'decoding' => 'async',
        ], $args);

        // Get fallback image with filter
        list($fallback_variants, $fallback_image) = $this->collect_density_variants($this->image_id, $thumb_size_name);
        $fallback_srcset = $this->format_srcset($fallback_variants);

        $filter_callback = function ($attr) use ($fallback_srcset) {
            if ($fallback_srcset !== '') {
                $attr['srcset'] = $fallback_srcset;
                unset($attr['sizes']);
            } else {
                unset($attr['srcset'], $attr['sizes']);
            }
            return $attr;
        };

        add_filter('wp_get_attachment_image_attributes', $filter_callback, 999);
        $fallback_img = wp_get_attachment_image($this->image_id, $thumb_size_name, false, $img_args);
        if (!$fallback_img) {
            $fallback_img = wp_get_attachment_image($this->image_id, 'full', false, $img_args);
            if (!$fallback_img) {
                Logger::log("Failed to get fallback <img> for image ID: {$this->image_id}");
                remove_filter('wp_get_attachment_image_attributes', $filter_callback, 999);
                return '';
            }
        }
        remove_filter('wp_get_attachment_image_attributes', $filter_callback, 999);

        $output = '<picture>';

        foreach ($sets as $type => $breakpoints) {
            if (empty($breakpoints) || !is_array($breakpoints)) {
                Logger::log([
                    'context' => 'Renderer::build_picture',
                    'event' => 'skip_empty_breakpoints',
                    'type' => $type,
                ]);
                continue;
            }

            $widths = array_map('intval', array_keys($breakpoints));
            if ($type === 'min') {
                rsort($widths, SORT_NUMERIC);
            } else {
                sort($widths, SORT_NUMERIC);
            }

            foreach ($widths as $width) {
                $dim = $breakpoints[$width];
                if (!is_array($dim) || count($dim) !== 2) {
                    continue;
                }

                $use_mobile = ($this->mobile_image_id && $width <= Config::MOBILE_BREAKPOINT);
                $output_image_id = $use_mobile ? $this->mobile_image_id : $this->image_id;
                $current_meta = $use_mobile ? $metadata_mobile : $metadata_desktop;
                $current_orig_ext = $use_mobile ? $orig_ext_mobile : $orig_ext_desktop;

                if (!$current_meta) {
                    Logger::log([
                        'context' => 'Renderer::build_picture',
                        'event' => 'missing_metadata',
                        'type' => $type,
                        'width' => $width,
                        'use_mobile' => $use_mobile,
                    ]);
                    continue;
                }

                list($dest_width, $dest_height) = $this->downsize($output_image_id, $dim);
                $size_name = Config::THUMBNAIL_PREFIX . "{$dest_width}x{$dest_height}";

                list($density_variants, $source_image) = $this->collect_density_variants($output_image_id, $size_name);
                if (!$source_image || empty($source_image[0])) {
                    Logger::log([
                        'context' => 'Renderer::build_picture',
                        'event' => 'missing_source_image',
                        'type' => $type,
                        'width' => $width,
                        'size_name' => $size_name,
                    ]);
                    continue;
                }

                $size_metadata = isset($current_meta['sizes'][$size_name]) && is_array($current_meta['sizes'][$size_name])
                    ? $current_meta['sizes'][$size_name]
                    : [];

                $ext = isset($size_metadata['extension']) ? $size_metadata['extension'] : $current_orig_ext;
                $ext = $this->normalize_extension($ext);
                $type_attr = ($ext === 'webp') ? 'image/webp' : "image/{$ext}";

                $media = '(' . ($type === 'min' ? 'min' : 'max') . '-width: ' . esc_attr($width) . 'px)';

                if (!empty($size_metadata['webp'])) {
                    $webp_srcset = $this->format_srcset($density_variants);
                    if ($webp_srcset !== '') {
                        $output .= '<source media="' . $media . '" srcset="' . esc_attr($webp_srcset) . '" type="image/webp">';
                    }
                    if ($type_attr !== 'image/webp') {
                        $standard_srcset = $this->format_srcset($density_variants);
                        if ($standard_srcset !== '') {
                            $output .= '<source media="' . $media . '" srcset="' . esc_attr($standard_srcset) . '" type="' . esc_attr($type_attr) . '">';
                        }
                    }
                } else {
                    $standard_srcset = $this->format_srcset($density_variants);
                    if ($standard_srcset !== '') {
                        $output .= '<source media="' . $media . '" srcset="' . esc_attr($standard_srcset) . '" type="' . esc_attr($type_attr) . '">';
                    }
                }
            }
        }

        $output .= $fallback_img;
        $output .= '</picture>';

        Logger::log([
            'context' => 'Renderer::build_picture',
            'event' => 'build_complete',
            'fallback_srcset' => $fallback_srcset,
        ]);

        return $output;
    }

    /**
     * Collect density variants (1x/2x) for a given size
     *
     * @param int $attachment_id Attachment ID
     * @param string $size_name Size name
     * @return array Array with [variants, base_image]
     */
    private function collect_density_variants($attachment_id, $size_name)
    {
        $base_image = wp_get_attachment_image_src($attachment_id, $size_name);
        if (!$base_image || empty($base_image[0])) {
            return [[], null];
        }

        $variants = [[
            'url' => $base_image[0],
            'descriptor' => '1x',
        ]];

        $retina_image = wp_get_attachment_image_src($attachment_id, $size_name . '@2x');
        if ($retina_image && !empty($retina_image[0])) {
            $variants[] = [
                'url' => $retina_image[0],
                'descriptor' => '2x',
            ];
        }

        return [$variants, $base_image];
    }

    /**
     * Build a srcset string from density variants
     *
     * @param array $variants Array of ['url' => string, 'descriptor' => string]
     * @return string Srcset attribute contents
     */
    private function format_srcset(array $variants)
    {
        if (empty($variants)) {
            return '';
        }

        $parts = [];
        foreach ($variants as $variant) {
            if (empty($variant['url']) || empty($variant['descriptor'])) {
                continue;
            }
            $parts[] = esc_url($variant['url']) . ' ' . $variant['descriptor'];
        }

        return implode(', ', $parts);
    }

    /**
     * Get attachment metadata with caching
     *
     * @param int $attachment_id Attachment ID
     * @return array Metadata
     */
    private function get_attachment_metadata($attachment_id)
    {
        if (!isset($this->metadata_cache[$attachment_id])) {
            $cache_key = 'liteimage_meta_' . $attachment_id;
            $cached = get_transient($cache_key);

            if ($cached === false) {
                $this->metadata_cache[$attachment_id] = wp_get_attachment_metadata($attachment_id);
                set_transient($cache_key, $this->metadata_cache[$attachment_id], Config::CACHE_EXPIRATION);
            } else {
                $this->metadata_cache[$attachment_id] = $cached;
            }
        }
        return $this->metadata_cache[$attachment_id];
    }

    /**
     * Calculate proportional dimensions
     *
     * @param int $image_id Image ID
     * @param array $thumb Thumbnail size [width, height]
     * @return array [width, height]
     */
    private function calculate_proportional_dimensions($image_id, $thumb)
    {
        $cache_key = 'liteimage_dims_' . $image_id . '_' . implode('x', $thumb);
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $result = liteimage_calculate_proportional_dimensions($image_id, $thumb);
        set_transient($cache_key, $result, Config::CACHE_EXPIRATION);

        return $result;
    }

    /**
     * Downsize image dimensions
     *
     * @param int $id Attachment ID
     * @param array $size Size specification
     * @return array [width, height]
     */
    private function downsize($id, $size)
    {
        return liteimage_downsize($id, $size);
    }

    /**
     * Build alt text
     *
     * @param array $args Arguments
     * @param string $fallback_title Fallback title
     * @return string Alt text
     */
    private function build_alt($args, $fallback_title)
    {
        if (!empty($args['alt'])) {
            return $args['alt'];
        }
        if (!empty($args['decorative'])) {
            return '';
        }
        return $fallback_title;
    }

    /**
     * Build HTML attributes string
     *
     * @param array $attributes Attributes array
     * @return string Attributes string
     */
    private function build_attributes($attributes)
    {
        $output = '';
        foreach ($attributes as $key => $value) {
            if ($key === 'style' && is_string($value)) {
                $output .= ' style="' . esc_attr($value) . '"';
            } elseif ($key !== 'style') {
                $output .= ' ' . esc_attr($key) . '="' . esc_attr($value) . '"';
            }
        }
        return $output;
    }

    /**
     * Normalize file extension
     *
     * @param string $ext Extension
     * @return string Normalized extension
     */
    private function normalize_extension($ext)
    {
        $ext = strtolower((string) $ext);
        if ($ext === 'jpg') {
            $ext = 'jpeg';
        }
        return $ext;
    }

    /**
     * Get array value safely
     *
     * @param array $arr Array
     * @param string $key Key
     * @param mixed $default Default value
     * @return mixed Value or default
     */
    private function get_array_value($arr, $key, $default = null)
    {
        return (is_array($arr) && array_key_exists($key, $arr)) ? $arr[$key] : $default;
    }
}
