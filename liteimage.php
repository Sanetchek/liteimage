<?php
/*
Plugin Name: LiteImage
Description: Optimizes images with dynamic thumbnail sizes, WebP support, and accessibility.
Version: 2.1
Author: Oleksandr Gryshko
Author URI: https://github.com/Sanetchek
Text Domain: liteimage
Domain Path: /languages
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Load Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';
use Intervention\Image\ImageManagerStatic as Image;

// Constants
define('LITEIMAGE_LOG_FILE', __DIR__ . '/liteimage-debug.log');

// Load settings
$liteimage_settings = get_option('liteimage_settings', [
    'disable_thumbnails' => false,
    'enable_logs' => false,
]);

// Apply thumbnail filter based on setting
if ($liteimage_settings['disable_thumbnails']) {
    add_filter('intermediate_image_sizes_advanced', '__return_empty_array');
}

// Define log constant based on setting
define('LITEIMAGE_LOG_ACTIVE', $liteimage_settings['enable_logs']);

/**
 * Logs a message to the LiteImage debug log file.
 *
 * @param string $message The log message.
 */
function liteimage_log($message) {
    if (!LITEIMAGE_LOG_ACTIVE) {
        return;
    }
    error_log(date('[Y-m-d H:i:s] ') . $message . PHP_EOL, 3, LITEIMAGE_LOG_FILE);
}

/**
 * Adds a link to the LiteImage settings page on the Plugins page.
 *
 * @param string[] $links The list of links.
 * @return string[] The list of links with the settings link added.
 */
function liteimage_add_settings_link($links) {
    $settings_link = '<a href="' . admin_url('tools.php?page=liteimage-settings') . '">' . __('Settings', 'liteimage') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'liteimage_add_settings_link');

/**
 * Checks if the cwebp command is available.
 *
 * @return bool True if cwebp is available, false otherwise.
 */
function liteimage_is_cwebp_available() {
    static $is_available = null;
    if ($is_available === null) {
        if (strncasecmp(PHP_OS, 'WIN', 3) == 0) {
            $is_available = false;
            liteimage_log("cwebp not found on Windows (no default path)");
        } else {
            $output = shell_exec('which cwebp 2>&1');
            $is_available = $output !== null && trim($output) !== '';
            liteimage_log("Checking cwebp availability: " . ($is_available ? 'Found' : 'Not found'));
        }
    }
    return $is_available;
}

/**
 * Checks if WebP image support is available.
 *
 * @return bool True if WebP support is available, false otherwise.
 */
function liteimage_is_webp_supported() {
    static $supported = null;
    if ($supported === null) {
        $supported = function_exists('imagewebp') || (class_exists('Imagick') && in_array('WEBP', Imagick::queryFormats()));
        liteimage_log("WebP support: " . ($supported ? 'Enabled' : 'Disabled'));
        liteimage_log("GD support: " . (function_exists('imagewebp') ? 'yes' : 'no'));
        liteimage_log("Imagick WebP: " . (class_exists('Imagick') && in_array('WEBP', Imagick::queryFormats()) ? 'yes' : 'no'));
    }
    return $supported;
}

/**
 * Loads the plugin textdomain for localization.
 */
