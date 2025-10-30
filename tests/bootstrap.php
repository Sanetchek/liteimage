<?php
/**
 * PHPUnit bootstrap file for LiteImage tests
 *
 * @package LiteImage
 */

// Composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Define WordPress constants for testing
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
}

if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

// Define plugin constants
define('LITEIMAGE_DIR', dirname(__DIR__) . '/');
define('LITEIMAGE_LOG_ACTIVE', false);

// Mock WordPress functions for testing
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $args = 1) { return true; }
}
if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $args = 1) { return true; }
}
if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $callback) { return true; }
}
if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $callback) { return true; }
}
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) { return __DIR__ . '/../'; }
}
if (!function_exists('plugin_basename')) {
    function plugin_basename($file) { return 'liteimage/liteimage.php'; }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = null) { return $default; }
}
if (!function_exists('update_option')) {
    function update_option($key, $value) { return true; }
}
if (!function_exists('add_option')) {
    function add_option($key, $value, $deprecated = '', $autoload = 'yes') { return true; }
}
if (!function_exists('delete_option')) {
    function delete_option($key) { return true; }
}
if (!function_exists('load_plugin_textdomain')) {
    function load_plugin_textdomain($domain, $path = false, $plugin_rel_path = false) { return true; }
}
if (!function_exists('is_admin')) {
    function is_admin() { return false; }
}
if (!function_exists('get_transient')) {
    function get_transient($key) { return false; }
}
if (!function_exists('set_transient')) {
    function set_transient($key, $value, $expiration = 0) { return true; }
}
if (!function_exists('delete_transient')) {
    function delete_transient($key) { return true; }
}
if (!function_exists('wp_get_attachment_metadata')) {
    function wp_get_attachment_metadata($id) { return ['width' => 1920, 'height' => 1080]; }
}
if (!function_exists('get_attached_file')) {
    function get_attached_file($id) { return '/path/to/image-' . $id . '.jpg'; }
}
if (!function_exists('image_constrain_size_for_editor')) {
    function image_constrain_size_for_editor($width, $height, $size) {
        return [$width, $height];
    }
}
if (!function_exists('image_get_intermediate_size')) {
    function image_get_intermediate_size($id, $size) {
        return ['width' => 800, 'height' => 600];
    }
}
if (!function_exists('liteimage_downsize')) {
    function liteimage_downsize($id, $size = 'medium') {
        if (is_array($size) && isset($size[0], $size[1])) {
            return [$size[0], $size[1]];
        }
        return [800, 600];
    }
}
if (!function_exists('liteimage_calculate_proportional_dimensions')) {
    function liteimage_calculate_proportional_dimensions($image_id, $thumb) {
        if (!is_array($thumb) || !isset($thumb[0], $thumb[1])) {
            return [0, 0];
        }
        // Return proportional size for zero height
        if ($thumb[0] > 0 && $thumb[1] == 0) {
            return [$thumb[0], (int)($thumb[0] * 0.5625)]; // 16:9
        }
        // Return proportional size for zero width
        if ($thumb[0] == 0 && $thumb[1] > 0) {
            return [(int)($thumb[1] * 1.7778), $thumb[1]]; // 16:9
        }
        return $thumb;
    }
}
if (!function_exists('add_image_size')) {
    function add_image_size($name, $width, $height, $crop = false) { return true; }
}

