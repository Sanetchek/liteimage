<?php

/**
 * Settings class for LiteImage plugin
 *
 * @package LiteImage
 * @since 3.2.0
 */

namespace LiteImage\Admin;

use function add_filter;
use function get_option;
use function update_option;

defined('ABSPATH') || exit;

/**
 * Class Settings
 *
 * Manages plugin settings
 */
class Settings
{
    public const DEFAULT_THUMBNAIL_QUALITY = 75;
    public const DEFAULT_SMART_COMPRESSION_ENABLED = true;
    public const DEFAULT_SMART_MIN_QUALITY = 75;
    public const DEFAULT_SMART_TARGET_PSNR = 41.5;
    public const DEFAULT_SMART_MAX_ITERATIONS = 10;
    public const DEFAULT_SMART_MIN_SAVINGS_PERCENT = 8.0;

    /**
     * Singleton instance
     *
     * @var Settings|null
     */
    private static $instance = null;

    /**
     * Settings array
     *
     * @var array
     */
    private $settings;

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->settings = \get_option('liteimage_settings', [
            'disable_thumbnails' => false,
            'show_donation' => true,
            'convert_to_webp' => true,
            'thumbnail_quality' => self::DEFAULT_THUMBNAIL_QUALITY,
            'smart_compression_enabled' => self::DEFAULT_SMART_COMPRESSION_ENABLED,
            'smart_target_psnr' => self::DEFAULT_SMART_TARGET_PSNR,
            'smart_min_quality' => self::DEFAULT_SMART_MIN_QUALITY,
            'smart_max_iterations' => self::DEFAULT_SMART_MAX_ITERATIONS,
            'smart_min_savings_percent' => self::DEFAULT_SMART_MIN_SAVINGS_PERCENT,
        ]);
        $this->settings['disable_thumbnails'] = !empty($this->settings['disable_thumbnails']);
        $this->settings['show_donation'] = !empty($this->settings['show_donation']);
        $this->settings['convert_to_webp'] = !empty($this->settings['convert_to_webp']);
        $this->settings['thumbnail_quality'] = self::DEFAULT_THUMBNAIL_QUALITY;
        $this->settings['smart_compression_enabled'] = isset($this->settings['smart_compression_enabled'])
            ? (bool) $this->settings['smart_compression_enabled']
            : self::DEFAULT_SMART_COMPRESSION_ENABLED;
        $this->settings['smart_target_psnr'] = self::DEFAULT_SMART_TARGET_PSNR;
        $this->settings['smart_min_quality'] = self::DEFAULT_SMART_MIN_QUALITY;
        $this->settings['smart_max_iterations'] = self::DEFAULT_SMART_MAX_ITERATIONS;
        $this->settings['smart_min_savings_percent'] = self::DEFAULT_SMART_MIN_SAVINGS_PERCENT;
        if ($this->settings['disable_thumbnails']) {
            \add_filter('intermediate_image_sizes_advanced', '__return_empty_array');
        }
    }

    /**
     * Get singleton instance
     *
     * @return Settings
     */
    public static function get_instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get a setting value
     *
     * @param string $key Setting key
     * @return mixed|null Setting value or null if not found
     */
    public function get($key)
    {
        return $this->settings[$key] ?? null;
    }

    /**
     * Set a setting value
     *
     * @param string $key Setting key
     * @param mixed $value Setting value
     * @return void
     */
    public function set($key, $value)
    {
        $this->settings[$key] = $value;
    }

    /**
     * Save settings to database
     *
     * @return bool
     */
    public function save()
    {
        return \update_option('liteimage_settings', $this->settings);
    }

    public static function sanitize_settings($input)
    {
        $existing = \get_option('liteimage_settings', []);
        $smartCompressionEnabled = array_key_exists('smart_compression_enabled', $input)
            ? !empty($input['smart_compression_enabled'])
            : (isset($existing['smart_compression_enabled'])
                ? (bool) $existing['smart_compression_enabled']
                : self::DEFAULT_SMART_COMPRESSION_ENABLED);
        return [
            'disable_thumbnails' => !empty($input['disable_thumbnails']),
            'show_donation' => !empty($input['show_donation']),
            'convert_to_webp' => !empty($input['convert_to_webp']),
            'thumbnail_quality' => self::DEFAULT_THUMBNAIL_QUALITY,
            'smart_compression_enabled' => $smartCompressionEnabled,
            'smart_target_psnr' => self::DEFAULT_SMART_TARGET_PSNR,
            'smart_min_quality' => self::DEFAULT_SMART_MIN_QUALITY,
            'smart_max_iterations' => self::DEFAULT_SMART_MAX_ITERATIONS,
            'smart_min_savings_percent' => self::DEFAULT_SMART_MIN_SAVINGS_PERCENT,
        ];
    }
}

// Backward compatibility alias
class_alias('LiteImage\Admin\Settings', 'LiteImage_Settings');
