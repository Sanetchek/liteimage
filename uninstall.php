<?php

/**
 * Uninstall script for LiteImage plugin
 *
 * @package LiteImage
 * @since 3.2.0
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('liteimage_settings');

// Delete all transients
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup requires direct DB access
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_liteimage_%' OR option_name LIKE '_transient_timeout_liteimage_%'"
);

// Optionally delete logs (users might want to keep them)
// Uncomment the following lines if you want to delete logs on uninstall
$upload_dir = wp_upload_dir();
$log_dir = $upload_dir['basedir'] . '/liteimage-logs/';

if (file_exists($log_dir)) {
    // Load WordPress filesystem
    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    WP_Filesystem();
    global $wp_filesystem;

    // Delete log directory and all files
    $wp_filesystem->rmdir($log_dir, true);
}

// Optional: Remove LiteImage thumbnails
// This is commented out by default to avoid data loss
// Uncomment if you want to clean up thumbnails on uninstall
$images = get_posts([
    'post_type' => 'attachment',
    'post_mime_type' => 'image',
    'numberposts' => -1,
    'fields' => 'ids',
]);

foreach ($images as $image_id) {
    $metadata = wp_get_attachment_metadata($image_id);
    if (!$metadata || !isset($metadata['sizes'])) {
        continue;
    }

    $upload_dir = wp_upload_dir()['basedir'];
    $base_path = $upload_dir . '/' . dirname($metadata['file']);

    foreach ($metadata['sizes'] as $size => $data) {
        if (strpos($size, 'liteimage-') !== 0) {
            continue;
        }

        // Delete thumbnail file
        if (isset($data['file'])) {
            $file = $base_path . '/' . $data['file'];
            if (file_exists($file)) {
                wp_delete_file($file);
            }
        }

        // Delete WebP file
        if (isset($data['webp'])) {
            $webp = $base_path . '/' . $data['webp'];
            if (file_exists($webp)) {
                wp_delete_file($webp);
            }
        }
    }

    // Remove LiteImage sizes from metadata
    $metadata['sizes'] = array_filter($metadata['sizes'], function ($size) {
        return strpos($size, 'liteimage-') !== 0;
    }, ARRAY_FILTER_USE_KEY);

    wp_update_attachment_metadata($image_id, $metadata);
}
