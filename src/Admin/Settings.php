<?php

/**
 * Settings class for LiteImage plugin
 *
 * @package LiteImage
 * @since 3.2.0
 */

namespace LiteImage\Admin;

defined('ABSPATH') || exit;

/**
 * Class Settings
 *
 * Manages plugin settings
 */
class Settings
{
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
        $this->settings = get_option('liteimage_settings', [
            'disable_thumbnails' => false,
            'show_donation' => true,
            'convert_to_webp' => true,
            'thumbnail_quality' => 85,
        ]);
        if (!isset($this->settings['convert_to_webp'])) {
            $this->settings['convert_to_webp'] = true;
        }
        if (!isset($this->settings['thumbnail_quality']) || !is_numeric($this->settings['thumbnail_quality'])) {
            $this->settings['thumbnail_quality'] = 85;
        }
        if ($this->settings['disable_thumbnails']) {
            add_filter('intermediate_image_sizes_advanced', '__return_empty_array');
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
        return update_option('liteimage_settings', $this->settings);
    }

    public static function sanitize_settings($input)
    {
        // Filter/validate thumbnail quality
        $quality = isset($input['thumbnail_quality']) && is_numeric($input['thumbnail_quality']) ? intval($input['thumbnail_quality']) : 85;
        if ($quality < 60) {
            $quality = 60;
        }
        if ($quality > 100) {
            $quality = 100;
        }
        return [
            'disable_thumbnails' => !empty($input['disable_thumbnails']),
            'show_donation' => !empty($input['show_donation']),
            'convert_to_webp' => isset($input['convert_to_webp']) && $input['convert_to_webp'] ? true : false,
            'thumbnail_quality' => $quality,
        ];
    }
}

// Backward compatibility alias
class_alias('LiteImage\Admin\Settings', 'LiteImage_Settings');
