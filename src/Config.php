<?php

/**
 * Configuration class for LiteImage plugin
 *
 * @package LiteImage
 * @since 3.2.0
 */

namespace LiteImage;

defined('ABSPATH') || exit;

/**
 * Class Config
 *
 * Stores all configuration constants for the plugin
 */
class Config
{
    /**
     * Mobile breakpoint in pixels
     * Images below this width will use mobile versions if available
     */
    const MOBILE_BREAKPOINT = 768;

    /**
     * WebP quality (0-100)
     */
    const WEBP_QUALITY = 85;

    /**
     * Thumbnail size name prefix
     */
    const THUMBNAIL_PREFIX = 'liteimage-';

    /**
     * Log date format
     */
    const LOG_DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * Items to process per batch in cleanup operations
     */
    const CLEANUP_BATCH_SIZE = 50;

    /**
     * Rate limiting time in seconds
     */
    const RATE_LIMIT_SECONDS = 60;

    /**
     * Cache expiration time for dimension calculations (in seconds)
     */
    const CACHE_EXPIRATION = DAY_IN_SECONDS;

    /**
     * Allowed image MIME types for processing
     */
    const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
    ];
}
