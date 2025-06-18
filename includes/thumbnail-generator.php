<?php
defined('ABSPATH') || exit;

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
                self::generate_thumbnail($image, $file_path, $size_name, $dest_width, $dest_height, $webp_path, $original_extension, $width && $height);
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
        if (LiteImage_WebP_Support::is_webp_supported() && class_exists('\Intervention\Image\ImageManagerStatic')) {
            try {
                \Intervention\Image\ImageManagerStatic::configure(['driver' => function_exists('imagewebp') ? 'gd' : 'imagick']);
                $image = \Intervention\Image\ImageManagerStatic::make($file_path)->strip();
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

    private static function generate_thumbnail($image, $file_path, $size_name, $dest_width, $dest_height, $webp_path, $original_extension, $crop = false) {
        if (class_exists('Intervention\Image\ImageManagerStatic') && LiteImage_WebP_Support::is_webp_supported() && $image instanceof \Intervention\Image\Image) {
            if ($crop) {
                $resized = $image->fit($dest_width, $dest_height);
            } else {
                $resized = $image->resize($dest_width, $dest_height, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });
            }

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

            if ($crop) {
                // Calculate cropping parameters
                $ratio_orig = $orig_width / $orig_height;
                $ratio_dest = $dest_width / $dest_height;
                if ($ratio_orig > $ratio_dest) {
                    $src_w = $orig_height * $ratio_dest;
                    $src_h = $orig_height;
                    $src_x = ($orig_width - $src_w) / 2;
                    $src_y = 0;
                } else {
                    $src_w = $orig_width;
                    $src_h = $orig_width / $ratio_dest;
                    $src_x = 0;
                    $src_y = ($orig_height - $src_h) / 2;
                }
                imagecopyresampled($resized, $image, 0, 0, $src_x, $src_y, $dest_width, $dest_height, $src_w, $src_h);
            } else {
                imagecopyresampled($resized, $image, 0, 0, 0, 0, $dest_width, $dest_height, $orig_width, $orig_height);
            }

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