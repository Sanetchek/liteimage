<?php
/*
Plugin Name: LiteImage
Description: Optimizes images with dynamic thumbnails, WebP support, and accessibility.
Version: 3.1
Author: Oleksandr Gryshko
Author URI: https://github.com/Sanetchek
Text Domain: liteimage
Domain Path: /languages
*/

// Exit if accessed directly
defined('ABSPATH') || exit;

// Load dependencies
require_once __DIR__ . '/vendor/autoload.php';

use Intervention\Image\ImageManagerStatic as Image;

// Constants
define('LITEIMAGE_LOG_FILE', __DIR__ . '/liteimage-debug.log');

// Initialize settings
class LiteImage_Settings {
    private static $instance = null;
    private $settings;

    private function __construct() {
        $this->settings = get_option('liteimage_settings', [
            'disable_thumbnails' => false,
            'enable_logs' => false,
        ]);
        define('LITEIMAGE_LOG_ACTIVE', $this->settings['enable_logs']);
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
        if (LITEIMAGE_LOG_ACTIVE) {
            error_log(date('[Y-m-d H:i:s] ') . $message . PHP_EOL, 3, LITEIMAGE_LOG_FILE);
        }
    }
}

// WebP support checker
class LiteImage_WebP_Support {
    private static $cwebp_available = null;
    private static $webp_supported = null;

    public static function is_cwebp_available() {
        if (self::$cwebp_available === null) {
            self::$cwebp_available = strncasecmp(PHP_OS, 'WIN', 3) !== 0 && shell_exec('which cwebp 2>&1') !== null;
            LiteImage_Logger::log("cwebp availability: " . (self::$cwebp_available ? 'Found' : 'Not found'));
        }
        return self::$cwebp_available;
    }

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

        if (is_array($thumb) && isset($thumb[0], $thumb[1]) && $attachment_id) {
            $image_data = wp_get_attachment_image_src($attachment_id, [$thumb[0], $thumb[1]]);
            if ($image_data) {
                $thumb_data['width'] = $image_data[1];
                $thumb_data['height'] = $image_data[2];
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
            }
        }

