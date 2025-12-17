<?php

/**
 * Main Plugin class for LiteImage
 *
 * @package LiteImage
 * @since 3.2.0
 */

namespace LiteImage;

use LiteImage\Admin\AdminPage;
use LiteImage\Admin\Settings;
use LiteImage\Blocks\LiteImageBlock;

defined('ABSPATH') || exit;

/**
 * Class Plugin
 *
 * Main plugin class - entry point for the plugin
 */
class Plugin
{
    /**
     * Singleton instance
     *
     * @var Plugin|null
     */
    private static $instance = null;

    /**
     * Plugin version
     *
     * @var string
     */
    const VERSION = '3.3.1';

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->init_hooks();
    }

    /**
     * Get singleton instance
     *
     * @return Plugin
     */
    public static function get_instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize hooks
     *
     * @return void
     */
    private function init_hooks()
    {
        add_action('plugins_loaded', [$this, 'init']);
        add_action('wp_update_attachment_metadata', [$this, 'clear_attachment_cache'], 10, 2);
    }

    /**
     * Initialize plugin
     *
     * @return void
     */
    public function init()
    {
        // Initialize settings
        Settings::get_instance();

        add_action('init', [LiteImageBlock::class, 'register']);

        // Initialize admin
        if (is_admin()) {
            AdminPage::init();
        }
    }

    /**
     * Clear cache when attachment metadata is updated
     *
     * @param array $data Attachment metadata
     * @param int $attachment_id Attachment ID
     * @return array Unmodified metadata
     */
    public function clear_attachment_cache($data, $attachment_id)
    {
        // Clear metadata cache
        delete_transient('liteimage_meta_' . $attachment_id);

        // Clear dimension caches for this attachment
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transient cleanup requires direct DB access
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like('_transient_liteimage_dims_' . $attachment_id) . '%'
            )
        );

        return $data;
    }

    /**
     * Plugin activation
     *
     * @return void
     */
    public static function activate()
    {
        // Create default options
        add_option('liteimage_settings', [
            'disable_thumbnails' => false,
            'show_donation' => true,
        ]);
    }

    /**
     * Plugin deactivation
     *
     * @return void
     */
    public static function deactivate()
    {
        // Cleanup transients on deactivation
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transient cleanup requires direct DB access
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_liteimage_%' OR option_name LIKE '_transient_timeout_liteimage_%'"
        );
    }
}