function liteimage_load_textdomain() {
    load_plugin_textdomain('liteimage', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'liteimage_load_textdomain');

/**
 * Displays admin notices on the LiteImage settings page.
 */
function liteimage_admin_notices() {
    $screen = get_current_screen();
    if ($screen->id !== 'tools_page_liteimage-settings') {
        return;
    }

    if (!liteimage_is_cwebp_available() && !liteimage_is_webp_supported()) {
        echo '<div class="notice notice-warning"><p>' . esc_html__('WebP conversion requires <strong>cwebp</strong> or WebP support in GD/Imagick. Using compressed JPEG.', 'liteimage') . '</p></div>';
    } elseif (!liteimage_is_cwebp_available()) {
        echo '<div class="notice notice-info"><p>' . esc_html__('cwebp not found, using Intervention Image for WebP. Install cwebp for better performance.', 'liteimage') . '</p></div>';
    }
}
add_action('admin_notices', 'liteimage_admin_notices');

/**
 * Returns an associative array containing the size name, width, and height of a thumbnail.
 *
 * @param array|int $thumb Thumbnail size in format [width, height] or a single integer value for width.
 * @return array
 */
function liteimage_get_thumb_size($thumb) {
    $thumb_width = 1920;
    $thumb_height = 0;
    $thumb_size = 'full';

    if (is_array($thumb) && isset($thumb[0], $thumb[1])) {
        $thumb_width = $thumb[0] ?: 1920;
        $thumb_height = $thumb[1] ?: 0;
        $thumb_size = "liteimage-{$thumb_width}x{$thumb_height}";
        add_image_size($thumb_size, $thumb_width, $thumb_height, ($thumb_width && $thumb_height));
    }

    return ['size_name' => $thumb_size, 'width' => $thumb_width, 'height' => $thumb_height];
}

/**
 * Calculates the destination dimensions for an image.
 *
 * @param int $width The desired width.
 * @param int $height The desired height.
 * @param int $orig_width The original width.
 * @param int $orig_height The original height.
 * @return array
 */
function liteimage_calculate_dimensions($width, $height, $orig_width, $orig_height) {
    $dest_width = $width ?: (int)round(($height / $orig_height) * $orig_width);
    $dest_height = $height ?: (int)round(($width / $orig_width) * $orig_height);
    return [$dest_width, $dest_height];
}

/**
 * Generates thumbnails for a given image attachment.
 *
 * @param int $attachment_id The ID of the image attachment.
 * @param string $file_path The path to the original image file.
 * @param array $sizes An associative array of size keys and dimensions [width, height].
 * @return string The name of the last generated thumbnail size.
 */
function liteimage_generate_thumbnails_for_image($attachment_id, $file_path, $sizes) {
    liteimage_log("Generating thumbnails for attachment ID: $attachment_id");
    if (!file_exists($file_path)) {
        liteimage_log("File not found: $file_path");
        return '';
    }

    $metadata = wp_get_attachment_metadata($attachment_id) ?: ['sizes' => []];
    $is_webp = strtolower(pathinfo($file_path, PATHINFO_EXTENSION)) === 'webp';
    $original_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

    list($orig_width, $orig_height) = getimagesize($file_path);
    if (!$orig_width || !$orig_height) {
        liteimage_log("Invalid image dimensions for $file_path");
        return '';
    }

    $image = null;
    if (class_exists('Intervention\Image\ImageManagerStatic') && liteimage_is_webp_supported()) {
        try {
            if (function_exists('imagewebp')) {
                Image::configure(['driver' => 'gd']);
                liteimage_log("Using GD driver for Intervention Image");
            } elseif (class_exists('Imagick')) {
                Image::configure(['driver' => 'imagick']);
                liteimage_log("Using Imagick driver for Intervention Image");
            }
            $image = Image::make($file_path)->strip();
            liteimage_log("Image loaded via Intervention: $file_path");
        } catch (Exception $e) {
            liteimage_log("Intervention Image failed: " . $e->getMessage());
        }
    }

    if (!$image) {
        $image = $is_webp ? imagecreatefromwebp($file_path) : imagecreatefromstring(file_get_contents($file_path));
        if (!$image) {
            liteimage_log("Failed to load image with GD: $file_path");
            return '';
        }
        liteimage_log("Image loaded via GD: $file_path");
    }

    $updated_size_name = '';
    foreach ($sizes as $size_key => $dimensions) {
        list($width, $height) = $dimensions;
        list($dest_width, $dest_height) = liteimage_calculate_dimensions($width, $height, $orig_width, $orig_height);
        $size_name = "liteimage-{$dest_width}x{$dest_height}";
        $updated_size_name = $size_name;
        $webp_path = str_replace(basename($file_path), basename($file_path, '.' . $original_extension) . "-$size_name" . '.webp', $file_path);

        if (!isset($metadata['sizes'][$size_name]) || !file_exists($webp_path)) {
            $intervention_available = class_exists('Intervention\Image\ImageManagerStatic');
            liteimage_log("Intervention available: " . ($intervention_available ? 'yes' : 'no') . ", WebP supported: " . liteimage_is_webp_supported());

            if ($intervention_available && liteimage_is_webp_supported() && $image instanceof \Intervention\Image\Image) {
                $resized = $image;
                if ($dest_width || $dest_height) {
                    $resized = $image->resize($dest_width, $dest_height, function ($constraint) {
                        $constraint->aspectRatio();
                        $constraint->upsize();
                    });
                }
                $resized->encode('webp', 85)->save($webp_path);
                liteimage_log("Generated thumbnail: $size_name, webp=$webp_path");
            } else {
                $resized = imagecreatetruecolor($dest_width, $dest_height);
                imagecopyresampled($resized, $image, 0, 0, 0, 0, $dest_width, $dest_height, $orig_width, $orig_height);
                if (function_exists('imagewebp')) {
                    imagewebp($resized, $webp_path, 85);
                    liteimage_log("Generated WebP via GD: $webp_path");
                }
                imagedestroy($resized);
            }

            $metadata['sizes'][$size_name] = [
                'file' => $is_webp ? false : basename($webp_path),
                'webp' => basename($webp_path),
                'width' => $dest_width,
                'height' => $dest_height,
                'extension' => 'webp',
            ];
        }
    }

    if (class_exists('Intervention\Image\ImageManagerStatic') && liteimage_is_webp_supported() && $image instanceof \Intervention\Image\Image) {
        $image->destroy();
    } elseif ($image) {
        imagedestroy($image);
    }
    wp_update_attachment_metadata($attachment_id, $metadata);
    return $updated_size_name;
}

/**
 * Deletes all existing thumbnails for all image attachments.
 */
function liteimage_clear_all_thumbnails() {
    $images = get_posts(['post_type' => 'attachment', 'post_mime_type' => 'image', 'numberposts' => -1]);
    $upload_dir = wp_upload_dir();
    $base_dir = $upload_dir['basedir'];

    foreach ($images as $image) {
        $file_path = get_attached_file($image->ID);
        $metadata = wp_get_attachment_metadata($image->ID) ?: [];
        if (isset($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size => $data) {
                $base_path = $base_dir . '/' . dirname($metadata['file']);
                $old_file = $base_path . '/' . $data['file'];
                $old_webp = $base_path . '/' . ($data['webp'] ?: str_replace(['.jpg', '.jpeg', '.png', '.gif'], '.webp', $data['file'] ?: basename($file_path)));
                if (file_exists($old_file)) unlink($old_file);
                if (file_exists($old_webp)) unlink($old_webp);
            }
            $metadata['sizes'] = [];
            wp_update_attachment_metadata($image->ID, $metadata);
        }
    }
}

/**
 * Adds the LiteImage settings page to the Tools menu.
 */
function liteimage_add_settings_page_to_submenu() {
    add_submenu_page(
        'tools.php',
        __('LiteImage Settings', 'liteimage'),
        __('LiteImage Settings', 'liteimage'),
        'manage_options',
        'liteimage-settings',
        'liteimage_thumbnails_page'
    );
}
add_action('admin_menu', 'liteimage_add_settings_page_to_submenu');

/**
 * Registers plugin settings.
 */
function liteimage_register_settings() {
    register_setting('liteimage_settings_group', 'liteimage_settings', [
        'sanitize_callback' => 'liteimage_sanitize_settings',
    ]);
}
add_action('admin_init', 'liteimage_register_settings');

/**
 * Sanitizes plugin settings.
 *
 * @param array $input The input settings.
 * @return array The sanitized settings.
 */
function liteimage_sanitize_settings($input) {
    return [
        'disable_thumbnails' => isset($input['disable_thumbnails']) ? (bool)$input['disable_thumbnails'] : false,
        'enable_logs' => isset($input['enable_logs']) ? (bool)$input['enable_logs'] : false,
    ];
}

/**
 * Outputs the LiteImage settings page with tabs.
 */
function liteimage_thumbnails_page() {
    if (!current_user_can('manage_options')) return;

    $liteimage_settings = get_option('liteimage_settings', [
        'disable_thumbnails' => false,
        'enable_logs' => false,
    ]);

    if (isset($_POST['liteimage_clear_thumbnails'])) {
        check_admin_referer('liteimage_clear_thumbnails_nonce');
        liteimage_clear_all_thumbnails();
        echo '<div class="updated"><p>' . esc_html__('Thumbnails cleared successfully! New sizes will be generated on next call to liteimage.', 'liteimage') . '</p></div>';
    }

    $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
    ?>
    <div class="wrap">
        <h1><?php _e('LiteImage Settings', 'liteimage'); ?></h1>
        <h2 class="nav-tab-wrapper">
            <a href="?page=liteimage-settings&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>"><?php _e('General', 'liteimage'); ?></a>
            <a href="?page=liteimage-settings&tab=usage" class="nav-tab <?php echo $active_tab === 'usage' ? 'nav-tab-active' : ''; ?>"><?php _e('Usage Instructions', 'liteimage'); ?></a>
        </h2>

        <?php if ($active_tab === 'general') : ?>
            <form method="post" action="options.php">
                <?php
                settings_fields('liteimage_settings_group');
                do_settings_sections('liteimage-settings');
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Disable Thumbnails', 'liteimage'); ?></th>
                        <td>
                            <input type="checkbox" name="liteimage_settings[disable_thumbnails]" value="1" <?php checked($liteimage_settings['disable_thumbnails'], true); ?>>
                            <label><?php _e('Disable default WordPress thumbnails', 'liteimage'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Enable Logs', 'liteimage'); ?></th>
                        <td>
                            <input type="checkbox" name="liteimage_settings[enable_logs]" value="1" <?php checked($liteimage_settings['enable_logs'], true); ?>>
                            <label><?php _e('Enable debug logging', 'liteimage'); ?></label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <p><?php _e('Clears all existing thumbnails. New thumbnails will be generated when liteimage is called.', 'liteimage'); ?></p>
            <form method="post">
                <?php wp_nonce_field('liteimage_clear_thumbnails_nonce'); ?>
                <p><input type="submit" name="liteimage_clear_thumbnails" class="button button-primary" value="<?php _e('Clear Thumbnails', 'liteimage'); ?>"></p>
            </form>
        <?php else : ?>
            <h2><?php _e('Using the liteimage Function', 'liteimage'); ?></h2>
            <p><?php _e('The <code>liteimage</code> function generates responsive images with WebP support if available (via cwebp or GD/Imagick). Falls back to JPEG if WebP is unsupported.', 'liteimage'); ?></p>
            <h3><?php _e('Function Syntax', 'liteimage'); ?></h3>
            <pre>liteimage(int $image_id, array $data = [], int|null $mobile_image_id = null)</pre>
            <h3><?php _e('Parameters', 'liteimage'); ?></h3>
            <ul>
                <li><strong>$image_id</strong>: <?php _e('The ID of the image attachment.', 'liteimage'); ?></li>
                <li><strong>$data</strong>: <?php _e('An array of configuration options:', 'liteimage'); ?>
                    <ul>
                        <li><code>thumb</code>: <?php _e('Default image size (e.g., "full") or array [width, height] (e.g., [1280, 0]). Defaults to [1920, 0].', 'liteimage'); ?></li>
                        <li><code>args</code>: <?php _e('Additional attributes for the <img> tag (e.g., ["class" => "my-image", "alt" => "Text", "fetchpriority" => "high"]).', 'liteimage'); ?></li>
                        <li><code>min</code>: <?php _e('Array of min-width media queries and sizes (e.g., ["768" => [1920, 0]]).', 'liteimage'); ?></li>
                        <li><code>max</code>: <?php _e('Array of max-width media queries and sizes (e.g., ["767" => [768, 480]]).', 'liteimage'); ?></li>
                    </ul>
                </li>
                <li><strong>$mobile_image_id</strong>: <?php _e('Optional image ID to use for smaller screen widths (typically < 768px).', 'liteimage'); ?></li>
            </ul>
            <h3><?php _e('Examples', 'liteimage'); ?></h3>
            <pre><code>
// Basic usage with default size
echo liteimage(123);

// Custom size with alt and class
echo liteimage(123, [
    'thumb' => [1280, 720],
    'args' => ['alt' => 'My Image', 'class' => 'custom-class']
]);

// Responsive images for different screens
echo liteimage(123, [
    'thumb' => [1920, 0],
    'min' => ['768' => [1920, 0]],
    'max' => ['767' => [768, 480]],
    'args' => ['alt' => 'Responsive image', 'fetchpriority' => 'high']
]);

// Responsive with mobile-specific image
echo liteimage(123, [
    'thumb' => [1920, 0],
    'min' => ['768' => [1920, 0]],
    'max' => ['767' => [768, 480]],
], 456); // 456 is the mobile image ID
            </code></pre>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Adds a new column to the media list table for thumbnail sizes.
 *
 * @param array $columns The existing columns.
 * @return array The updated columns.
 */
function liteimage_add_thumbnail_sizes_column($columns) {
    $columns['thumbnail_sizes'] = __('Thumbnail Sizes', 'liteimage');
    return $columns;
}
add_filter('manage_media_columns', 'liteimage_add_thumbnail_sizes_column');

/**
 * Outputs the thumbnail sizes for each image attachment in the media list table.
 *
 * @param string $column_name The name of the column.
 * @param int $post_id The ID of the image attachment.
 */
function liteimage_display_thumbnail_sizes_column($column_name, $post_id) {
    if ($column_name === 'thumbnail_sizes') {
        $metadata = wp_get_attachment_metadata($post_id) ?: [];
        if (isset($metadata['sizes']) && !empty($metadata['sizes'])) {
            echo '<ul>';
            foreach ($metadata['sizes'] as $size => $data) {
                echo '<li>' . esc_html($size) . ': ' . $data['width'] . 'x' . $data['height'];
                if (isset($data['webp']) && $data['webp']) echo ' (WebP: ' . esc_html($data['webp']) . ')';
                echo '</li>';
            }
            echo '</ul>';
        } else {
            echo esc_html__('No thumbnails', 'liteimage');
        }
    }
}
add_action('manage_media_custom_column', 'liteimage_display_thumbnail_sizes_column', 10, 2);

/**
 * Generates responsive image HTML with WebP support.
 *
 * @param int $image_id The ID of the image attachment.
 * @param array $data An associative array of options.
 * @param int|null $mobile_image_id Optional ID for a mobile-specific image.
 * @return string The HTML output for the responsive image.
 */
function liteimage($image_id, $data = [], $mobile_image_id = null) {
    $thumb = $data['thumb'] ?? [1920, 0];
    $args = $data['args'] ?? [];
    $min = $data['min'] ?? [];
    $max = $data['max'] ?? [];
    $thumb_data = liteimage_get_thumb_size($thumb);
    $sizes_to_generate = [$thumb_data['size_name'] => [$thumb_data['width'], $thumb_data['height']]];

    $min_sizes = [];
    $max_sizes = [];
    foreach (['min' => $min, 'max' => $max] as $type => $sizes) {
        foreach ($sizes as $width => $dim) {
            $output_image_id = ($type === 'min' && intval($width) > 0 && intval($width) < 768 || $type === 'max' && intval($width) < 768) && $mobile_image_id ? $mobile_image_id : $image_id;
            if ($output_image_id === $image_id) {
                ${$type . '_sizes'}[$type . '-' . $width] = $dim;
            }
        }
    }

    if (!empty($min_sizes) || !empty($max_sizes)) {
        $sizes_to_generate = array_merge($sizes_to_generate, $min_sizes, $max_sizes);
    }
    $file_path = get_attached_file($image_id);
    $generated_size = liteimage_generate_thumbnails_for_image($image_id, $file_path, $sizes_to_generate);
    $thumb_size_name = $generated_size ?: $thumb_data['size_name'];

    if ($mobile_image_id && $mobile_image_id !== $image_id) {
        $mobile_sizes_to_generate = [];
        foreach (['min' => $min, 'max' => $max] as $type => $sizes) {
            foreach ($sizes as $width => $dim) {
                if (($type === 'min' && intval($width) > 0 && intval($width) < 768 || $type === 'max' && intval($width) < 768)) {
                    $mobile_sizes_to_generate[$type . '-' . $width] = $dim;
                }
            }
        }
        if (!empty($mobile_sizes_to_generate)) {
            $mobile_file_path = get_attached_file($mobile_image_id);
            liteimage_generate_thumbnails_for_image($mobile_image_id, $mobile_file_path, $mobile_sizes_to_generate);
        }
    }

    $image = wp_get_attachment_image_src($image_id, $thumb_size_name);
    if (!$image) return '';

    $output = '<picture role="img">';
    $metadata = wp_get_attachment_metadata($image_id);
    $original_extension = strtolower(pathinfo(get_attached_file($image_id), PATHINFO_EXTENSION));
    $default_type = ($metadata['extension'] ?? $original_extension) === 'webp' ? 'image/webp' : 'image/' . $original_extension;

    foreach (['min' => $min, 'max' => $max] as $type => $sizes) {
        foreach ($sizes as $width => $dim) {
            $output_image_id = ($type === 'min' && intval($width) > 0 && intval($width) < 768 || $type === 'max' && intval($width) < 768) && $mobile_image_id ? $mobile_image_id : $image_id;
            $size_key = $type . '-' . $width;
            list($dim_width, $dim_height) = $dim;
            list($dest_width, $dest_height) = liteimage_calculate_dimensions($dim_width, $dim_height, $image[1], $image[2]);
            $size_name = "liteimage-{$dest_width}x{$dest_height}";

            $source_image = wp_get_attachment_image_src($output_image_id, $size_name);
            if ($source_image) {
                $size_metadata = $metadata['sizes'][$size_name] ?? [];
                $extension = $size_metadata['extension'] ?? $original_extension;
                $type_attr = $extension === 'webp' ? 'image/webp' : "image/$extension";

                if (isset($size_metadata['webp']) && $size_metadata['webp']) {
                    $webp_url = str_replace(basename($source_image[0]), $size_metadata['webp'], $source_image[0]);
                    liteimage_log("WebP found for $size_name: $webp_url");
                    $output .= '<source media="(' . ($type === 'min' ? 'min' : 'max') . '-width:' . esc_attr($width) . 'px)" srcset="' . esc_url($webp_url) . '" type="image/webp">';
                } else {
                    $output .= '<source media="(' . ($type === 'min' ? 'min' : 'max') . '-width:' . esc_attr($width) . 'px)" srcset="' . esc_url($source_image[0]) . '" type="' . esc_attr($type_attr) . '">';
                }
            }
        }
    }

    $img_attributes = 'loading="lazy" decoding="async"';
    if (!empty($args['decorative'])) {
        $img_attributes .= ' alt=""';
    } else {
        $alt_text = !empty($args['alt']) ? $args['alt'] : get_the_title($image_id);
        $img_attributes .= ' alt="' . esc_attr($alt_text) . '"';
    }

    $img_metadata = $metadata['sizes'][$thumb_size_name] ?? [];
    $img_width = $image[1];
    $img_height = $image[2];
    if (isset($img_metadata['width'])) {
        $img_width = $img_metadata['width'];
        $img_height = $img_metadata['height'];
    }
    $img_attributes .= ' width="' . esc_attr($img_width) . '" height="' . esc_attr($img_height) . '"';

    $img_extension = $img_metadata['extension'] ?? $original_extension;
    $img_type = $img_extension === 'webp' ? 'image/webp' : "image/$img_extension";
    $img_attributes .= ' type="' . esc_attr($img_type) . '"';

    if (!empty($args['fetchpriority']) && $args['fetchpriority'] === 'high') {
        $img_attributes .= ' fetchpriority="high"';
    }
    if (!empty($args['aria-label'])) {
        $img_attributes .= ' aria-label="' . esc_attr($args['aria-label']) . '"';
    }
    if (!empty($args['aria-describedby'])) {
        $img_attributes .= ' aria-describedby="' . esc_attr($args['aria-describedby']) . '"';
    }

    unset($args['alt'], $args['decorative'], $args['aria-label'], $args['aria-describedby'], $args['fetchpriority']);
    foreach ($args as $key => $value) {
        $img_attributes .= ' ' . esc_attr($key) . '="' . esc_attr($value) . '"';
    }

    $output .= '<img src="' . esc_url($image[0]) . '" ' . $img_attributes . '>';
    $output .= '</picture>';

    return $output;
}