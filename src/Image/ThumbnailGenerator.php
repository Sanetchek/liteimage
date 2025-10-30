<?php

/**
 * Thumbnail Generator class for LiteImage plugin
 *
 * @package LiteImage
 * @since 3.2.0
 */

namespace LiteImage\Image;

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Interfaces\ImageInterface;
use LiteImage\Config;
use LiteImage\Support\Logger;
use LiteImage\Support\WebPSupport;

defined('ABSPATH') || exit;

/**
 * Class ThumbnailGenerator
 *
 * Generates thumbnails and WebP versions using Intervention Image 3.x
 */
class ThumbnailGenerator
{
    /**
     * Get thumbnail size data
     *
     * @param mixed $thumb Thumbnail size specification
     * @param int|null $attachment_id Attachment ID
     * @return array Thumbnail data with size_name, width, height
     */
    public static function get_thumb_size($thumb, $attachment_id = null)
    {
        $thumb_data = ['size_name' => 'full', 'width' => 0, 'height' => 0];

        if (is_array($thumb) && isset($thumb[0], $thumb[1])) {
            $image_data = liteimage_downsize($attachment_id, [$thumb[0], $thumb[1]]);
            if ($image_data) {
                $thumb_data['width'] = $image_data[0];
                $thumb_data['height'] = $image_data[1];
                $thumb_data['size_name'] = Config::THUMBNAIL_PREFIX . "{$thumb_data['width']}x{$thumb_data['height']}";
                add_image_size(
                    $thumb_data['size_name'],
                    $thumb_data['width'],
                    $thumb_data['height'],
                    ($thumb_data['width'] && $thumb_data['height'])
                );
            }
        }
        return $thumb_data;
    }

    /**
     * Generate thumbnails for an attachment
     *
     * @param int $attachment_id Attachment ID
     * @param string $file_path File path
     * @param array $sizes Array of sizes to generate
     * @return string Last generated size name
     */
    public static function generate_thumbnails($attachment_id, $file_path, $sizes)
    {
        if (!file_exists($file_path) || !wp_get_attachment_image_src($attachment_id)) {
            Logger::log("Invalid file or attachment ID: $attachment_id");
            return '';
        }

        // Validate MIME type
        $file_type = wp_check_filetype($file_path);
        if (!in_array($file_type['type'], Config::ALLOWED_MIME_TYPES, true)) {
            Logger::log("Invalid MIME type for $file_path: " . $file_type['type']);
            return '';
        }

        $metadata = wp_get_attachment_metadata($attachment_id) ?: ['sizes' => []];
        $original_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

        $image_size = getimagesize($file_path);
        if ($image_size === false || !isset($image_size[0], $image_size[1])) {
            Logger::log("Failed to get image size for $file_path");
            return '';
        }

        list($orig_width, $orig_height) = $image_size;
        if (!$orig_width || !$orig_height) {
            Logger::log("Invalid image dimensions for $file_path");
            return '';
        }

        $image = self::load_image($file_path, $original_extension);
        if (!$image) {
            Logger::log("Failed to load image: $file_path");
            return '';
        }

        $updated_size_name = '';
        foreach ($sizes as $size_key => $dimensions) {
            list($width, $height) = $dimensions;
            list($dest_width, $dest_height) = liteimage_downsize($attachment_id, [$width, $height]);
            $size_name = Config::THUMBNAIL_PREFIX . "{$dest_width}x{$dest_height}";
            $updated_size_name = $size_name;
            $webp_path = str_replace(
                basename($file_path),
                basename($file_path, '.' . $original_extension) . "-$size_name.webp",
                $file_path
            );

            if (!isset($metadata['sizes'][$size_name]) || !file_exists($webp_path)) {
                self::generate_thumbnail(
                    $image,
                    $file_path,
                    $size_name,
                    $dest_width,
                    $dest_height,
                    $webp_path,
                    $original_extension,
                    $width && $height
                );
                $metadata['sizes'][$size_name] = [
                    'file' => basename($webp_path),
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

    /**
     * Load image using Intervention Image 3.x
     *
     * @param string $file_path File path
     * @param string $extension File extension
     * @return ImageInterface|null Image object or null on failure
     */
    private static function load_image($file_path, $extension)
    {
        if (!WebPSupport::is_webp_supported()) {
            Logger::log("WebP not supported, skipping Intervention Image");
            return null;
        }

        try {
            // Choose driver based on available PHP extensions
            $driver = (function_exists('imagewebp') && extension_loaded('gd')) ?
                new GdDriver() :
                new ImagickDriver();

            $manager = new ImageManager($driver);
            $image = $manager->read($file_path);
            Logger::log("Image loaded via Intervention 3.x: $file_path");
            return $image;
        } catch (\Exception $e) {
            Logger::log("Intervention Image 3.x failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate a single thumbnail
     *
     * @param ImageInterface $image Image object
     * @param string $file_path Original file path
     * @param string $size_name Size name
     * @param int $dest_width Destination width
     * @param int $dest_height Destination height
     * @param string $webp_path WebP file path
     * @param string $original_extension Original file extension
     * @param bool $crop Whether to crop
     * @return void
     */
    private static function generate_thumbnail($image, $file_path, $size_name, $dest_width, $dest_height, $webp_path, $original_extension, $crop = false)
    {
        // Check if using Intervention Image 3.x
        if (!$image instanceof ImageInterface) {
            Logger::log("Invalid image object for thumbnail generation");
            return;
        }

        if (WebPSupport::is_webp_supported()) {
            try {
                // Intervention Image 3.x API
                if ($crop) {
                    $resized = $image->cover($dest_width, $dest_height);
                } else {
                    $resized = $image->scale($dest_width, $dest_height);
                }

                // Save as WebP
                $resized->toWebp(Config::WEBP_QUALITY)->save($webp_path);
                Logger::log("Generated thumbnail via Intervention 3.x: $size_name, webp=$webp_path");
                return;
            } catch (\Exception $e) {
                Logger::log("Intervention thumbnail generation failed: " . $e->getMessage());
                return;
            }
        }
    }

    /**
     * Destroy image resource
     *
     * @param mixed $image Image object
     * @return void
     */
    private static function destroy_image($image)
    {
        // Intervention Image 3.x doesn't need explicit destruction
        // Resources are automatically cleaned up
        if ($image instanceof ImageInterface) {
            // No action needed
            return;
        }
    }
}

// Backward compatibility alias
class_alias('LiteImage\Image\ThumbnailGenerator', 'LiteImage_Thumbnail_Generator');
