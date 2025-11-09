<?php

/*
Plugin Name: LiteImage
Description: Optimizes images with dynamic thumbnails, WebP support, and accessibility.
Version: 3.3.0
Author: Oleksandr Gryshko
Author URI: https://github.com/Sanetchek
Text Domain: liteimage
Domain Path: /languages
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires at least: 5.8
Requires PHP: 8.0
*/

// Exit if accessed directly
defined('ABSPATH') || exit;

// Load dependencies
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>LiteImage Error:</strong> Composer dependencies not found. ';
        echo 'Please run <code>composer install</code> in the plugin directory.';
        echo '</p></div>';
    });
    return;
}
require_once __DIR__ . '/vendor/autoload.php';

use LiteImage\Plugin;
use LiteImage\Image\Renderer;

// Constants
define('LITEIMAGE_DIR', plugin_dir_path(__FILE__));
define('LITEIMAGE_LOG_ACTIVE', true);

// Register activation/deactivation hooks
register_activation_hook(__FILE__, ['LiteImage\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['LiteImage\Plugin', 'deactivate']);

/**
 * Calculate proportional dimensions when one dimension is 0
 *
 * @param int $image_id The attachment ID
 * @param array $thumb Array with [width, height]
 * @return array Array with calculated [width, height]
 */
function liteimage_calculate_proportional_dimensions($image_id, $thumb)
{
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
                } elseif (
                    preg_match('/width=["\']([^"\']*)["\']/', $svg_content, $matches) &&
                          preg_match('/height=["\']([^"\']*)["\']/', $svg_content, $height_matches)
                ) {
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

// Old includes directory removed - all classes now in src/ with namespaces

/**
 * Core image rendering function
 *
 * Wrapper function for backward compatibility
 * Uses the new Renderer class
 *
 * @param int $image_id Image attachment ID
 * @param array $data Configuration data
 * @param int|null $mobile_image_id Optional mobile image ID
 * @return string HTML output
 */
function liteimage($image_id, $data = [], $mobile_image_id = null)
{
    $renderer = new Renderer($image_id, $data, $mobile_image_id);
    return $renderer->render();
}

// Downsize function
function liteimage_downsize($id, $size = 'medium')
{
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

// Initialize plugin
Plugin::get_instance();
