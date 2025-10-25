<?php
/*
Plugin Name: LiteImage
Description: Optimizes images with dynamic thumbnails, WebP support, and accessibility.
Version: 3.2
Author: Oleksandr Gryshko
Author URI: https://github.com/Sanetchek
Text Domain: liteimage
Domain Path: /languages
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires at least: 4.6
Requires PHP: 7.4
*/

// Exit if accessed directly
defined('ABSPATH') || exit;

// Load dependencies
require_once __DIR__ . '/vendor/autoload.php';

use Intervention\Image\ImageManagerStatic as Image;

// Constants
define('LITEIMAGE_DIR', plugin_dir_path(__FILE__));
define('LITEIMAGE_LOG_ACTIVE', false);

/**
 * Calculate proportional dimensions when one dimension is 0
 *
 * @param int $image_id The attachment ID
 * @param array $thumb Array with [width, height]
 * @return array Array with calculated [width, height]
 */
function liteimage_calculate_proportional_dimensions($image_id, $thumb) {
    $final_width = isset($thumb[0]) ? $thumb[0] : 0;
    $final_height = isset($thumb[1]) ? $thumb[1] : 0;

    // Get image metadata
    $image_meta = wp_get_attachment_metadata($image_id);

    if (!$image_meta || !isset($image_meta['width']) || !isset($image_meta['height'])) {
        // Try to get SVG dimensions from file content
        $file_path = get_attached_file($image_id);
        if ($file_path && file_exists($file_path)) {
            $svg_content = file_get_contents($file_path);
            if ($svg_content) {
                // Try to extract dimensions from SVG viewBox or width/height attributes
                if (preg_match('/viewBox=["\']([^"\']*)["\']/', $svg_content, $matches)) {
                    $viewBox = explode(' ', $matches[1]);
                    if (count($viewBox) >= 4) {
                        $original_width = floatval($viewBox[2]);
                        $original_height = floatval($viewBox[3]);
                    }
                } elseif (preg_match('/width=["\']([^"\']*)["\']/', $svg_content, $matches) &&
                          preg_match('/height=["\']([^"\']*)["\']/', $svg_content, $height_matches)) {
                    $original_width = floatval($matches[1]);
                    $original_height = floatval($height_matches[1]);
                }
            }
        }

        // If we still don't have dimensions, use fallback
        if (!isset($original_width) || !isset($original_height)) {
            if ($final_width == 0 && $final_height > 0) {
                $final_width = $final_height * 2; // Assume 2:1 ratio
            } elseif ($final_height == 0 && $final_width > 0) {
                $final_height = $final_width / 2; // Assume 2:1 ratio
            }
            return [$final_width, $final_height];
        }

        // Use extracted dimensions for calculation
        $image_meta = ['width' => $original_width, 'height' => $original_height];
    }

    $original_width = $image_meta['width'];
    $original_height = $image_meta['height'];

    // If width is 0, calculate proportional width based on height
    if ($final_width == 0 && $final_height > 0) {
        // If the requested height equals original height, use original width
        if ($final_height == $original_height) {
            $final_width = $original_width;
        } else {
            // Calculate proportional width
            $final_width = round(($original_width / $original_height) * $final_height);
        }
    }

    // If height is 0, calculate proportional height based on width
    if ($final_height == 0 && $final_width > 0) {
        // If the requested width equals original width, use original height
        if ($final_width == $original_width) {
            $final_height = $original_height;
        } else {
            // Calculate proportional height
            $final_height = round(($original_height / $original_width) * $final_width);
        }
    }

    return [$final_width, $final_height];
}

// Initialize settings
require_once LITEIMAGE_DIR . 'includes/settings.php';

// Logger class
require_once LITEIMAGE_DIR . 'includes/logger.php';

// WebP support checker
require_once LITEIMAGE_DIR . 'includes/webp-support.php';

// Thumbnail generator
require_once LITEIMAGE_DIR . 'includes/thumbnail-generator.php';

// Admin interface
require_once LITEIMAGE_DIR . 'includes/admin-page.php';

