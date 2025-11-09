<?php

/**
 * Thumbnail Cleaner class for LiteImage plugin
 *
 * @package LiteImage
 * @since 3.2.0
 */

namespace LiteImage\Image;

use LiteImage\Config;
use LiteImage\Support\Logger;

defined('ABSPATH') || exit;

/**
 * Class ThumbnailCleaner
 *
 * Handles cleanup of thumbnails
 */
class ThumbnailCleaner
{
    /**
     * Clear all LiteImage thumbnails
     *
     * @return int Number of thumbnails deleted
     */
    public static function clear_all_thumbnails()
    {
        $deleted_count = 0;
        $paged = 0;
        $per_page = Config::CLEANUP_BATCH_SIZE;

        do {
            $images = get_posts([
                'post_type' => 'attachment',
                'post_mime_type' => 'image',
                'numberposts' => $per_page,
                'offset' => $paged * $per_page,
                'fields' => 'ids',
            ]);

            if (empty($images)) {
                break;
            }

            $upload_dir = wp_upload_dir()['basedir'];

            foreach ($images as $image_id) {
                if (in_array(get_post_mime_type($image_id), ['image/svg+xml', 'image/avif'])) {
                    Logger::log("Skipping {$image_id} (SVG/AVIF)");
                    continue;
                }

                $file_path = get_attached_file($image_id);
                $metadata = wp_get_attachment_metadata($image_id) ?: [];

                if (isset($metadata['sizes'])) {
                    foreach ($metadata['sizes'] as $size => $data) {
                        if (strpos($size, Config::THUMBNAIL_PREFIX) !== 0) {
                            continue; // Skip non-LiteImage thumbnails
                        }

                        $base_path = $upload_dir . '/' . dirname($metadata['file']);

                        $is_retina = !empty($data['is_retina']) || (strpos($size, '@2x') !== false);
                        $label = $is_retina ? 'LiteImage retina thumbnail' : 'LiteImage thumbnail';

                        if ($data['file']) {
                            $file = $base_path . '/' . $data['file'];
                            if (file_exists($file)) {
                                wp_delete_file($file);
                                $deleted_count++;
                                Logger::log("Deleted {$label}: $file for {$image_id}");
                            }
                        }

                        if (isset($data['webp']) && $data['webp']) {
                            $webp = $base_path . '/' . $data['webp'];
                            if (file_exists($webp)) {
                                wp_delete_file($webp);
                                $deleted_count++;
                                Logger::log("Deleted {$label} (WebP): $webp for {$image_id}");
                            }
                        }
                    }

                    $metadata['sizes'] = array_filter($metadata['sizes'], function ($size) {
                        return strpos($size, Config::THUMBNAIL_PREFIX) !== 0;
                    }, ARRAY_FILTER_USE_KEY);

                    wp_update_attachment_metadata($image_id, $metadata);
                }
            }

            $paged++;
        } while (count($images) === $per_page);

        return $deleted_count;
    }

    /**
     * Clear WordPress-generated thumbnails (non-LiteImage)
     *
     * @return int Number of thumbnails deleted
     */
    public static function clear_wordpress_thumbnails()
    {
        $deleted_count = 0;
        $paged = 0;
        $per_page = Config::CLEANUP_BATCH_SIZE;

        do {
            $images = get_posts([
                'post_type' => 'attachment',
                'post_mime_type' => 'image',
                'numberposts' => $per_page,
                'offset' => $paged * $per_page,
                'fields' => 'ids',
            ]);

            if (empty($images)) {
                break;
            }

            $upload_dir = wp_upload_dir()['basedir'];

            foreach ($images as $image_id) {
                if (in_array(get_post_mime_type($image_id), ['image/svg+xml', 'image/avif'])) {
                    Logger::log("Skipping {$image_id} (SVG/AVIF)");
                    continue;
                }

                $file_path = get_attached_file($image_id);
                $metadata = wp_get_attachment_metadata($image_id) ?: ['sizes' => []];
                $base_path = $upload_dir . '/' . dirname($metadata['file'] ?: $file_path);
                $filename = pathinfo($file_path, PATHINFO_FILENAME);

                // Delete thumbnails from metadata
                if (isset($metadata['sizes'])) {
                    foreach ($metadata['sizes'] as $size => $data) {
                        if (strpos($size, Config::THUMBNAIL_PREFIX) === 0) {
                            continue; // Skip LiteImage thumbnails
                        }

                        $is_retina = !empty($data['is_retina']) || (strpos($size, '@2x') !== false);
                        $label = $is_retina ? 'WordPress retina thumbnail' : 'WordPress thumbnail';

                        if ($data['file']) {
                            $file = $base_path . '/' . $data['file'];
                            if (file_exists($file)) {
                                wp_delete_file($file);
                                $deleted_count++;
                                Logger::log("Deleted {$label}: $file for {$image_id}");
                            }
                        }

                        if (isset($data['webp']) && $data['webp']) {
                            $webp = $base_path . '/' . $data['webp'];
                            if (file_exists($webp)) {
                                wp_delete_file($webp);
                                $deleted_count++;
                                Logger::log("Deleted {$label} (WebP): $webp for {$image_id}");
                            }
                        }
                    }

                    $metadata['sizes'] = array_filter($metadata['sizes'], function ($size) {
                        return strpos($size, Config::THUMBNAIL_PREFIX) === 0;
                    }, ARRAY_FILTER_USE_KEY);

                    wp_update_attachment_metadata($image_id, $metadata);
                }

                // Scan folder for residual WordPress thumbnails
                $pattern = $base_path . '/' . $filename . '-*x*.{jpg,jpeg,png,gif,webp}';
                $residual_files = glob($pattern, GLOB_BRACE);
                if ($residual_files) {
                    foreach ($residual_files as $file) {
                        $basename = basename($file);
                        if (strpos($basename, Config::THUMBNAIL_PREFIX) === false) {
                            if (file_exists($file)) {
                                wp_delete_file($file);
                                $deleted_count++;
                                Logger::log("Deleted residual WordPress thumbnail: $file for {$image_id}");
                            }
                        }
                    }
                }
            }

            $paged++;
        } while (count($images) === $per_page);

        return $deleted_count;
    }
}

// Backward compatibility alias
class_alias('LiteImage\Image\ThumbnailCleaner', 'LiteImage_Thumbnail_Cleaner');
