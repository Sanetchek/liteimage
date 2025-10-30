<?php

/**
 * WebP Support class for LiteImage plugin
 *
 * @package LiteImage
 * @since 3.2.0
 */

namespace LiteImage\Support;

defined('ABSPATH') || exit;

/**
 * Class WebPSupport
 *
 * Checks for WebP support in the system
 */
class WebPSupport
{
    /**
     * Cached WebP support status
     *
     * @var bool|null
     */
    private static $webp_supported = null;

    /**
     * Check if WebP is supported
     *
     * @return bool True if WebP is supported, false otherwise
     */
    public static function is_webp_supported()
    {
        if (self::$webp_supported === null) {
            self::$webp_supported = function_exists('imagewebp') ||
                (class_exists('Imagick') && in_array('WEBP', \Imagick::queryFormats()));

            Logger::log("WebP support: " . (self::$webp_supported ? 'Enabled' : 'Disabled'));
        }
        return self::$webp_supported;
    }
}

// Backward compatibility alias
class_alias('LiteImage\Support\WebPSupport', 'LiteImage_WebP_Support');
