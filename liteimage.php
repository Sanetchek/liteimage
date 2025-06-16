<?php
/*
Plugin Name: LiteImage
Description: Optimizes images with dynamic thumbnails, WebP support, and accessibility.
Version: 3.1
Author: Oleksandr Gryshko
Author URI: https://github.com/Sanetchek
Text Domain: liteimage
Domain Path: /languages
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

// Exit if accessed directly
defined('ABSPATH') || exit;

// Load dependencies
require_once __DIR__ . '/vendor/autoload.php';

use Intervention\Image\ImageManagerStatic as Image;

// Constants
define('LITEIMAGE_LOG_FILE', __DIR__ . '/liteimage-debug.log');
define('LITEIMAGE_LOG_ACTIVE', false);

// Initialize settings
class LiteImage_Settings {
    private static $instance = null;
    private $settings;

    private function __construct() {
        $this->settings = get_option('liteimage_settings', [
            'disable_thumbnails' => false,
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
        return $this->settings[$key] ?? null;
    }
}

// Logger class
class LiteImage_Logger {
    public static function log($message) {
        if (LITEIMAGE_LOG_ACTIVE && defined('WP_DEBUG') && WP_DEBUG) {
            self::log_data([
                'message' => $message,
                'timestamp' => gmdate('Y-m-d H:i:s')
            ]);
        }
    }

    private static function log_data($data) {
        // Get plugin directory path
        $plugin_dir = trailingslashit(plugin_dir_path(__FILE__));
        $log_dir = $plugin_dir . 'logs/';

        // Initialize WordPress filesystem
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();
        global $wp_filesystem;

        // Ensure the directory exists
        if (!$wp_filesystem->is_dir($log_dir)) {
            $wp_filesystem->mkdir($log_dir);
            $wp_filesystem->chmod($log_dir, 0755);
        }

        // Log file name with date
        $log_file = $log_dir . 'log-' . gmdate('Y-m-d') . '.log';

        // Create log entry
        $log_entry = '[' . $data['timestamp'] . '] ' . $data['message'] . PHP_EOL;

        // Write log to file
        $wp_filesystem->put_contents($log_file, $log_entry, FILE_APPEND);

        // Set permissions for file (0644)
        $wp_filesystem->chmod($log_file, 0644);
    }
}

// WebP support checker
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

// Thumbnail generator
class LiteImage_Thumbnail_Generator {
    public static function get_thumb_size($thumb, $attachment_id = null) {
        $thumb_data = ['size_name' => 'full', 'width' => 0, 'height' => 0];

        if (is_array($thumb) && isset($thumb[0], $thumb[1])) {
            $image_data = liteimage_downsize($attachment_id, [$thumb[0], $thumb[1]]);
            if ($image_data) {
                $thumb_data['width'] = $image_data[0];
                $thumb_data['height'] = $image_data[1];
                $thumb_data['size_name'] = "liteimage-{$thumb_data['width']}x{$thumb_data['height']}";
                add_image_size($thumb_data['size_name'], $thumb_data['width'], $thumb_data['height'], ($thumb_data['width'] && $thumb_data['height']));
            }
        }
        return $thumb_data;
    }

    public static function generate_thumbnails($attachment_id, $file_path, $sizes) {
        if (!file_exists($file_path) || !wp_get_attachment_image_src($attachment_id)) {
            LiteImage_Logger::log("Invalid file or attachment ID: $attachment_id");
            return '';
        }

        $metadata = wp_get_attachment_metadata($attachment_id) ?: ['sizes' => []];
        $original_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        list($orig_width, $orig_height) = getimagesize($file_path);

        if (!$orig_width || !$orig_height) {
            LiteImage_Logger::log("Invalid image dimensions for $file_path");
            return '';
        }

        $image = self::load_image($file_path, $original_extension);
        if (!$image) {
            LiteImage_Logger::log("Failed to load image: $file_path");
            return '';
        }

        $updated_size_name = '';
        foreach ($sizes as $size_key => $dimensions) {
            list($width, $height) = $dimensions;
            list($dest_width, $dest_height) = liteimage_downsize($attachment_id, [$width, $height]);
            $size_name = "liteimage-{$dest_width}x{$dest_height}";
            $updated_size_name = $size_name;
            $webp_path = str_replace(basename($file_path), basename($file_path, '.' . $original_extension) . "-$size_name.webp", $file_path);

            if (!isset($metadata['sizes'][$size_name]) || !file_exists($webp_path)) {
                self::generate_thumbnail($image, $file_path, $size_name, $dest_width, $dest_height, $webp_path, $original_extension);
                $metadata['sizes'][$size_name] = [
                    'file' => $original_extension === 'webp' ? false : basename($webp_path),
                    'webp' => basename($webp_path),
                    'width' => $dest_width,
                    'height' => $dest_height,
                    'extension' => 'webp',
                ];
            }
        }

        self::destroy_image($image);
        wp_update_attachment_metadata($attachment_id, $metadata);
        return $updated_size_name;
    }

    private static function load_image($file_path, $extension) {
        if (LiteImage_WebP_Support::is_webp_supported() && class_exists('Intervention\Image\ImageManagerStatic')) {
            try {
                Image::configure(['driver' => function_exists('imagewebp') ? 'gd' : 'imagick']);
                $image = Image::make($file_path)->strip();
                LiteImage_Logger::log("Image loaded via Intervention: $file_path");
                return $image;
            } catch (Exception $e) {
                LiteImage_Logger::log("Intervention Image failed: " . $e->getMessage());
                return null;
            }
        }

        $image = false;
        if ($extension === 'webp' && function_exists('imagecreatefromwebp')) {
            $image = imagecreatefromwebp($file_path);
        } else {
            $image = imagecreatefromstring(file_get_contents($file_path));
        }

        if ($image === false) {
            LiteImage_Logger::log("Failed to load image with GD: $file_path");
            return null;
        }

        LiteImage_Logger::log("Image loaded via GD: $file_path");
        return $image;
    }

    private static function generate_thumbnail($image, $file_path, $size_name, $dest_width, $dest_height, $webp_path, $original_extension) {
        if (!$image) {
            LiteImage_Logger::log("Invalid image resource for $file_path");
            return;
        }

        list($orig_width, $orig_height) = getimagesize($file_path);
        LiteImage_Logger::log("Original dimensions: {$orig_width}x{$orig_height}, Target: {$dest_width}x{$dest_height}");

        if (class_exists('Intervention\Image\ImageManagerStatic') && LiteImage_WebP_Support::is_webp_supported() && $image instanceof \Intervention\Image\Image) {
            // Force square crop to exact target dimensions from center
            $resized = $image->fit($dest_width, $dest_height, null, 'center');
            LiteImage_Logger::log("Center cropped image to {$dest_width}x{$dest_height}");

            if ($original_extension === 'png') {
                $resized->fill('transparent');
            }
            $resized->encode('webp', 85)->save($webp_path);
            LiteImage_Logger::log("Generated thumbnail: $size_name, webp=$webp_path");
        } else {
            $resized = imagecreatetruecolor($dest_width, $dest_height);
            if ($original_extension === 'png') {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
                imagefill($resized, 0, 0, $transparent);
            }

            // Calculate square crop
            $crop_size = min($orig_width, $orig_height); // Use smallest dimension for square
            $src_x = ($orig_width - $crop_size) / 2; // Center horizontally
            $src_y = ($orig_height - $crop_size) / 2; // Center vertically
            $src_x = max(0, (int) $src_x);
            $src_y = max(0, (int) $src_y);
            $src_w = $crop_size;
            $src_h = $crop_size;
            LiteImage_Logger::log("GD cropping: src_x={$src_x}, src_y={$src_y}, src_w={$src_w}, src_h={$src_h}");

            if (imagecopyresampled($resized, $image, 0, 0, $src_x, $src_y, $dest_width, $dest_height, $src_w, $src_h)) {
                if (function_exists('imagewebp')) {
                    imagewebp($resized, $webp_path, 85);
                    LiteImage_Logger::log("Generated WebP via GD: $webp_path");
                }
            } else {
                LiteImage_Logger::log("GD imagecopyresampled failed for $file_path");
            }
            imagedestroy($resized);
        }
    }

    private static function destroy_image($image) {
        if ($image instanceof \Intervention\Image\Image) {
            $image->destroy();
        } elseif ($image) {
            imagedestroy($image);
        }
    }
}

// Admin interface
class LiteImage_Admin {
    public static function init() {
        add_action('plugins_loaded', [__CLASS__, 'load_textdomain']);
        add_action('admin_menu', [__CLASS__, 'add_settings_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_notices', [__CLASS__, 'show_admin_notices']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [__CLASS__, 'add_settings_link']);
        add_filter('manage_media_columns', [__CLASS__, 'add_thumbnail_sizes_column']);
        add_action('manage_media_custom_column', [__CLASS__, 'display_thumbnail_sizes_column'], 10, 2);
    }

    public static function load_textdomain() {
        load_plugin_textdomain('liteimage', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public static function add_settings_link($links) {
        array_unshift($links, '<a href="' . admin_url('tools.php?page=liteimage-settings') . '">' . __('Settings', 'liteimage') . '</a>');
        return $links;
    }

    public static function add_settings_page() {
        add_submenu_page(
            'tools.php',
            __('LiteImage Settings', 'liteimage'),
            __('LiteImage Settings', 'liteimage'),
            'manage_options',
            'liteimage-settings',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function register_settings() {
        register_setting('liteimage_settings_group', 'liteimage_settings', [
            'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
        ]);
    }

    public static function sanitize_settings($input) {
        return [
            'disable_thumbnails' => !empty($input['disable_thumbnails']),
        ];
    }

    public static function show_admin_notices() {
        if (get_current_screen()->id !== 'tools_page_liteimage-settings') {
            return;
        }

        if (!LiteImage_WebP_Support::is_webp_supported()) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('WebP conversion requires GD or Imagick with WebP support. Using compressed JPEG/PNG.', 'liteimage') . '</p></div>';
        }
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) return;

        $settings = LiteImage_Settings::get_instance();
        if (isset($_POST['liteimage_clear_thumbnails'])) {
            check_admin_referer('liteimage_clear_thumbnails_nonce');
            LiteImage_Thumbnail_Cleaner::clear_all_thumbnails();
            echo '<div class="updated"><p>' . esc_html__('LiteImage thumbnails cleared successfully! New sizes will be generated on next call to liteimage.', 'liteimage') . '</p></div>';
        }
        if (isset($_POST['liteimage_clear_wp_thumbnails'])) {
            check_admin_referer('liteimage_clear_wp_thumbnails_nonce');
            LiteImage_Thumbnail_Cleaner::clear_wordpress_thumbnails();
            echo '<div class="updated"><p>' . esc_html__('WordPress thumbnails cleared successfully! New sizes will be generated by WordPress as needed.', 'liteimage') . '</p></div>';
        }

        $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'general';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('LiteImage Settings', 'liteimage'); ?></h1>
            <p><?php esc_html_e('LiteImage optimizes images with dynamic thumbnails, WebP support, and accessibility features. Configure settings below or learn how to use the plugin.', 'liteimage'); ?></p>
            <h2 class="nav-tab-wrapper">
                <a href="?page=liteimage-settings&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('General', 'liteimage'); ?></a>
                <a href="?page=liteimage-settings&tab=usage" class="nav-tab <?php echo $active_tab === 'usage' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Usage Instructions', 'liteimage'); ?></a>
            </h2>

            <?php if ($active_tab === 'general') : ?>
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
                                <input type="checkbox" name="liteimage_settings[disable_thumbnails]" value="1" <?php checked($settings->get('disable_thumbnails'), true); ?>>
                                <label><?php esc_html_e('Disable default WordPress thumbnails', 'liteimage'); ?></label>
                                <p class="description"><?php esc_html_e('Prevents WordPress from generating default thumbnail sizes, relying solely on LiteImage dynamic sizes.', 'liteimage'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <h3><?php esc_html_e('WebP Support Status', 'liteimage'); ?></h3>
                    <p>
                        <?php
                        if (LiteImage_WebP_Support::is_webp_supported()) {
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
                            <input type="text" value="1NDUzCkYvKE5qHZnfR9f71NrXL2DJCAVpn" readonly style="width: 100%; max-width: 300px; padding: 6px; border: 1px solid #ddd; border-radius: 4px;" onclick="this.select(); document.execCommand('copy');">
                            <span style="color: #28a745; margin-left: 10px; display: none;" id="copy-notice"><?php esc_html_e('Copied!', 'liteimage'); ?></span>
                        </p>
                        <p class="description"><?php esc_html_e('Send donations to this Bitcoin address (BTC chain): 1NDUzCkYvKE5qHZnfR9f71NrXL2DJCAVpn', 'liteimage'); ?></p>
                    </div>
                </div>
                <script>
                    document.querySelector('input[readonly]').addEventListener('click', function() {
                        this.select();
                        document.execCommand('copy');
                        const notice = document.getElementById('copy-notice');
                        notice.style.display = 'inline';
                        setTimeout(() => notice.style.display = 'none', 2000);
                    });
                </script>
            <?php else : ?>
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

                <h3><?php esc_html_e('Example Picture Element Outputs', 'liteimage'); ?></h3>
                <p><?php esc_html_e('The <code>liteimage</code> function generates a <code><picture></code> element with responsive sources using <code>wp_get_attachment_image()</code> internally for the fallback image. Below are example outputs for illustration:', 'liteimage'); ?></p>

                <h4><?php esc_html_e('1. Basic Image', 'liteimage'); ?></h4>
                <p><code>liteimage(123)</code></p>

                <p><code>liteimage(123, ["thumb" => [800, 600], "args" => ["alt" => "Custom Image", "class" => "featured"]])</code></p>

                <p><code>liteimage(123, ["thumb" => [1280, 720], "min" => ["768" => [1280, 720]], "max" => ["767" => [640, 480]], "args" => ["alt" => "Responsive Image"]], 456)</code></p>

                <h3><?php esc_html_e('Tips for Optimal Use', 'liteimage'); ?></h3>
                <ul>
                    <li><?php esc_html_e('Use specific thumbnail sizes to reduce server load and improve performance.', 'liteimage'); ?></li>
                    <li><?php esc_html_e('Enable WebP support by ensuring GD or Imagick supports WebP for faster image loading.', 'liteimage'); ?></li>
                    <li><?php esc_html_e('Clear thumbnails periodically to remove unused sizes and save disk space.', 'liteimage'); ?></li>
                    <li><?php esc_html_e('Add accessibility attributes like "alt" and "aria-label" in the args array for better SEO and usability.', 'liteimage'); ?></li>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function add_thumbnail_sizes_column($columns) {
        $columns['thumbnail_sizes'] = __('Thumbnail Sizes', 'liteimage');
        return $columns;
    }

    public static function display_thumbnail_sizes_column($column_name, $post_id) {
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

// Thumbnail cleaner
class LiteImage_Thumbnail_Cleaner {
    public static function clear_all_thumbnails() {
        $images = get_posts([
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'numberposts' => -1,
        ]);
        $upload_dir = wp_upload_dir()['basedir'];

        foreach ($images as $image) {
            if (in_array(get_post_mime_type($image->ID), ['image/svg+xml', 'image/avif'])) {
                LiteImage_Logger::log("Skipping {$image->ID} (SVG/AVIF)");
                continue;
            }

            $file_path = get_attached_file($image->ID);
            $metadata = wp_get_attachment_metadata($image->ID) ?: [];
            if (isset($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $size => $data) {
                    if (strpos($size, 'liteimage-') !== 0) {
                        continue; // Skip non-LiteImage thumbnails
                    }
                    $base_path = $upload_dir . '/' . dirname($metadata['file']);
                    if ($data['file']) {
                        $file = $base_path . '/' . $data['file'];
                        if (file_exists($file)) {
                            wp_delete_file($file);
                            LiteImage_Logger::log("Deleted LiteImage thumbnail: $file for {$image->ID}");
                        }
                    }
                    if ($data['webp']) {
                        $webp = $base_path . '/' . $data['webp'];
                        if (file_exists($webp)) {
                            wp_delete_file($webp);
                            LiteImage_Logger::log("Deleted LiteImage WebP: $webp for {$image->ID}");
                        }
                    }
                }
                $metadata['sizes'] = array_filter($metadata['sizes'], function($size) {
                    return strpos($size, 'liteimage-') !== 0;
                }, ARRAY_FILTER_USE_KEY);
                wp_update_attachment_metadata($image->ID, $metadata);
            }
        }
    }

    public static function clear_wordpress_thumbnails() {
        $images = get_posts([
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'numberposts' => -1,
        ]);
        $upload_dir = wp_upload_dir()['basedir'];

        foreach ($images as $image) {
            if (in_array(get_post_mime_type($image->ID), ['image/svg+xml', 'image/avif'])) {
                LiteImage_Logger::log("Skipping {$image->ID} (SVG/AVIF)");
                continue;
            }

            $file_path = get_attached_file($image->ID);
            $metadata = wp_get_attachment_metadata($image->ID) ?: ['sizes' => []];
            $base_path = $upload_dir . '/' . dirname($metadata['file'] ?: $file_path);
            $filename = pathinfo($file_path, PATHINFO_FILENAME);

            // Delete thumbnails from metadata
            if (isset($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $size => $data) {
                    if (strpos($size, 'liteimage-') === 0) {
                        continue; // Skip LiteImage thumbnails
                    }
                    if ($data['file']) {
                        $file = $base_path . '/' . $data['file'];
                        if (file_exists($file)) {
                            wp_delete_file($file);
                            LiteImage_Logger::log("Deleted WordPress thumbnail: $file for {$image->ID}");
                        }
                    }
                    if ($data['webp']) {
                        $webp = $base_path . '/' . $data['webp'];
                        if (file_exists($webp)) {
                            wp_delete_file($webp);
                            LiteImage_Logger::log("Deleted WordPress WebP: $webp for {$image->ID}");
                        }
                    }
                }
                $metadata['sizes'] = array_filter($metadata['sizes'], function($size) {
                    return strpos($size, 'liteimage-') === 0;
                }, ARRAY_FILTER_USE_KEY);
                wp_update_attachment_metadata($image->ID, $metadata);
            }

            // Scan folder for residual WordPress thumbnails
            $pattern = $base_path . '/' . $filename . '-*x*.{jpg,jpeg,png,gif,webp}';
            $residual_files = glob($pattern, GLOB_BRACE);
            foreach ($residual_files as $file) {
                if (strpos(basename($file), 'liteimage-') === false) {
                    if (file_exists($file)) {
                        wp_delete_file($file);
                        LiteImage_Logger::log("Deleted residual WordPress thumbnail: $file for {$image->ID}");
                    }
                }
            }
        }
    }
}

// Core image rendering
function liteimage($image_id, $data = [], $mobile_image_id = null) {
    if (!$image_id) {
        LiteImage_Logger::log("Invalid image ID: $image_id");
        return '';
    }

    $thumb = $data['thumb'] ?? [1920, 0];
    $args = $data['args'] ?? [];
    $min = $data['min'] ?? [];
    $max = $data['max'] ?? [];

    // Sort min (descending) and max (ascending)
    krsort($min);
    ksort($max);

    $thumb_data = LiteImage_Thumbnail_Generator::get_thumb_size($thumb, $image_id);
    $sizes_to_generate = [$thumb_data['size_name'] => [$thumb_data['width'], $thumb_data['height']]];

    foreach (['min' => $min, 'max' => $max] as $type => $sizes) {
        foreach ($sizes as $width => $dim) {
            $output_image_id = ($type === 'min' && $width > 0 && $width < 768 || $type === 'max' && $width < 768) && $mobile_image_id ? $mobile_image_id : $image_id;
            if ($output_image_id === $image_id) {
                $sizes_to_generate[$type . '-' . $width] = $dim;
            }
        }
    }

    $file_path = get_attached_file($image_id);
    $generated_size = LiteImage_Thumbnail_Generator::generate_thumbnails($image_id, $file_path, $sizes_to_generate);
    $metadata = wp_get_attachment_metadata($image_id);
    $original_extension = strtolower(pathinfo(get_attached_file($image_id), PATHINFO_EXTENSION));

    $thumb_size_name = ($metadata['extension'] ?? $original_extension) === 'svg' ? $thumb : $thumb_data['size_name'];

    if ($mobile_image_id && $mobile_image_id !== $image_id) {
        $mobile_sizes = [];
        foreach (['min' => $min, 'max' => $max] as $type => $sizes) {
            foreach ($sizes as $width => $dim) {
                if (($type === 'min' && $width > 0 && $width < 768) || ($type === 'max' && $width < 768)) {
                    $mobile_sizes[$type . '-' . $width] = $dim;
                }
            }
        }
        if ($mobile_sizes) {
            LiteImage_Thumbnail_Generator::generate_thumbnails($mobile_image_id, get_attached_file($mobile_image_id), $mobile_sizes);
        }
    }

    $image = wp_get_attachment_image_src($image_id, $thumb_size_name);
    if (!$image) {
        return '';
    }

    $default_type = "image/$original_extension";

    // Determine if image is decorative
    $is_decorative = !empty($args['decorative']) && $args['decorative'] === true;

    // Set picture attributes for accessibility
    $picture_attrs = $is_decorative ? ' aria-hidden="true"' : '';

    $output = '<picture role="img"' . $picture_attrs . '>';
    foreach (['min' => $min, 'max' => $max] as $type => $sizes) {
        foreach ($sizes as $width => $dim) {
            $output_image_id = ($type === 'min' && $width > 0 && $width < 768 || $type === 'max' && $width < 768) && $mobile_image_id ? $mobile_image_id : $image_id;
            $size_key = $type . '-' . $width;
            list($dest_width, $dest_height) = liteimage_downsize($output_image_id, $dim);
            $size_name = "liteimage-{$dest_width}x{$dest_height}";
            $source_image = wp_get_attachment_image_src($output_image_id, $size_name);

            if ($source_image) {
                $size_metadata = $metadata['sizes'][$size_name] ?? [];
                $extension = $size_metadata['extension'] ?? $original_extension;
                $type_attr = "image/$extension";

                if ($size_metadata['webp']) {
                    $webp_url = str_replace(basename($source_image[0]), $size_metadata['webp'], $source_image[0]);
                    $output .= '<source media="(' . ($type === 'min' ? 'min' : 'max') . '-width:' . esc_attr($width) . 'px)" srcset="' . esc_url($webp_url) . '" type="image/webp">';
                } else {
                    $output .= '<source media="(' . ($type === 'min' ? 'min' : 'max') . '-width:' . esc_attr($width) . 'px)" srcset="' . esc_url($source_image[0]) . '" type="' . esc_attr($type_attr) . '">';
                }
            }
        }
    }

    // Prepare img attributes
    $img_args = array_merge([
        'alt' => $is_decorative ? '' : (!empty($args['alt']) ? $args['alt'] : get_the_title($image_id)),
        'loading' => 'lazy',
        'decoding' => 'async',
    ], $args);

    if (in_array(get_post_mime_type($image_id), ['image/svg+xml', 'image/avif'])) {
        list($img_args['width'], $img_args['height']) = liteimage_downsize($image_id, $thumb);
    }

    // Define named filter function
    $remove_srcset_sizes = function ($attr, $attachment, $size) {
        unset($attr['srcset'], $attr['sizes']);
        return $attr;
    };

    // Add filter to remove srcset and sizes
    add_filter('wp_get_attachment_image_attributes', $remove_srcset_sizes, 999, 3);

    $output .= wp_get_attachment_image($image_id, $thumb_size_name, false, $img_args);
    $output .= '</picture>';

    // Remove filter
    remove_filter('wp_get_attachment_image_attributes', $remove_srcset_sizes, 999);

    return $output;
}

// Downsize function
function liteimage_downsize($id, $size = 'medium') {
    $meta = wp_get_attachment_metadata($id);
    $width = $meta['width'] ?? 0;
    $height = $meta['height'] ?? 0;

    if (is_array($size) && count($size) === 2) {
        return [$size[0], $size[1]];
    }

    if ($intermediate = image_get_intermediate_size($id, $size)) {
        $width = $intermediate['width'];
        $height = $intermediate['height'];
    }

    return image_constrain_size_for_editor($width, $height, $size);
}

// Fallback function
if (!function_exists('liteimage')) {
    function liteimage($image_id, $data = [], $mobile_image_id = null) {
        return apply_filters('liteimage_disabled_fallback', '', $image_id, $data, $mobile_image_id);
    }
}

// Initialize plugin
LiteImage_Settings::get_instance();
LiteImage_Admin::init();