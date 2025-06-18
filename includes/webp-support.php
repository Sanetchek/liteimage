<?php
defined('ABSPATH') || exit;

class LiteImage_WebP_Support {
    private static $webp_supported = null;

    public static function is_webp_supported() {
        if (self::$webp_supported === null) {
            self::$webp_supported = function_exists('imagewebp') || (class_exists('Imagick') && in_array('WEBP', Imagick::queryFormats()));
            LiteImage_Logger::log("WebP support: " . (self::$webp_supported ? 'Enabled' : 'Disabled'));
        }
        return self::$webp_supported;
    }
}