        $image = $extension === 'webp' ? imagecreatefromwebp($file_path) : imagecreatefromstring(file_get_contents($file_path));
        if ($image) {
            LiteImage_Logger::log("Image loaded via GD: $file_path");
        } else {
            LiteImage_Logger::log("Failed to load image with GD: $file_path");
        }
        return $image;
    }

    private static function generate_thumbnail($image, $file_path, $size_name, $dest_width, $dest_height, $webp_path, $original_extension) {
        if (class_exists('Intervention\Image\ImageManagerStatic') && LiteImage_WebP_Support::is_webp_supported() && $image instanceof \Intervention\Image\Image) {
            $resized = $dest_width || $dest_height ? $image->resize($dest_width, $dest_height, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            }) : $image;

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
            list($orig_width, $orig_height) = getimagesize($file_path);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $dest_width, $dest_height, $orig_width, $orig_height);
            if (function_exists('imagewebp')) {
                imagewebp($resized, $webp_path, 85);
                LiteImage_Logger::log("Generated WebP via GD: $webp_path");
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
            'enable_logs' => !empty($input['enable_logs']),
        ];
    }

    public static function show_admin_notices() {
        if (get_current_screen()->id !== 'tools_page_liteimage-settings') {
            return;
        }

        if (!LiteImage_WebP_Support::is_cwebp_available() && !LiteImage_WebP_Support::is_webp_supported()) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('WebP conversion requires cwebp or GD/Imagick support. Using compressed JPEG.', 'liteimage') . '</p></div>';
        } elseif (!LiteImage_WebP_Support::is_cwebp_available()) {
            echo '<div class="notice notice-info"><p>' . esc_html__('cwebp not found, using Intervention Image for WebP. Install cwebp for better performance.', 'liteimage') . '</p></div>';
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

        $active_tab = $_GET['tab'] ?? 'general';
        ?>
        <div class="wrap">
            <h1><?php _e('LiteImage Settings', 'liteimage'); ?></h1>
            <p><?php _e('LiteImage optimizes images with dynamic thumbnails, WebP support, and accessibility features. Configure settings below or learn how to use the plugin.', 'liteimage'); ?></p>
            <h2 class="nav-tab-wrapper">
                <a href="?page=liteimage-settings&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>"><?php _e('General', 'liteimage'); ?></a>
                <a href="?page=liteimage-settings&tab=usage" class="nav-tab <?php echo $active_tab === 'usage' ? 'nav-tab-active' : ''; ?>"><?php _e('Usage Instructions', 'liteimage'); ?></a>
            </h2>

            <?php if ($active_tab === 'general') : ?>
                <h2><?php _e('General Settings', 'liteimage'); ?></h2>
                <form method="post" action="options.php">
                    <?php
                    settings_fields('liteimage_settings_group');
                    do_settings_sections('liteimage-settings');
                    ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Disable Thumbnails', 'liteimage'); ?></th>
                            <td>
                                <input type="checkbox" name="liteimage_settings[disable_thumbnails]" value="1" <?php checked($settings->get('disable_thumbnails'), true); ?>>
                                <label><?php _e('Disable default WordPress thumbnails', 'liteimage'); ?></label>
                                <p class="description"><?php _e('Prevents WordPress from generating default thumbnail sizes, relying solely on LiteImage dynamic sizes.', 'liteimage'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Enable Logs', 'liteimage'); ?></th>
                            <td>
                                <input type="checkbox" name="liteimage_settings[enable_logs]" value="1" <?php checked($settings->get('enable_logs'), true); ?>>
                                <label><?php _e('Enable debug logging', 'liteimage'); ?></label>
                                <p class="description"><?php _e('Logs plugin operations to liteimage-debug.log in the plugin directory for troubleshooting.', 'liteimage'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <h3><?php _e('WebP Support Status', 'liteimage'); ?></h3>
                    <p>
                        <?php
                        if (LiteImage_WebP_Support::is_cwebp_available()) {
                            _e('cwebp is available for optimal WebP conversion.', 'liteimage');
                        } elseif (LiteImage_WebP_Support::is_webp_supported()) {
                            _e('WebP supported via GD/Imagick. Install cwebp for better performance.', 'liteimage');
                        } else {
                            _e('WebP conversion unavailable. Install cwebp or enable GD/Imagick WebP support for optimal performance.', 'liteimage');
                        }
                        ?>
                    </p>
                    <?php submit_button(); ?>
                </form>
                <h3><?php _e('Thumbnail Management', 'liteimage'); ?></h3>
                <p><?php _e('Manage thumbnails generated by LiteImage and WordPress.', 'liteimage'); ?></p>
                <form method="post" style="margin-bottom: 1em;">
                    <?php wp_nonce_field('liteimage_clear_thumbnails_nonce'); ?>
                    <p><input type="submit" name="liteimage_clear_thumbnails" class="button button-primary" value="<?php _e('Clear LiteImage Thumbnails', 'liteimage'); ?>"></p>
                    <p class="description"><?php _e('Remove all LiteImage-generated thumbnails and WebP images. New thumbnails will be created when liteimage is called.', 'liteimage'); ?></p>
                </form>
                <form method="post">
                    <?php wp_nonce_field('liteimage_clear_wp_thumbnails_nonce'); ?>
                    <p><input type="submit" name="liteimage_clear_wp_thumbnails" class="button button-primary" value="<?php _e('Clear WordPress Thumbnails', 'liteimage'); ?>"></p>
                    <p class="description"><?php _e('Remove all WordPress-generated thumbnails (excluding LiteImage thumbnails). WordPress will regenerate them as needed.', 'liteimage'); ?></p>
                </form>
            <?php else : ?>
                <h2><?php _e('Using the liteimage Function', 'liteimage'); ?></h2>
                <p><?php _e('The <code>liteimage</code> function generates responsive images with WebP support if available, falling back to JPEG/PNG if not. Use it in your theme or templates to optimize image delivery.', 'liteimage'); ?></p>
                <h3><?php _e('Function Syntax', 'liteimage'); ?></h3>
                <pre>liteimage(int $image_id, array $data = [], int|null $mobile_image_id = null)</pre>


                <h3><?php _e('Parameters', 'liteimage'); ?></h3>
                <ul>
                    <li><strong>$image_id</strong>: <?php _e('The ID of the image attachment from the Media Library.', 'liteimage'); ?></li>
                    <li><strong>$data</strong>: <?php _e('Configuration options for image rendering:', 'liteimage'); ?>
                        <ul>
                            <li><code>thumb</code>: <?php _e('Default size (e.g., "full" or [width, height], default [1920, 0]).', 'liteimage'); ?></li>
                            <li><code>args</code>: <?php _e('HTML attributes for the <img> tag (e.g., ["class" => "my-image", "alt" => "Description"]).', 'liteimage'); ?></li>
                            <li><code>min</code>: <?php _e('Min-width media queries (e.g., ["768" => [1920, 0]]).', 'liteimage'); ?></ afstand
                            <li><code>max</code>: <?php _e('Max-width media queries (e.g., ["767" => [768, 480]]).', 'liteimage'); ?></li>
                        </ul>
                    </li>
                    <li><strong>$mobile_image_id</strong>: <?php _e('Optional ID for a mobile-specific image (used for screens < 768px).', 'liteimage'); ?></li>
                </ul>
                <h3><?php _e('Examples', 'liteimage'); ?></h3>
                <pre><code>
echo liteimage(123); // Basic usage
echo liteimage(123, ['thumb' => [1280, 720], 'args' => ['alt' => 'My Image', 'class' => 'custom-class']]); // Custom size
echo liteimage(123, ['thumb' => [1920, 0], 'min' => ['768' => [1920, 0]], 'max' => ['767' => [768, 480]], 'args' => ['alt' => 'Responsive image']], 456); // Responsive with mobile image
                </code></pre>
                <h3><?php _e('Tips for Optimal Use', 'liteimage'); ?></h3>
                <ul>
                    <li><?php _e('Use specific thumbnail sizes to reduce server load and improve performance.', 'liteimage'); ?></li>
                    <li><?php _e('Enable WebP support by installing cwebp or ensuring GD/Imagick supports WebP for faster image loading.', 'liteimage'); ?></li>
                    <li><?php _e('Clear thumbnails periodically to remove unused sizes and save disk space.', 'liteimage'); ?></li>
                    <li><?php _e('Add accessibility attributes like "alt" and "aria-label" in the args array for better SEO and usability.', 'liteimage'); ?></li>
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
                echo '<li>' . esc_html($size) . ': ' . $data['width'] . 'x' . $data['height'];
                if (isset($data['webp'])) {
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
                            unlink($file);
                            LiteImage_Logger::log("Deleted LiteImage thumbnail: $file for {$image->ID}");
                        }
                    }
                    if ($data['webp']) {
                        $webp = $base_path . '/' . $data['webp'];
                        if (file_exists($webp)) {
                            unlink($webp);
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
                            unlink($file);
                            LiteImage_Logger::log("Deleted WordPress thumbnail: $file for {$image->ID}");
                        }
                    }
                    if ($data['webp']) {
                        $webp = $base_path . '/' . $data['webp'];
                        if (file_exists($webp)) {
                            unlink($webp);
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
                        unlink($file);
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
    $thumb_size_name = $generated_size ?: $thumb_data['size_name'];

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

    $metadata = wp_get_attachment_metadata($image_id);
    $original_extension = strtolower(pathinfo(get_attached_file($image_id), PATHINFO_EXTENSION));
    $default_type = ($metadata['extension'] ?? $original_extension) === 'webp' ? 'image/webp' : "image/$original_extension";

    $output = '<picture role="img">';
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
                $type_attr = $extension === 'webp' ? 'image/webp' : "image/$extension";

                if ($size_metadata['webp']) {
                    $webp_url = str_replace(basename($source_image[0]), $size_metadata['webp'], $source_image[0]);
                    $output .= '<source media="(' . ($type === 'min' ? 'min' : 'max') . '-width:' . esc_attr($width) . 'px)" srcset="' . esc_url($webp_url) . '" type="image/webp">';
                } else {
                    $output .= '<source media="(' . ($type === 'min' ? 'min' : 'max') . '-width:' . esc_attr($width) . 'px)" srcset="' . esc_url($source_image[0]) . '" type="' . esc_attr($type_attr) . '">';
                }
            }
        }
    }

    $img_attributes = 'loading="lazy" decoding="async"';
    $alt_text = !empty($args['alt']) ? $args['alt'] : ($args['decorative'] ?? false ? '' : get_the_title($image_id));
    $img_attributes .= ' alt="' . esc_attr($alt_text) . '"';

    $img_metadata = $metadata['sizes'][$thumb_size_name] ?? [];
    $img_width = $img_metadata['width'] ?? $image[1];
    $img_height = $img_metadata['height'] ?? $image[2];

    if (in_array(get_post_mime_type($image_id), ['image/svg+xml', 'image/avif'])) {
        list($img_width, $img_height) = liteimage_downsize($image_id, $thumb);
    }

    $img_attributes .= ' width="' . esc_attr($img_width) . '" height="' . esc_attr($img_height) . '"';
    $img_extension = $img_metadata['extension'] ?? $original_extension;
    $img_attributes .= ' type="' . ($img_extension === 'webp' ? 'image/webp' : "image/$img_extension") . '"';

    if (!empty($args['fetchpriority']) && $args['fetchpriority'] === 'high') {
        $img_attributes .= ' fetchpriority="high"';
    }
    foreach (['aria-label', 'aria-describedby'] as $attr) {
        if (!empty($args[$attr])) {
            $img_attributes .= ' ' . $attr . '="' . esc_attr($args[$attr]) . '"';
        }
    }

    unset($args['alt'], $args['decorative'], $args['aria-label'], $args['aria-describedby'], $args['fetchpriority']);
    foreach ($args as $key => $value) {
        $img_attributes .= ' ' . esc_attr($key) . '="' . esc_attr($value) . '"';
    }

    $output .= '<img src="' . esc_url($image[0]) . '" ' . $img_attributes . '>';
    $output .= '</picture>';

    return $output;
}

// Downsize function
function liteimage_downsize($id, $size = 'medium') {
    $meta = wp_get_attachment_metadata($id);
    $width = $meta['width'] ?? 0;
    $height = $meta['height'] ?? 0;

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