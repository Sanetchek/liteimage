<?php
defined('ABSPATH') || exit;

class LiteImage_Settings {
    private static $instance = null;
    private $settings;

    private function __construct() {
        $this->settings = get_option('liteimage_settings', [
            'disable_thumbnails' => false,
            'show_donation' => true,
        ]);
        if ($this->settings['disable_thumbnails']) {
            add_filter('intermediate_image_sizes_advanced', '__return_empty_array');
        }
    }

    public static function get_instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get($key) {
        return $this->settings[$key] ? $this->settings[$key] : null;
    }
}