// Thumbnail cleaner
require_once LITEIMAGE_DIR . 'includes/thumbnail-cleaner.php';

// Core image rendering
function liteimage($image_id, $data = [], $mobile_image_id = null)
{
    if (!$image_id) {
        LiteImage_Logger::log("Invalid image ID: $image_id");
        return '';
    }

    // ---- Helpers ----
    $arr_get = function ($arr, $key, $default = null) {
        return (is_array($arr) && array_key_exists($key, $arr)) ? $arr[$key] : $default;
    };

    $normalize_ext = function ($ext) {
        $ext = strtolower((string) $ext);
        if ($ext === 'jpg')
            $ext = 'jpeg';
        return $ext;
    };

    $build_alt = function ($args, $fallback_title) {
        // Explicit alt wins
        if (!empty($args['alt'])) {
            return $args['alt'];
        }
        // Decorative image: empty alt
        if (!empty($args['decorative'])) {
            return '';
        }
        // Fallback: attachment title
        return $fallback_title;
    };

    // ---- Basic paths / extensions ----
    $file_path_desktop = get_attached_file($image_id);
    if (!$file_path_desktop) {
        LiteImage_Logger::log("Missing file path for image ID: $image_id");
        return '';
    }
    $orig_ext_desktop = strtolower(pathinfo($file_path_desktop, PATHINFO_EXTENSION));

    $file_path_mobile = ($mobile_image_id && $mobile_image_id !== $image_id) ? get_attached_file($mobile_image_id) : null;
    $orig_ext_mobile = $file_path_mobile ? strtolower(pathinfo($file_path_mobile, PATHINFO_EXTENSION)) : null;

    // ---- Fast path: vector/modern formats we don't process ----
    if (in_array($orig_ext_desktop, ['svg', 'avif'], true)) {
        $args = $arr_get($data, 'args', []);
        $thumb = $arr_get($data, 'thumb', 'full');

        // For SVG files, handle dimensions properly
        if ($orig_ext_desktop === 'svg') {
            $img_args = array_merge([
                'alt' => $build_alt($args, get_the_title($image_id)),
                'loading' => 'lazy',
                'decoding' => 'async',
            ], $args);

            // Get the image URL directly for SVG
            $image_url = wp_get_attachment_image_url($image_id, 'full');
            if (!$image_url) {
                LiteImage_Logger::log("Failed to get SVG URL for image ID: $image_id");
                return '';
            }

            // Build attributes string
            $attributes = '';
            foreach ($img_args as $key => $value) {
                if ($key === 'style' && is_string($value)) {
                    $attributes .= ' style="' . esc_attr($value) . '"';
                } elseif ($key !== 'style') {
                    $attributes .= ' ' . esc_attr($key) . '="' . esc_attr($value) . '"';
                }
            }

            // Add width and height attributes if thumb size is provided
            if (is_array($thumb)) {
                list($final_width, $final_height) = liteimage_calculate_proportional_dimensions($image_id, $thumb);

                // Add the calculated dimensions
                if ($final_width > 0) {
                    $attributes .= ' width="' . esc_attr($final_width) . '"';
                }
                if ($final_height > 0) {
                    $attributes .= ' height="' . esc_attr($final_height) . '"';
                }
            }

            return '<img src="' . esc_url($image_url) . '"' . $attributes . ' />';
        } else {
            // For other formats like AVIF, use standard WordPress function
            $img_args = array_merge([
                'alt' => $build_alt($args, get_the_title($image_id)),
                'loading' => 'lazy',
                'decoding' => 'async',
            ], $args);

            LiteImage_Logger::log("Skipping processing for {$orig_ext_desktop}: $file_path_desktop");
            return wp_get_attachment_image($image_id, ($thumb ?: 'full'), false, $img_args);
        }
    }

    // ---- Inputs ----
    $thumb = $arr_get($data, 'thumb', [1920, 0]); // [w,h] artificial size
    $args = $arr_get($data, 'args', []);
    $min = $arr_get($data, 'min', []); // mobile-first (we’ll render after max)
    $max = $arr_get($data, 'max', []); // desktop-first/mobile constraints (we’ll render first)

    // ---- Ensure arrays ----
    if (!is_array($min))
        $min = [];
    if (!is_array($max))
        $max = [];

    // ---- Compute thumbnail size key for fallback <img> ----
    $thumb_data = LiteImage_Thumbnail_Generator::get_thumb_size($thumb, $image_id);
    $thumb_size_name = $thumb_data['size_name'];

    // ---- Build generation maps for desktop & mobile ----
    $sizes_to_generate_desktop = [$thumb_size_name => [$thumb_data['width'], $thumb_data['height']]];
    $sizes_to_generate_mobile = [];

    // Prepare a function to add sizes to a map
    $add_size = function (&$map, $key, $dim) {
        if (!is_array($dim) || count($dim) !== 2)
            return;
        $w = (int) $dim[0];
        $h = (int) $dim[1];
        if ($w < 0 || $h < 0)
            return;
        $map[$key] = [$w, $h];
    };

    // Desktop sizes: all min/max entries go here if they will be served from desktop image
    foreach (['min' => $min, 'max' => $max] as $type => $sizes) {
        foreach ($sizes as $width => $dim) {
            $width = (int) $width;
            $is_mobile_breakpoint = ($width > 0 && $width <= 768) || ($type === 'max' && $width <= 768);
            // If mobile exists, sub-768 sizes belong to mobile; else they stay on desktop
            if ($mobile_image_id && $is_mobile_breakpoint) {
                $add_size($sizes_to_generate_mobile, $type . '-' . $width, $dim);
            } else {
                $add_size($sizes_to_generate_desktop, $type . '-' . $width, $dim);
            }
        }
    }

    // ---- Generate thumbnails (desktop first, then mobile) ----
    if (!empty($sizes_to_generate_desktop)) {
        LiteImage_Thumbnail_Generator::generate_thumbnails($image_id, $file_path_desktop, $sizes_to_generate_desktop);
    }
    if ($file_path_mobile && !empty($sizes_to_generate_mobile)) {
        LiteImage_Thumbnail_Generator::generate_thumbnails($mobile_image_id, $file_path_mobile, $sizes_to_generate_mobile);
    }

    // ---- Refresh metadata AFTER generation ----
    $metadata_desktop = wp_get_attachment_metadata($image_id);
    $metadata_mobile = ($mobile_image_id && $mobile_image_id !== $image_id) ? wp_get_attachment_metadata($mobile_image_id) : null;

    // ---- Build <picture> ----
    // NOTE: We intentionally render `max` first (mobile-first constraints like max-width ascending),
    // then `min` (desktop expansions). This reduces "wrong source grabbed too early" issues.
    $sets = [
        'max' => $max,
        'min' => $min,
    ];

    // Fallback <img> args
    $img_args = array_merge([
        'alt' => $build_alt($args, get_the_title($image_id)),
        'loading' => 'lazy',
        'decoding' => 'async',
    ], $args);

    // Allow attribute filter (kept from your original)
    add_filter('wp_get_attachment_image_attributes', 'liteimage_filter_image_attributes', 999, 3);
    // Ensure we can get fallback <img> (WP will produce <img> with srcset if the size exists)
    $fallback_img = wp_get_attachment_image($image_id, $thumb_size_name, false, $img_args);
    if (!$fallback_img) {
        // fall back to full if custom size not found for some reason
        $fallback_img = wp_get_attachment_image($image_id, 'full', false, $img_args);
        if (!$fallback_img) {
            LiteImage_Logger::log("Failed to get fallback <img> for image ID: $image_id");
            return '';
        }
    }
    remove_filter('wp_get_attachment_image_attributes', 'liteimage_filter_image_attributes', 999);

    $output = '<picture>';

    foreach ($sets as $type => $breakpoints) {
        if (empty($breakpoints) || !is_array($breakpoints)) {
            continue;
        }

        // Sort widths
        $widths = array_map('intval', array_keys($breakpoints));
        if ($type === 'min') {
            rsort($widths, SORT_NUMERIC); // 1440, 1200, 992, 768...
        } else {
            sort($widths, SORT_NUMERIC);  // 320, 480, 767, 991...
        }

        foreach ($widths as $width) {
            $dim = $breakpoints[$width];
            if (!is_array($dim) || count($dim) !== 2)
                continue;

            // Decide which attachment to use at this breakpoint
            $use_mobile = ($mobile_image_id && $width <= 768);
            $output_image_id = $use_mobile ? $mobile_image_id : $image_id;
            $current_meta = $use_mobile ? $metadata_mobile : $metadata_desktop;
            $current_orig_ext = $use_mobile ? $orig_ext_mobile : $orig_ext_desktop;

            if (!$current_meta)
                continue;

            // Compute real target size (align with your generator)
            list($dest_width, $dest_height) = liteimage_downsize($output_image_id, $dim);
            $size_name = "liteimage-{$dest_width}x{$dest_height}";

            // Pull the chosen image URL
            $source_image = wp_get_attachment_image_src($output_image_id, $size_name);
            if (!$source_image) {
                // If size metadata isn't there (rare timing), skip this source
                continue;
            }

            // Size metadata for extension/webp
            $size_metadata = (isset($current_meta['sizes'][$size_name]) && is_array($current_meta['sizes'][$size_name]))
                ? $current_meta['sizes'][$size_name]
                : [];

            // MIME type
            $ext = isset($size_metadata['extension']) ? $size_metadata['extension'] : $current_orig_ext;
            $ext = $normalize_ext($ext);
            $type_attr = ($ext === 'webp') ? 'image/webp' : "image/{$ext}";

            // Media condition
            $media = '(' . ($type === 'min' ? 'min' : 'max') . '-width: ' . esc_attr($width) . 'px)';

            // Prefer generated webp if present in metadata
            if (!empty($size_metadata['webp'])) {
                $webp_filename = $size_metadata['webp'];
                $webp_url = str_replace(basename($source_image[0]), $webp_filename, $source_image[0]);
                $output .= '<source media="' . $media . '" srcset="' . esc_url($webp_url) . '" type="image/webp">';
                // Also add non-webp fallback for this media if original isn't webp
                if ($type_attr !== 'image/webp') {
                    $output .= '<source media="' . $media . '" srcset="' . esc_url($source_image[0]) . '" type="' . esc_attr($type_attr) . '">';
                }
            } else {
                $output .= '<source media="' . $media . '" srcset="' . esc_url($source_image[0]) . '" type="' . esc_attr($type_attr) . '">';
            }
        }
    }

    // Append fallback <img> and close
    $output .= $fallback_img;
    $output .= '</picture>';

    return $output;
}

