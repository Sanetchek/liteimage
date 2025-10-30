<?php

/**
 * Admin Page class for LiteImage plugin
 *
 * @package LiteImage
 * @since 3.2.0
 */

namespace LiteImage\Admin;

use LiteImage\Config;
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

        wp_enqueue_script(
            'liteimage-admin',
            plugins_url('assets/js/admin.js', LITEIMAGE_DIR . 'liteimage.php'),
            [],
            \LiteImage\Plugin::VERSION,
            true
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
        return [
            'disable_thumbnails' => !empty($input['disable_thumbnails']),
            'show_donation' => !empty($input['show_donation']),
        ];
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

        // Rate limiting check
        $user_id = get_current_user_id();
        $rate_limit_key_liteimage = 'liteimage_cleanup_liteimage_' . $user_id;
        $rate_limit_key_wp = 'liteimage_cleanup_wp_' . $user_id;

        if (isset($_POST['liteimage_clear_thumbnails'])) {
            check_admin_referer('liteimage_clear_thumbnails_nonce');

            if (get_transient($rate_limit_key_liteimage)) {
                echo '<div class="notice notice-error"><p>' .
                     sprintf(
                         esc_html__('Please wait %d seconds before running this operation again.', 'liteimage'),
                         Config::RATE_LIMIT_SECONDS
                     ) .
                     '</p></div>';
            } else {
                set_transient($rate_limit_key_liteimage, true, Config::RATE_LIMIT_SECONDS);
                $deleted = ThumbnailCleaner::clear_all_thumbnails();
                echo '<div class="updated"><p>' .
                     sprintf(
                         esc_html__('LiteImage thumbnails cleared successfully! %d files deleted. New sizes will be generated on next call to liteimage.', 'liteimage'),
                         $deleted
                     ) .
                     '</p></div>';
            }
        }

        if (isset($_POST['liteimage_clear_wp_thumbnails'])) {
            check_admin_referer('liteimage_clear_wp_thumbnails_nonce');

            if (get_transient($rate_limit_key_wp)) {
                echo '<div class="notice notice-error"><p>' .
                     sprintf(
                         esc_html__('Please wait %d seconds before running this operation again.', 'liteimage'),
                         Config::RATE_LIMIT_SECONDS
                     ) .
                     '</p></div>';
            } else {
                set_transient($rate_limit_key_wp, true, Config::RATE_LIMIT_SECONDS);
                $deleted = ThumbnailCleaner::clear_wordpress_thumbnails();
                echo '<div class="updated"><p>' .
                     sprintf(
                         esc_html__('WordPress thumbnails cleared successfully! %d files deleted. New sizes will be generated by WordPress as needed.', 'liteimage'),
                         $deleted
                     ) .
                     '</p></div>';
            }
        }

        // Whitelist validation for tab
        $allowed_tabs = ['general', 'usage'];
        $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'general';
        $active_tab = in_array($active_tab, $allowed_tabs, true) ? $active_tab : 'general';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('LiteImage Settings', 'liteimage'); ?></h1>
            <p><?php esc_html_e('LiteImage optimizes images with dynamic thumbnails, WebP support, and accessibility features. Configure settings below or learn how to use the plugin.', 'liteimage'); ?></p>
            <h2 class="nav-tab-wrapper">
                <a href="?page=liteimage-settings&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('General', 'liteimage'); ?></a>
                <a href="?page=liteimage-settings&tab=usage" class="nav-tab <?php echo $active_tab === 'usage' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Usage Instructions', 'liteimage'); ?></a>
            </h2>

            <?php if ($active_tab === 'general') : ?>
                <?php
                self::display_general_tab_content();
                self::display_developer_support();
                ?>
            <?php else : ?>
                <?php self::display_usage_tab_content(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Display general tab content
     *
     * @return void
     */
    private static function display_general_tab_content()
    {
        ?>
        <h2><?php esc_html_e('General Settings', 'liteimage'); ?></h2>
        <form method="post" action="options.php">
            <?php
            settings_fields('liteimage_settings_group');
            do_settings_sections('liteimage-settings');
            ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Disable Thumbnails', 'liteimage'); ?></th>
                    <td>
                        <?php $settings = Settings::get_instance(); ?>
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
                    esc_html_e('WebP conversion unavailable. Enable GD or Imagick WebP support for optimal performance.', 'liteimage');
                }
                ?>
            </p>
            <?php submit_button(); ?>
        </form>
        <h3><?php esc_html_e('Thumbnail Management', 'liteimage'); ?></h3>
        <p><?php esc_html_e('Manage thumbnails generated by LiteImage and WordPress.', 'liteimage'); ?></p>
        <form method="post" style="margin-bottom: 1em;">
            <?php wp_nonce_field('liteimage_clear_thumbnails_nonce'); ?>
            <p><input type="submit" name="liteimage_clear_thumbnails" class="button button-primary" value="<?php esc_html_e('Clear LiteImage Thumbnails', 'liteimage'); ?>"></p>
            <p class="description"><?php esc_html_e('Remove all LiteImage-generated thumbnails and WebP images. New thumbnails will be created when liteimage is called.', 'liteimage'); ?></p>
        </form>
        <form method="post">
            <?php wp_nonce_field('liteimage_clear_wp_thumbnails_nonce'); ?>
            <p><input type="submit" name="liteimage_clear_wp_thumbnails" class="button button-primary" value="<?php esc_html_e('Clear WordPress Thumbnails', 'liteimage'); ?>"></p>
            <p class="description"><?php esc_html_e('Remove all WordPress-generated thumbnails (excluding LiteImage thumbnails). WordPress will regenerate them as needed.', 'liteimage'); ?></p>
        </form>
        <?php
    }

    /**
     * Display usage tab content
     *
     * @return void
     */
    private static function display_usage_tab_content()
    {
        ?>
        <h2><?php esc_html_e('Using the liteimage Function', 'liteimage'); ?></h2>
        <p><?php esc_html_e('The <code>liteimage</code> function generates responsive images with WebP support if available, falling back to JPEG/PNG if not. Use it in your theme or templates to optimize image delivery.', 'liteimage'); ?></p>
        <h3><?php esc_html_e('Function Syntax', 'liteimage'); ?></h3>
        <pre>liteimage(int $image_id, array $data = [], int|null $mobile_image_id = null)</pre>

        <h3><?php esc_html_e('Parameters', 'liteimage'); ?></h3>
        <ul>
            <li><strong>$image_id</strong>: <?php esc_html_e('The ID of the image attachment from the Media Library.', 'liteimage'); ?></li>
            <li><strong>$data</strong>: <?php esc_html_e('Configuration options for image rendering:', 'liteimage'); ?>
                <ul>
                    <li><code>thumb</code>: <?php esc_html_e('Default size (e.g., "full" or [width, height], default [1920, 0]).', 'liteimage'); ?></li>
                    <li><code>args</code>: <?php esc_html_e('HTML attributes for the <img> tag (e.g., ["class" => "my-image", "alt" => "Description"]).', 'liteimage'); ?></li>
                    <li><code>min</code>: <?php esc_html_e('Min-width media queries (e.g., ["768" => [1920, 0]]).', 'liteimage'); ?></li>
                    <li><code>max</code>: <?php esc_html_e('Max-width media queries (e.g., ["767" => [768, 480]]).', 'liteimage'); ?></li>
                </ul>
            </li>
            <li><strong>$mobile_image_id</strong>: <?php esc_html_e('Optional ID for a mobile-specific image (used for screens < 768px).', 'liteimage'); ?></li>
        </ul>

        <h3><?php esc_html_e('Tips for Optimal Use', 'liteimage'); ?></h3>
        <ul>
            <li><?php esc_html_e('Use specific thumbnail sizes to reduce server load and improve performance.', 'liteimage'); ?></li>
            <li><?php esc_html_e('Enable WebP support by ensuring GD or Imagick supports WebP for faster image loading.', 'liteimage'); ?></li>
            <li><?php esc_html_e('Clear thumbnails periodically to remove unused sizes and save disk space.', 'liteimage'); ?></li>
            <li><?php esc_html_e('Add accessibility attributes like "alt" and "aria-label" in the args array for better SEO and usability.', 'liteimage'); ?></li>
        </ul>
        <?php
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

