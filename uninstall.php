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
$liteimage_upload_data = wp_upload_dir();
$liteimage_upload_base = isset($liteimage_upload_data['basedir']) ? rtrim($liteimage_upload_data['basedir'], '/\\') : '';
$liteimage_log_dir = $liteimage_upload_base !== '' ? $liteimage_upload_base . '/liteimage-logs/' : '';

if ($liteimage_log_dir !== '' && file_exists($liteimage_log_dir)) {
    // Load WordPress filesystem
    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    WP_Filesystem();
    global $wp_filesystem;

	// Delete log directory and all files
	$wp_filesystem->rmdir($liteimage_log_dir, true);
}

// Optional: Remove LiteImage thumbnails
// This is commented out by default to avoid data loss
// Uncomment if you want to clean up thumbnails on uninstall
$liteimage_attachment_ids = get_posts([
    'post_type' => 'attachment',
    'post_mime_type' => 'image',
    'numberposts' => -1,
    'fields' => 'ids',
]);

foreach ($liteimage_attachment_ids as $liteimage_attachment_id) {
	$liteimage_metadata = wp_get_attachment_metadata($liteimage_attachment_id);
	if (!$liteimage_metadata || !isset($liteimage_metadata['sizes'])) {
        continue;
    }

	if ($liteimage_upload_base === '') {
		continue;
	}

	$liteimage_base_path = $liteimage_upload_base . '/' . dirname($liteimage_metadata['file']);

	foreach ($liteimage_metadata['sizes'] as $liteimage_size_key => $liteimage_size_data) {
		if (strpos($liteimage_size_key, 'liteimage-') !== 0) {
            continue;
        }

        // Delete thumbnail file
		if (isset($liteimage_size_data['file'])) {
			$liteimage_file_path = $liteimage_base_path . '/' . $liteimage_size_data['file'];
			if (file_exists($liteimage_file_path)) {
				wp_delete_file($liteimage_file_path);
            }
        }

        // Delete WebP file
		if (isset($liteimage_size_data['webp'])) {
			$liteimage_webp_path = $liteimage_base_path . '/' . $liteimage_size_data['webp'];
			if (file_exists($liteimage_webp_path)) {
				wp_delete_file($liteimage_webp_path);
            }
        }
    }

    // Remove LiteImage sizes from metadata
	$liteimage_metadata['sizes'] = array_filter($liteimage_metadata['sizes'], function ($liteimage_size_key) {
		return strpos($liteimage_size_key, 'liteimage-') !== 0;
    }, ARRAY_FILTER_USE_KEY);

	wp_update_attachment_metadata($liteimage_attachment_id, $liteimage_metadata);
}
