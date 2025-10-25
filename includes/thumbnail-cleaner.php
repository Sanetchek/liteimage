<?php
defined('ABSPATH') || exit;

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
                    if (isset($data['webp']) && $data['webp']) {
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
                    if (isset($data['webp']) && $data['webp']) {
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