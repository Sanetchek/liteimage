<?php

/**
 * Admin Page class for LiteImage plugin
 *
 * @package LiteImage
 * @since 3.2.0
 */

namespace LiteImage\Admin;

use LiteImage\Config;
use LiteImage\Image\SmartCompressionTelemetry;
use LiteImage\Image\ThumbnailCleaner;
use LiteImage\Support\WebPSupport;

defined('ABSPATH') || exit;

/**
 * Class AdminPage
 *
 * Handles admin interface
 */
class AdminPage
{
    /**
     * Initialize admin functionality
     *
     * @return void
     */
    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'add_settings_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_notices', [__CLASS__, 'show_admin_notices']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_scripts']);
        add_filter('plugin_action_links_' . plugin_basename(LITEIMAGE_DIR . 'liteimage.php'), [__CLASS__, 'add_settings_link']);
        add_filter('manage_media_columns', [__CLASS__, 'add_thumbnail_sizes_column']);
        add_action('manage_media_custom_column', [__CLASS__, 'display_thumbnail_sizes_column'], 10, 2);

        // AJAX actions for clearing thumbnails
        add_action('wp_ajax_liteimage_clear_thumbnails', [__CLASS__, 'ajax_clear_liteimage_thumbnails']);
        add_action('wp_ajax_liteimage_clear_wp_thumbnails', [__CLASS__, 'ajax_clear_wp_thumbnails']);
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page hook
     * @return void
     */
    public static function enqueue_admin_scripts($hook)
    {
        // Only load on our settings page
        if ($hook !== 'tools_page_liteimage-settings') {
            return;
        }

        // Enqueue modern flat CSS for LiteImage
        wp_enqueue_style(
            'liteimage-admin-style',
            plugins_url('assets/css/admin.css', LITEIMAGE_DIR . 'liteimage.php'),
            [],
            \LiteImage\Plugin::VERSION
        );

        wp_enqueue_script(
            'liteimage-admin',
            plugins_url('assets/js/admin.js', LITEIMAGE_DIR . 'liteimage.php'),
            [],
            \LiteImage\Plugin::VERSION,
            true
        );

        // Provide AJAX data to admin script
        wp_localize_script(
            'liteimage-admin',
            'LiteImageAdmin',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonceClearLite' => wp_create_nonce('liteimage_clear_thumbnails'),
                'nonceClearWp' => wp_create_nonce('liteimage_clear_wp_thumbnails'),
                'i18n' => [
                    'inProgress' => __('Processing...', 'liteimage'),
                    'done' => __('Done', 'liteimage'),
                    'error' => __('Error', 'liteimage'),
                ],
            ]
        );
    }

    /**
     * Add settings link to plugin actions
     *
     * @param array $links Plugin action links
     * @return array Modified links
     */
    public static function add_settings_link($links)
    {
        array_unshift($links, '<a href="' . admin_url('tools.php?page=liteimage-settings') . '">' . __('Settings', 'liteimage') . '</a>');
        return $links;
    }

    /**
     * Add settings page to admin menu
     *
     * @return void
     */
    public static function add_settings_page()
    {
        add_submenu_page(
            'tools.php',
            __('LiteImage Settings', 'liteimage'),
            __('LiteImage Settings', 'liteimage'),
            'manage_options',
            'liteimage-settings',
            [__CLASS__, 'render_settings_page']
        );
    }

    /**
     * Register plugin settings
     *
     * @return void
     */
    public static function register_settings()
    {
        register_setting('liteimage_settings_group', 'liteimage_settings', [
            'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
        ]);
    }

    /**
     * Sanitize settings input
     *
     * @param array $input Input settings
     * @return array Sanitized settings
     */
    public static function sanitize_settings($input)
    {
        return Settings::sanitize_settings($input);
    }

    /**
     * Show admin notices
     *
     * @return void
     */
    public static function show_admin_notices()
    {
        if (get_current_screen()->id !== 'tools_page_liteimage-settings') {
            return;
        }

        if (!WebPSupport::is_webp_supported()) {
            echo '<div class="notice notice-warning"><p>' .
                 esc_html__('WebP conversion requires GD or Imagick with WebP support. Using compressed JPEG/PNG.', 'liteimage') .
                 '</p></div>';
        }
    }

    /**
     * Render settings page
     *
     * @return void
     */
    public static function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = Settings::get_instance();
        $user_id = get_current_user_id();
        $rate_limit_key_liteimage = 'liteimage_cleanup_liteimage_' . $user_id;
        $rate_limit_key_wp = 'liteimage_cleanup_wp_' . $user_id;

        // Таб навигация (4 flat таба)
        $allowed_tabs = ['general', 'stats', 'webp', 'usage'];
        $tab_labels = [
            'general' => __('General', 'liteimage'),
            'stats'   => __('Savings & Reports', 'liteimage'),
            'webp'    => __('WebP Status', 'liteimage'),
            'usage'   => __('Usage Instructions', 'liteimage'),
        ];
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab navigation, not a security-sensitive form
        $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'general';
        $active_tab = in_array($active_tab, $allowed_tabs, true) ? $active_tab : 'general';

        echo '<div class="wrap liteimage-wrap">';
        echo '<h1 class="liteimage-title">LiteImage</h1>';

        // Flat tab navigation
        echo '<nav class="liteimage-tab-nav">';
        foreach ($tab_labels as $tab => $label) {
            $active = $active_tab === $tab ? 'active' : '';
            echo '<a href="?page=liteimage-settings&tab=' . esc_attr($tab) . '" class="liteimage-tab-link ' . esc_attr($active) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';

        echo '<div class="liteimage-card">';
        switch ($active_tab) {
            case 'general':
                self::display_general_tab_content();
                self::display_developer_support();
                break;
            case 'stats':
                self::display_stats_tab_content();
                break;
            case 'webp':
                self::display_webp_tab_content();
                break;
            case 'usage':
                self::display_usage_tab_content();
                break;
        }
        echo '</div>';
        echo '</div>';
    }

    /**
     * Display general tab content
     *
     * @return void
     */
    public static function display_general_tab_content()
    {
        $webp_available = self::is_webp_supported_anywhere();
        $settings = Settings::get_instance();
        $webp_enabled = $settings->get('convert_to_webp');
        ?>
        <h2><?php esc_html_e('General Settings', 'liteimage'); ?></h2>
        <form method="post" action="options.php">
            <?php
            settings_fields('liteimage_settings_group');
            do_settings_sections('liteimage-settings');
            ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Generate WebP Thumbnails (if possible)', 'liteimage'); ?></th>
                    <td>
                        <input type="checkbox" name="liteimage_settings[convert_to_webp]" value="1" <?php checked($webp_enabled, true);
                        if (!$webp_available) {
                            echo ' disabled';
                        } ?>>
                        <label><?php esc_html_e('Generate WebP thumbnails for browsers that support it (recommended)', 'liteimage'); ?></label>
                        <?php
                        if (!$webp_available) {
                            echo '<div style="color:#d23b1a;margin:5px 0 0 0;font-size:0.98em;">';
                            esc_html_e('WebP conversion is not available on your server. Install or enable GD or Imagick extension for this functionality.', 'liteimage');
                            echo ' <a href="?page=liteimage-settings&tab=webp" style="margin-left:8px;font-size:0.97em;">' . esc_html__('Show details', 'liteimage') . '</a>';
                            echo '</div>';
                        }
                        ?>
                        <p class="description">
                            <?php esc_html_e('WebP thumbnails save space and load faster for users on modern browsers.', 'liteimage'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Disable Thumbnails', 'liteimage'); ?></th>
                    <td>
                        <input type="checkbox" name="liteimage_settings[disable_thumbnails]" value="1" <?php checked($settings->get('disable_thumbnails'), true); ?>>
                        <label><?php esc_html_e('Disable default WordPress thumbnails', 'liteimage'); ?></label>
                        <p class="description"><?php esc_html_e('Prevents WordPress from generating default thumbnail sizes, relying solely on LiteImage dynamic sizes.', 'liteimage'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Show Donation Section', 'liteimage'); ?></th>
                    <td>
                        <input type="checkbox" name="liteimage_settings[show_donation]" value="1" <?php checked($settings->get('show_donation'), true); ?>>
                        <label><?php esc_html_e('Display donation section in admin', 'liteimage'); ?></label>
                        <p class="description"><?php esc_html_e('Show the Bitcoin donation section in the LiteImage settings page.', 'liteimage'); ?></p>
                    </td>
                </tr>
            </table>
            <h3><?php esc_html_e('WebP Support Status', 'liteimage'); ?></h3>
            <p>
                <?php
                if (WebPSupport::is_webp_supported()) {
                    esc_html_e('WebP supported via GD or Imagick.', 'liteimage');
                } else {
                    esc_html_e('WebP conversion unavailable. Enable GD or Imagick extension for optimal performance.', 'liteimage');
                }
                ?>
            </p>
            <?php submit_button(); ?>
        </form>
        <section class="liteimage-section-management">
            <h3><?php esc_html_e('Thumbnail Management', 'liteimage'); ?></h3>
            <p><?php esc_html_e('Manage thumbnails generated by LiteImage and WordPress.', 'liteimage'); ?></p>
            <div class="liteimage-section-management-form">
                <div style="margin-bottom: 1em;">
                    <p><button type="button" id="liteimage-btn-clear-lite" class="button button-primary"><?php esc_html_e('Clear LiteImage Thumbnails', 'liteimage'); ?></button></p>
                    <p class="description"><?php esc_html_e('Remove all LiteImage-generated thumbnails and WebP images. New thumbnails will be created when liteimage is called.', 'liteimage'); ?></p>
                    <div id="liteimage-clear-lite-notice"></div>
                </div>
                <div>
                    <p><button type="button" id="liteimage-btn-clear-wp" class="button button-primary"><?php esc_html_e('Clear WordPress Thumbnails', 'liteimage'); ?></button></p>
                    <p class="description"><?php esc_html_e('Remove all WordPress-generated thumbnails (excluding LiteImage thumbnails). WordPress will regenerate them as needed.', 'liteimage'); ?></p>
                    <div id="liteimage-clear-wp-notice"></div>
                </div>
            </div>
        </section>
        <?php
    }

    /**
     * AJAX: Clear LiteImage-generated thumbnails
     *
     * @return void
     */
    public static function ajax_clear_liteimage_thumbnails()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'liteimage')], 403);
        }

        check_ajax_referer('liteimage_clear_thumbnails', 'nonce');

        $deleted = ThumbnailCleaner::clear_all_thumbnails();
        wp_send_json_success(['deleted' => (int) $deleted]);
    }

    /**
     * AJAX: Clear WordPress-generated thumbnails
     *
     * @return void
     */
    public static function ajax_clear_wp_thumbnails()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'liteimage')], 403);
        }

        check_ajax_referer('liteimage_clear_wp_thumbnails', 'nonce');

        $deleted = ThumbnailCleaner::clear_wordpress_thumbnails();
        wp_send_json_success(['deleted' => (int) $deleted]);
    }

    public static function is_webp_supported_anywhere()
    {
        // GD
        if (extension_loaded('gd') && function_exists('imagewebp')) {
            return true;
        }
        // Imagick
        if (extension_loaded('imagick') && class_exists('Imagick')) {
            $i = new \Imagick();
            if (in_array('WEBP', $i->queryFormats('WEBP'))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Render smart compression telemetry metrics.
     *
     * @param array<string,mixed> $summary
     * @return void
     */
    private static function render_compression_metrics(array $summary)
    {
        echo '<div class="liteimage-card liteimage-card--metrics">';
        echo '<h3>' . esc_html__('Smart Compression Metrics', 'liteimage') . '</h3>';

        if (empty($summary['total_operations'])) {
            echo '<p>' . esc_html__('No smart compression operations recorded yet. Generate thumbnails to populate these statistics.', 'liteimage') . '</p>';
            echo '</div>';
            return;
        }

        $avgQuality = $summary['average_quality'] !== null ? number_format_i18n((float) $summary['average_quality'], 1) : __('n/a', 'liteimage');
        $avgSavings = $summary['average_savings_percent'] !== null ? number_format_i18n((float) $summary['average_savings_percent'], 2) . '%' : __('n/a', 'liteimage');
        $avgIterations = number_format_i18n((float) $summary['average_iterations'], 2);
        $avgPsnr = $summary['average_psnr'] !== null ? number_format_i18n((float) $summary['average_psnr'], 2) . ' dB' : __('n/a', 'liteimage');

        echo '<div class="liteimage-stat-grid">';
        echo '<div class="liteimage-stat"><span class="liteimage-stat-num">' . esc_html(number_format_i18n((int) $summary['total_operations'])) . '</span><br>' . esc_html__('Optimized variants', 'liteimage') . '</div>';
        echo '<div class="liteimage-stat"><span class="liteimage-stat-num">' . esc_html($avgQuality) . '</span><br>' . esc_html__('Average quality', 'liteimage') . '</div>';
        echo '<div class="liteimage-stat"><span class="liteimage-stat-num">' . esc_html($avgSavings) . '</span><br>' . esc_html__('Average savings', 'liteimage') . '</div>';
        echo '<div class="liteimage-stat"><span class="liteimage-stat-num">' . esc_html($avgIterations) . '</span><br>' . esc_html__('Iterations per run', 'liteimage') . '</div>';
        echo '<div class="liteimage-stat"><span class="liteimage-stat-num">' . esc_html($avgPsnr) . '</span><br>' . esc_html__('Average PSNR', 'liteimage') . '</div>';
        echo '</div>';

        $strategies = isset($summary['strategies']) && is_array($summary['strategies']) ? $summary['strategies'] : [];
        $formats = isset($summary['formats']) && is_array($summary['formats']) ? $summary['formats'] : [];

        echo '<h4 style="margin-top:20px;">' . esc_html__('Strategy usage', 'liteimage') . '</h4>';
        echo '<ul class="liteimage-strategy-list">';
        foreach ($strategies as $strategy => $count) {
            echo '<li><strong>' . esc_html(ucfirst($strategy)) . ':</strong> ' . esc_html(number_format_i18n((int) $count)) . '</li>';
        }
        echo '</ul>';

        if (!empty($summary['upscaled_count'])) {
            echo '<p style="margin-top:12px;">' . sprintf(
                esc_html__('%s retina variants were upscaled beyond the source dimensions.', 'liteimage'),
                esc_html(number_format_i18n((int) $summary['upscaled_count']))
            ) . '</p>';
        }

        if (!empty($formats)) {
            echo '<h4 style="margin-top:20px;">' . esc_html__('Format breakdown', 'liteimage') . '</h4>';
            echo '<table class="widefat striped" style="max-width:560px;">';
            echo '<thead><tr><th>' . esc_html__('Format', 'liteimage') . '</th><th>' . esc_html__('Runs', 'liteimage') . '</th><th>' . esc_html__('Avg quality', 'liteimage') . '</th><th>' . esc_html__('Avg savings', 'liteimage') . '</th></tr></thead><tbody>';
            foreach ($formats as $format => $data) {
                $formatCount = number_format_i18n((int) ($data['count'] ?? 0));
                $formatQuality = isset($data['average_quality']) && $data['average_quality'] !== null
                    ? number_format_i18n((float) $data['average_quality'], 1)
                    : __('n/a', 'liteimage');
                $formatSavings = isset($data['average_savings_percent']) && $data['average_savings_percent'] !== null
                    ? number_format_i18n((float) $data['average_savings_percent'], 2) . '%'
                    : __('n/a', 'liteimage');

                echo '<tr>';
                echo '<td>' . esc_html(strtoupper($format)) . '</td>';
                echo '<td>' . esc_html($formatCount) . '</td>';
                echo '<td>' . esc_html($formatQuality) . '</td>';
                echo '<td>' . esc_html($formatSavings) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        if (!empty($summary['last_event'])) {
            $lastEvent = $summary['last_event'];
            $lastQuality = isset($lastEvent['quality']) && $lastEvent['quality'] !== null ? number_format_i18n((int) $lastEvent['quality']) : __('n/a', 'liteimage');
            $lastSize = isset($lastEvent['bytes']) ? size_format((float) $lastEvent['bytes'], 2) : __('n/a', 'liteimage');
            $lastTime = isset($lastEvent['timestamp']) ? human_time_diff($lastEvent['timestamp'], time()) : __('n/a', 'liteimage');

            echo '<p style="margin-top:20px;font-size:0.95em;opacity:0.8;">' .
                sprintf(
                    esc_html__('Last run: %1$s ago · %2$s · quality %3$s · %4$s bytes', 'liteimage'),
                    esc_html($lastTime),
                    esc_html(strtoupper((string) ($lastEvent['format'] ?? ''))),
                    esc_html($lastQuality),
                    esc_html($lastSize)
                ) .
                '</p>';
        }

        echo '</div>';
    }

    /**
     * Inspect Imagick capabilities for the WebP status tab.
     *
     * @return array<string,mixed>
     */
    private static function get_imagick_capabilities(): array
    {
        $available = extension_loaded('imagick') && class_exists('Imagick');
        $caps = [
            'available' => $available,
            'version' => null,
            'psnr_supported' => false,
            'webp_supported' => false,
            'delegates' => [],
            'sample_formats' => [],
            'error' => null,
        ];

        if (!$available) {
            return $caps;
        }

        try {
            $versionInfo = \Imagick::getVersion();
            if (is_array($versionInfo) && isset($versionInfo['versionString'])) {
                $caps['version'] = $versionInfo['versionString'];
                if (preg_match('/Delegates(?:\\s*:?\\s*)([A-Za-z0-9_\\s]+)/', $versionInfo['versionString'], $matches)) {
                    $caps['delegates'] = array_filter(array_map('trim', preg_split('/\\s+/', $matches[1])));
                }
            } else {
                $caps['version'] = phpversion('imagick') ?: null;
            }

            $caps['psnr_supported'] = method_exists('\Imagick', 'compareImages');

            $imagick = new \Imagick();
            $formatsToProbe = ['WEBP', 'JPEG', 'JPG', 'PNG', 'GIF', 'TIFF', 'SVG', 'AVIF', 'HEIC', 'PDF'];
            $supported = [];
            foreach ($formatsToProbe as $format) {
                try {
                    $results = $imagick->queryFormats($format);
                    if (!empty($results)) {
                        $supported[] = strtoupper($format);
                    }
                } catch (\Throwable $formatException) {
                    continue;
                }
            }
            $caps['sample_formats'] = $supported;
            $caps['webp_supported'] = in_array('WEBP', $supported, true);

            $imagick->clear();
            $imagick->destroy();
        } catch (\Throwable $exception) {
            $caps['error'] = $exception->getMessage();
        }

        return $caps;
    }

    // New tab placeholders:
    private static function display_stats_tab_content()
    {
        echo '<h2 class="liteimage-section-header">' . esc_html__('Savings & Reports', 'liteimage') . '</h2>';

        $telemetrySummary = SmartCompressionTelemetry::get_summary();
        self::render_compression_metrics($telemetrySummary);

        // 1) Collect all images in the media library
        $args = array(
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        );
        $attachments = get_posts($args);

        $all_count = 0;                // all images (originals)
        $all_bytes = 0;                // total weight of all originals

        foreach ($attachments as $id) {
            $file_abs = get_attached_file($id);
            if ($file_abs && file_exists($file_abs)) {
                $all_count++;
                $all_bytes += (int) filesize($file_abs);
            }
        }

        // Formatting numbers in MB
        $fmt = function ($bytes) {
            return round(((int) $bytes) / 1048576, 2);
        };

        echo '<div class="liteimage-card" style="margin-bottom:18px">';
        // Top block: all uploaded images
        echo '<h3 style="margin-top:0">' . esc_html__('All uploaded images', 'liteimage') . '</h3>';
        echo '<div class="liteimage-stats">';
        echo '<div class="liteimage-stat"><span class="liteimage-stat-num">' . esc_html($all_count) . '</span><br>' . esc_html__('Images total', 'liteimage') . '</div>';
        echo '<div class="liteimage-stat"><span class="liteimage-stat-num">' . esc_html($fmt($all_bytes)) . ' MB</span><br>' . esc_html__('Total originals size', 'liteimage') . '</div>';
        echo '</div>';
        echo '</div>';
    }
    private static function display_webp_tab_content()
    {
        echo '<h2 class="liteimage-section-header">' . esc_html__('WebP Status', 'liteimage') . '</h2>';
        $supported = \LiteImage\Support\WebPSupport::is_webp_supported();
        $gd = extension_loaded('gd') && function_exists('imagewebp');
        $imagick = extension_loaded('imagick') && class_exists('Imagick');
        $imagick_caps = self::get_imagick_capabilities();

        if ($supported) {
            $engine = $gd ? 'GD' : ($imagick ? 'Imagick' : 'Unknown');
            echo '<div style="color: #008a1c; font-weight: 600; font-size:1.14em; margin-bottom:12px;">' . esc_html__('WebP is supported on this server!', 'liteimage') .
                 ' (' . esc_html($engine) . ')</div>';
            echo '<p style="margin-bottom:24px;">' . esc_html__('You are using fast and modern WebP optimization. All compatible browsers receive WebP thumbnails automatically.', 'liteimage') . '</p>';
        } else {
            echo '<div style="color: #ca4a1f; font-weight: 600; font-size:1.15em; margin-bottom:12px;">' . esc_html__('WebP not supported!', 'liteimage') . '</div>';
            echo '<p>' . esc_html__('Your server PHP must have either the GD or Imagick extension enabled for WebP support.', 'liteimage') . '</p>';
            if (!$gd) {
                echo '<b>GD</b>: <span style="color:#b93c0b">';
                echo esc_html__('Not available', 'liteimage') . '</span><br>';
            }
            if (!$imagick) {
                echo '<b>Imagick</b>: <span style="color:#b93c0b">';
                echo esc_html__('Not available', 'liteimage') . '</span><br>';
            }
            echo '<div style="margin:18px 0 6px 0;font-size:0.98em">';
            echo esc_html__('To enable WebP, install and enable GD/Imagick. On Ubuntu:', 'liteimage');
            echo '<pre style="background:#f5f5f5;padding:7px 13px;display:inline-block;border-radius:4px;margin-top:3px;">sudo apt install php-gd php-imagick
sudo service php7.4-fpm restart</pre>';
            echo '</div>';
            echo '<div style="margin-top:10px;opacity:0.75;font-size:0.97em">';
            echo esc_html__('After installing, restart your web server and revisit this settings page.', 'liteimage');
            echo '</div>';
        }

        echo '<div class="liteimage-card" style="margin-top:24px;">';
        echo '<h3 style="margin-top:0;">' . esc_html__('Imagick diagnostics', 'liteimage') . '</h3>';
        if (!$imagick_caps['available']) {
            echo '<p>' . esc_html__('Imagick extension is not available. PSNR-based smart compression will rely on filesize heuristics.', 'liteimage') . '</p>';
        } elseif (!empty($imagick_caps['error'])) {
            echo '<p style="color:#b93c0b;">' . esc_html__('Imagick detected but initialization failed:', 'liteimage') . ' ' . esc_html($imagick_caps['error']) . '</p>';
        } else {
            echo '<table class="widefat striped" style="max-width:540px;margin-bottom:14px;">';
            echo '<tbody>';
            echo '<tr><th style="width:190px;">' . esc_html__('Imagick version', 'liteimage') . '</th><td>' . esc_html($imagick_caps['version'] ?? __('Unknown', 'liteimage')) . '</td></tr>';
            echo '<tr><th>' . esc_html__('PSNR metric available', 'liteimage') . '</th><td>' . ($imagick_caps['psnr_supported'] ? '<span style="color:#0a7b1e;">' . esc_html__('Yes', 'liteimage') . '</span>' : '<span style="color:#b93c0b;">' . esc_html__('No', 'liteimage') . '</span>') . '</td></tr>';
            echo '<tr><th>' . esc_html__('WebP read/write', 'liteimage') . '</th><td>' . ($imagick_caps['webp_supported'] ? '<span style="color:#0a7b1e;">' . esc_html__('Supported', 'liteimage') . '</span>' : '<span style="color:#b93c0b;">' . esc_html__('Not supported', 'liteimage') . '</span>') . '</td></tr>';
            if (!empty($imagick_caps['delegates'])) {
                echo '<tr><th>' . esc_html__('Delegates', 'liteimage') . '</th><td>' . esc_html(implode(', ', $imagick_caps['delegates'])) . '</td></tr>';
            }
            if (!empty($imagick_caps['sample_formats'])) {
                echo '<tr><th>' . esc_html__('Sample formats', 'liteimage') . '</th><td>' . esc_html(implode(', ', $imagick_caps['sample_formats'])) . '</td></tr>';
            }
            echo '</tbody>';
            echo '</table>';

            if (!$imagick_caps['psnr_supported']) {
                echo '<p style="margin-top:12px;color:#b93c0b;">' . esc_html__('Imagick is available but PSNR comparison is missing. Smart compression will use filesize heuristics instead of visual metrics.', 'liteimage') . '</p>';
            } elseif (!$imagick_caps['webp_supported']) {
                echo '<p style="margin-top:12px;color:#d98c12;">' . esc_html__('Imagick is available but does not list WebP in its format registry. Ensure WebP delegates are installed to unlock Imagick-based conversions.', 'liteimage') . '</p>';
            } else {
                echo '<p style="margin-top:12px;opacity:0.85;">' . esc_html__('Imagick is ready for PSNR-based smart compression and WebP generation.', 'liteimage') . '</p>';
            }
        }
        echo '</div>';
    }

    /**
     * Display usage tab content
     *
     * @return void
     */
    private static function display_usage_tab_content()
    {
        echo '<h2 class="liteimage-section-header">' . esc_html__('How to use LiteImage', 'liteimage') . '</h2>';
        echo '<div style="max-width:800px;line-height:1.6;margin-bottom:18px;">';
        echo '<p><b>' . esc_html__('The', 'liteimage') . ' <code>liteimage</code> ' . esc_html__('function generates responsive images with WebP support if available, falling back to JPEG/PNG if not. Use it in your theme or templates to optimize image delivery.', 'liteimage') . '</b></p>';
        echo '<h3 style="margin-top:1.2em;">' . esc_html__('Function Syntax', 'liteimage') . '</h3>';
        echo '<pre style="background:#f5f5f5;padding:8px 10px;border-radius:6px;">liteimage(int $image_id, array $data = [], int|null $mobile_image_id = null)</pre>';
        echo '<h3 style="margin-top:1em;">' . esc_html__('Parameters', 'liteimage') . '</h3>';
        echo '<ul style="margin-bottom:1em;">';
        echo '<li><b>$image_id</b>: ' . esc_html__('The ID of the image attachment from the Media Library.', 'liteimage') . '</li>';
        echo '<li><b>$data</b>: ' . esc_html__('Configuration options for image rendering:', 'liteimage') .
                '<ul style="margin-top:6px;">'
                . '<li><code>thumb</code>: ' . esc_html__('Default size (e.g., "full" or [width, height], default [1920, 0]).', 'liteimage') . '</li>'
                . '<li><code>args</code>: ' . esc_html__('HTML attributes for the <img> tag (e.g., ["class" => "my-image", "alt" => "Description"]).', 'liteimage') . '</li>'
                . '<li><code>min</code>: ' . esc_html__('Min-width media queries (e.g., ["768" => [1920, 0]]).', 'liteimage') . '</li>'
                . '<li><code>max</code>: ' . esc_html__('Max-width media queries (e.g., ["767" => [768, 480]]).', 'liteimage') . '</li>'
                . '</ul></li>';
        echo '<li><b>$mobile_image_id</b>: ' . esc_html__('Optional ID for a mobile-specific image (used for screens < 768px).', 'liteimage') . '</li>';
        echo '</ul>';
        echo '<h3>' . esc_html__('Tips for Optimal Use', 'liteimage') . '</h3>';
        echo '<ul>';
        echo '<li>' . esc_html__('Use specific thumbnail sizes to reduce server load and improve performance.', 'liteimage') . '</li>';
        echo '<li>' . esc_html__('Enable WebP support by ensuring GD or Imagick supports WebP for faster image loading.', 'liteimage') . '</li>';
        echo '<li>' . esc_html__('Clear thumbnails periodically to remove unused sizes and save disk space.', 'liteimage') . '</li>';
        echo '<li>' . esc_html__('Add accessibility attributes like "alt" and "aria-label" in the args array for better SEO and usability.', 'liteimage') . '</li>';
        echo '</ul>';
        echo '</div>';
    }

    /**
     * Display developer support section
     *
     * @return void
     */
    private static function display_developer_support()
    {
        $settings = Settings::get_instance();
        if (!$settings->get('show_donation')) {
            return;
        }
        ?>
        <h3><?php esc_html_e('Support the Developer', 'liteimage'); ?></h3>
        <p><?php esc_html_e('Enjoying LiteImage? Support its development with a Bitcoin (BTC chain) donation!', 'liteimage'); ?></p>
        <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 20px;">
            <div style="width: 120px; height: 120px; background: #f8f9fa; border-radius: 8px; padding: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <div class="qr-code" style="width: 100%; height: 100%; background: #fff; border-radius: 4px; overflow: hidden;">
                    <svg height="120" width="120" viewBox="0 0 33 33"><path fill="#FFFFFF" d="M0,0 h33v33H0z" shape-rendering="crispEdges"></path><path fill="#000000" d="M0 0h7v1H0zM10 0h2v1H10zM15 0h1v1H15zM23 0h1v1H23zM26,0 h7v1H26zM0 1h1v1H0zM6 1h1v1H6zM9 1h1v1H9zM17 1h2v1H17zM20 1h2v1H20zM23 1h2v1H23zM26 1h1v1H26zM32,1 h1v1H32zM0 2h1v1H0zM2 2h3v1H2zM6 2h1v1H6zM9 2h1v1H9zM12 2h4v1H12zM19 2h1v1H19zM22 2h2v1H22zM26 2h1v1H26zM28 2h3v1H28zM32,2 h1v1H32zM0 3h1v1H0zM2 3h3v1H2zM6 3h1v1H6zM9 3h4v1H9zM14 3h2v1H14zM18 3h2v1H18zM22 3h1v1H22zM26 3h1v1H26zM28 3h3v1H28zM32,3 h1v1H32zM0 4h1v1H0zM2 4h3v1H2zM6 4h1v1H6zM8 4h4v1H8zM14 4h2v1H14zM18 4h1v1H18zM20 4h1v1H20zM24 4h1v1H24zM26 4h1v1H26zM28 4h3v1H28zM32,4 h1v1H32zM0 5h1v1H0zM6 5h1v1H6zM10 5h2v1H10zM17 5h1v1H17zM19 5h4v1H19zM26 5h1v1H26zM32,5 h1v1H32zM0 6h7v1H0zM8 6h1v1H8zM10 6h1v1H10zM12 6h1v1H12zM14 6h1v1H14zM16 6h1v1H16zM18 6h1v1H18zM20 6h1v1H20zM22 6h1v1H22zM24 6h1v1H24zM26,6 h7v1H26zM8 7h2v1H8zM11 7h1v1H11zM16 7h1v1H16zM24 7h1v1H24zM2 8h2v1H2zM6 8h3v1H6zM10 8h1v1H10zM14 8h3v1H14zM18 8h1v1H18zM20 8h1v1H20zM24 8h3v1H24zM28 8h1v1H28zM1 9h1v1H1zM3 9h1v1H3zM5 9h1v1H5zM7 9h1v1H7zM10 9h4v1H10zM16 9h1v1H16zM18 9h2v1H18zM21 9h3v1H21zM25 9h1v1H25zM30 9h1v1H30zM1 10h3v1H1zM5 10h2v1H5zM10 10h1v1H10zM15 10h6v1H15zM23 10h1v1H23zM25 10h2v1H25zM29,10 h4v1H29zM1 11h4v1H1zM7 11h4v1H7zM12 11h5v1H12zM18 11h2v1H18zM22 11h3v1H22zM27 11h2v1H27zM31,11 h2v1H31zM3 12h1v1H3zM6 12h1v1H6zM11 12h3v1H11zM15 12h1v1H15zM17 12h2v1H17zM20 12h1v1H20zM22 12h2v1H22zM25 12h1v1H25zM27 12h1v1H27zM29 12h1v1H29zM31 12h1v1H31zM1 13h2v1H1zM4 13h2v1H4zM9 13h1v1H9zM11 13h1v1H11zM15 13h1v1H15zM17 13h3v1H17zM21 13h1v1H21zM23 13h3v1H23zM27 13h1v1H27zM29 13h1v1H29zM31 13h1v1H31zM1 14h2v1H1zM4 14h1v1H4zM6 14h1v1H6zM10 14h1v1H10zM15 14h1v1H15zM17 14h1v1H17zM20 14h3v1H20zM30 14h2v1H30zM1 15h2v1H1zM5 15h1v1H5zM8 15h2v1H8zM12 15h2v1H12zM20 15h3v1H20zM24 15h7v1H24zM32,15 h1v1H32zM4 16h3v1H4zM13 16h2v1H13zM16 16h1v1H16zM22 16h1v1H22zM26 16h4v1H26zM32,16 h1v1H32zM1 17h1v1H1zM4 17h1v1H4zM8 17h1v1H8zM13 17h2v1H13zM17 17h1v1H17zM20 17h2v1H20zM24 17h1v1H24zM26 17h1v1H26zM28 17h1v1H28zM32,17 h1v1H32zM1 18h2v1H1zM4 18h1v1H4zM6 18h1v1H6zM11 18h1v1H11zM13 18h8v1H13zM22 18h1v1H22zM24 18h1v1H24zM27 18h4v1H27zM0 19h4v1H0zM5 19h1v1H5zM8 19h2v1H8zM11 19h1v1H11zM14 19h3v1H14zM19 19h2v1H19zM25 19h1v1H25zM27 19h1v1H27zM32,19 h1v1H32zM1 20h1v1H1zM3 20h5v1H3zM9 20h1v1H9zM12 20h1v1H12zM14 20h2v1H14zM17 20h2v1H17zM20 20h2v1H20zM23 20h2v1H23zM27 20h1v1H27zM32,20 h1v1H32zM0 21h1v1H0zM2 21h1v1H2zM8 21h3v1H8zM13 21h1v1H13zM15 21h3v1H15zM19 21h1v1H19zM27 21h1v1H27zM31 21h1v1H31zM4 22h3v1H4zM11 22h1v1H11zM14 22h1v1H14zM17 22h2v1H17zM23 22h5v1H23zM30,22 h3v1H30zM1 23h1v1H1zM3 23h2v1H3zM11 23h1v1H11zM13 23h3v1H13zM20 23h1v1H20zM22 23h1v1H22zM27 23h3v1H27zM31,23 h2v1H31zM0 24h1v1H0zM2 24h3v1H2zM6 24h3v1H6zM13 24h3v1H13zM19 24h4v1H19zM24 24h5v1H24zM30 24h2v1H30zM8 25h3v1H8zM16 25h3v1H16zM21 25h1v1H21zM23 25h2v1H23zM28 25h4v1H28zM0 26h7v1H0zM8 26h4v1H8zM13 26h1v1H13zM15 26h1v1H15zM20 26h2v1H20zM24 26h1v1H24zM26 26h1v1H26zM28 26h2v1H28zM0 27h1v1H0zM6 27h1v1H6zM9 27h4v1H9zM15 27h1v1H15zM17 27h1v1H17zM20 27h2v1H20zM23 27h2v1H23zM28 27h4v1H28zM0 28h1v1H0zM2 28h3v1H2zM6 28h1v1H6zM10 28h2v1H10zM17 28h3v1H17zM23 28h8v1H23zM0 29h1v1H0zM2 29h3v1H2zM6 29h1v1H6zM8 29h1v1H8zM11 29h5v1H11zM17 29h1v1H17zM19 29h1v1H19zM21 29h3v1H21zM28 29h2v1H28zM31,29 h2v1H31zM0 30h1v1H0zM2 30h3v1H2zM6 30h1v1H6zM8 30h3v1H8zM16 30h1v1H16zM18 30h2v1H18zM21 30h1v1H21zM24 30h3v1H24zM30 30h1v1H30zM0 31h1v1H0zM6 31h1v1H6zM14 31h1v1H14zM16 31h4v1H16zM22 31h2v1H22zM26 31h1v1H26zM28 31h1v1H28zM32,31 h1v1H32zM0 32h7v1H0zM14 32h2v1H14zM18 32h2v1H18zM21 32h1v1H21zM23 32h1v1H23zM27 32h1v1H27z" shape-rendering="crispEdges"></path></svg>
                </div>
            </div>
            <div>
                <a href="bitcoin:1NDUzCkYvKE5qHZnfR9f71NrXL2DJCAVpn?label=LiteImage%20Donation" class="button button-secondary" style="background: #f7931a; border-color: #f7931a; color: #fff; padding: 8px 16px; font-weight: 500; text-decoration: none;" target="_blank">
                    <?php esc_html_e('Buy Me a Coffee (BTC)', 'liteimage'); ?>
                </a>
                <p style="margin-top: 10px;">
                    <input type="text" value="1NDUzCkYvKE5qHZnfR9f71NrXL2DJCAVpn" readonly style="width: 100%; max-width: 300px; padding: 6px; border: 1px solid #ddd; border-radius: 4px;" id="btc-address">
                    <span style="color: #28a745; margin-left: 10px; display: none;" id="copy-notice"><?php esc_html_e('Copied!', 'liteimage'); ?></span>
                </p>
                <p class="description"><?php esc_html_e('Send donations to this Bitcoin address (BTC chain): 1NDUzCkYvKE5qHZnfR9f71NrXL2DJCAVpn', 'liteimage'); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Add thumbnail sizes column to media library
     *
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public static function add_thumbnail_sizes_column($columns)
    {
        $columns['thumbnail_sizes'] = __('Thumbnail Sizes', 'liteimage');
        return $columns;
    }

    /**
     * Display thumbnail sizes in media library column
     *
     * @param string $column_name Column name
     * @param int $post_id Post ID
     * @return void
     */
    public static function display_thumbnail_sizes_column($column_name, $post_id)
    {
        if ($column_name === 'thumbnail_sizes') {
            $metadata = wp_get_attachment_metadata($post_id) ?: [];
            if (!empty($metadata['sizes'])) {
                echo '<ul>';
                foreach ($metadata['sizes'] as $size => $data) {
                    echo '<li>' . esc_html($size) . ': ' . esc_attr($data['width']) . 'x' . esc_attr($data['height']);
                    if (isset($data['webp']) && !empty($data['webp'])) {
                        echo ' (WebP: ' . esc_html($data['webp']) . ')';
                    }
                    echo '</li>';
                }
                echo '</ul>';
            } else {
                echo esc_html__('No thumbnails', 'liteimage');
            }
        }
    }
}

// Backward compatibility alias
class_alias('LiteImage\Admin\AdminPage', 'LiteImage_Admin');

