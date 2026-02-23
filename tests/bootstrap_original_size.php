<?php

/**
 * Bootstrap for OriginalSizeConversionTest: WordPress stubs and plugin load.
 * Required so that liteimage_downsize() and plugin code are available.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file)
    {
        return dirname($file) . '/';
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $callback)
    {
    }
}

if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $callback)
    {
    }
}

if (!function_exists('add_action')) {
    function add_action($tag, $callback, $priority = 10, $accepted_args = 1)
    {
    }
}

if (!function_exists('wp_get_attachment_metadata')) {
    function wp_get_attachment_metadata($id)
    {
        return $GLOBALS['_liteimage_test_meta'][$id] ?? ['width' => 0, 'height' => 0];
    }
}

if (!function_exists('image_constrain_size_for_editor')) {
    function image_constrain_size_for_editor($width, $height, $size)
    {
        return [$width, $height];
    }
}

if (!function_exists('add_image_size')) {
    function add_image_size($name, $width, $height, $crop = false)
    {
    }
}

if (!function_exists('image_get_intermediate_size')) {
    function image_get_intermediate_size($id, $size)
    {
        return false;
    }
}

// Do not load plugin when liteimage() is already defined (e.g. by LiteImageBlockTest stub)
if (!function_exists('liteimage')) {
    require_once dirname(__DIR__) . '/liteimage.php';
}