function liteimage_filter_image_attributes($attr, $attachment, $size) {
    unset($attr['srcset'], $attr['sizes']);
    return $attr;
}

// Downsize function
function liteimage_downsize($id, $size = 'medium') {
    $meta = wp_get_attachment_metadata($id);
    $orig_width = $meta['width'] ? $meta['width'] : 0;
    $orig_height = $meta['height'] ? $meta['height'] : 0;

    if (is_array($size) && isset($size[0], $size[1])) {
        $width = $size[0];
        $height = $size[1];

        // If only one dimension is provided, resize proportionally
        if ($width && !$height) {
            $height = (int) round(($orig_height / $orig_width) * $width);
        } elseif ($height && !$width) {
            $width = (int) round(($orig_width / $orig_height) * $height);
        }

        // If both dimensions are provided, crop
        $crop = ($width && $height);
        return image_constrain_size_for_editor($width, $height, $crop ? [$width, $height] : 'medium');
    }

    // Fallback to existing logic for non-array sizes
    if ($intermediate = image_get_intermediate_size($id, $size)) {
        return [$intermediate['width'], $intermediate['height']];
    }

    return [$orig_width, $orig_height];
}

// Fallback function
if (!function_exists('liteimage')) {
    function liteimage($image_id, $data = [], $mobile_image_id = null) {
        return apply_filters('liteimage_disabled_fallback', '', $image_id, $data, $mobile_image_id);
    }
}

// Initialize plugin
LiteImage_Settings::get_instance();
LiteImage_Admin::init();