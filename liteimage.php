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
function liteimage($image_id, $data = [], $mobile_image_id = null) {
    if (!$image_id) {
        LiteImage_Logger::log("Invalid image ID: $image_id");
        return '';
    }

    $file_path = get_attached_file($image_id);
    $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    if (in_array($extension, ['svg', 'avif'])) {
        $args = $data['args'] ?? [];
        $img_args = array_merge([
            'alt' => !empty($args['alt']) ? $args['alt'] : ($args['decorative'] ?? false ? '' : get_the_title($image_id)),
            'loading' => 'lazy',
            'decoding' => 'async'
        ], $args);

        LiteImage_Logger::log("Skipping processing for $extension: $file_path");
        return wp_get_attachment_image($image_id, ($data['thumb'] ?? 'full'), false, $img_args);
    }

    $thumb = $data['thumb'] ?? [1920, 0];
    $args = $data['args'] ?? [];
    $min = $data['min'] ?? [];
    $max = $data['max'] ?? [];

    $thumb_data = LiteImage_Thumbnail_Generator::get_thumb_size($thumb, $image_id);
    $sizes_to_generate = [$thumb_data['size_name'] => [$thumb_data['width'], $thumb_data['height']]];

    foreach (['min' => $min, 'max' => $max] as $type => $sizes) {
        foreach ($sizes as $width => $dim) {
            $output_image_id = ($type === 'min' && $width > 0 && $width < 768 || $type === 'max' && $width < 768) && $mobile_image_id ? $mobile_image_id : $image_id;
            if ($output_image_id === $image_id) {
                $sizes_to_generate[$type . '-' . $width] = $dim;
            }
        }
    }

    $generated_size = LiteImage_Thumbnail_Generator::generate_thumbnails($image_id, $file_path, $sizes_to_generate);
    $metadata = wp_get_attachment_metadata($image_id);
    $original_extension = $extension;

    $thumb_size_name = $thumb_data['size_name'];

    if ($mobile_image_id && $mobile_image_id !== $image_id) {
        $mobile_sizes = [];
        foreach (['min' => $min, 'max' => $max] as $type => $sizes) {
            foreach ($sizes as $width => $dim) {
                if (($type === 'min' && $width > 0 && $width < 768) || ($type === 'max' && $width < 768)) {
                    $mobile_sizes[$type . '-' . $width] = $dim;
                }
            }
        }
        if ($mobile_sizes) {
            LiteImage_Thumbnail_Generator::generate_thumbnails($mobile_image_id, get_attached_file($mobile_image_id), $mobile_sizes);
        }
    }

    $image = wp_get_attachment_image($image_id, $thumb_size_name);
    if (!$image) {
        return '';
    }

    $output = '<picture role="img">';

    $sets = [
        'min' => $min, // Mobile-First: There will be RSORT
        'max' => $max, // Desktop-First: There will be Sort
    ];

    foreach ($sets as $type => $data) {
        if (empty($data) || !is_array($data)) {
            continue;
        }

        // We sort the width in the correct order
        $widths = array_map('intval', array_keys($data));
        if ($type === 'min') {
            rsort($widths, SORT_NUMERIC); // 1440,1200,992,768...
        } else {
            sort($widths, SORT_NUMERIC);  // 480,767,991,1199...
        }

        foreach ($widths as $width) {
            $dim = $data[$width];

            // Явные скобки, чтобы приоритет операторов не стрелял в ногу
            $use_mobile = ($mobile_image_id && $width < 768);
            $output_image_id = $use_mobile ? $mobile_image_id : $image_id;

            $size_key = $type . '-' . $width;

            list($dest_width, $dest_height) = liteimage_downsize($output_image_id, $dim);
            $size_name = "liteimage-{$dest_width}x{$dest_height}";
            $source_image = wp_get_attachment_image_src($output_image_id, $size_name);

            if (!$source_image) {
                continue;
            }

            // Метаданные размера (аккуратно читаем)
            $size_metadata = isset($metadata['sizes'][$size_name]) && is_array($metadata['sizes'][$size_name])
                ? $metadata['sizes'][$size_name]
                : [];

            $extension = isset($size_metadata['extension']) ? $size_metadata['extension'] : $original_extension;
            $type_attr = ($extension === 'webp') ? 'image/webp' : "image/{$extension}";

            // Формируем media-условие
            $media = '(' . ($type === 'min' ? 'min' : 'max') . '-width: ' . esc_attr($width) . 'px)';

            // Если генератор сделал webp-имя файла — подменяем url
            if (!empty($size_metadata['webp'])) {
                $webp_filename = $size_metadata['webp'];
                $webp_url = str_replace(basename($source_image[0]), $webp_filename, $source_image[0]);
                $output .= '<source media="' . $media . '" srcset="' . esc_url($webp_url) . '" type="image/webp">';
            } else {
                $output .= '<source media="' . $media . '" srcset="' . esc_url($source_image[0]) . '" type="' . esc_attr($type_attr) . '">';
            }
        }
    }

    $img_args = array_merge([
        'alt' => !empty($args['alt']) ? $args['alt'] : ($args['decorative'] ?? false ? '' : get_the_title($image_id)),
        'loading' => 'lazy',
        'decoding' => 'async',
    ], $args);

    add_filter('wp_get_attachment_image_attributes', 'liteimage_filter_image_attributes', 999, 3);

    $output .= wp_get_attachment_image($image_id, $thumb_size_name, false, $img_args);
    $output .= '</picture>';

    remove_filter('wp_get_attachment_image_attributes', 'liteimage_filter_image_attributes', 999);

    return $output;
}

function liteimage_filter_image_attributes($attr, $attachment, $size) {
    unset($attr['srcset'], $attr['sizes']);
    return $attr;
}

// Downsize function
function liteimage_downsize($id, $size = 'medium') {
    $meta = wp_get_attachment_metadata($id);
    $orig_width = $meta['width'] ?? 0;
    $orig_height = $meta['height'] ?? 0;

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