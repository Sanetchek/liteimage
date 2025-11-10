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
use LiteImage\Support\MemoryGuard;
use LiteImage\Support\WebPSupport;
use LiteImage\Admin\Settings;
use LiteImage\Admin\AdminPage;

defined('ABSPATH') || exit;

/**
 * Class ThumbnailGenerator
 *
 * Generates thumbnails and WebP versions using Intervention Image 3.x
 */
class ThumbnailGenerator
{
    /**
     * Smart compression service instance.
     *
     * @var SmartCompressionService|null
     */
    private static $smartCompressionService = null;

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
        $attachment_src = wp_get_attachment_image_src($attachment_id, 'full');

        if (!file_exists($file_path) || !$attachment_src || empty($attachment_src[0])) {
            Logger::log("Skipping thumbnail generation for attachment {$attachment_id}: missing original file or metadata.");
            return '';
        }

        $file_type = wp_check_filetype($file_path);
        if (!in_array($file_type['type'], Config::ALLOWED_MIME_TYPES, true)) {
            Logger::log("Invalid MIME type for $file_path: " . $file_type['type']);
            return '';
        }

        $settings = Settings::get_instance();
        $use_webp = $settings->get('convert_to_webp') && AdminPage::is_webp_supported_anywhere();
        $quality = (int) $settings->get('thumbnail_quality');
        if ($quality < 60) {
            $quality = 60;
        }
        if ($quality > 100) {
            $quality = 100;
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

        $updated_size_name = '';
        $smartOptions = self::resolveSmartCompressionOptions($settings, $quality);
        $smartSummary = json_encode(self::summarizeSmartOptions($smartOptions));
        Logger::log('Smart compression settings: ' . ($smartSummary !== false ? $smartSummary : '[]'));

        foreach ($sizes as $size_key => $dimensions) {
            list($width, $height) = $dimensions;
            list($dest_width, $dest_height) = liteimage_downsize($attachment_id, [$width, $height]);
            $size_name = Config::THUMBNAIL_PREFIX . "{$dest_width}x{$dest_height}";
            $retina_size_name = $size_name . '@2x';
            $retina_width = max(1, (int) round($dest_width * 2));
            $retina_height = max(1, (int) round($dest_height * 2));

            // Register sizes so WP functions can retrieve the metadata later.
            add_image_size(
                $size_name,
                $dest_width,
                $dest_height,
                ($dest_width && $dest_height)
            );
            add_image_size(
                $retina_size_name,
                $retina_width,
                $retina_height,
                ($dest_width && $dest_height)
            );

            $updated_size_name = $size_name;
            $webp_path = str_replace(
                basename($file_path),
                basename($file_path, '.' . $original_extension) . "-$size_name.webp",
                $file_path
            );
            $orig_ext_path = str_replace(
                basename($file_path),
                basename($file_path, '.' . $original_extension) . "-$size_name." . $original_extension,
                $file_path
            );
            $retina_webp_path = str_replace(
                basename($file_path),
                basename($file_path, '.' . $original_extension) . "-$retina_size_name.webp",
                $file_path
            );
            $retina_orig_path = str_replace(
                basename($file_path),
                basename($file_path, '.' . $original_extension) . "-$retina_size_name." . $original_extension,
                $file_path
            );

            $crop = ($width && $height);
            $upscale_notice = ($retina_width > $orig_width || $retina_height > $orig_height);

            // We generate only if WebP is needed:
            if ($use_webp) {
                if (!isset($metadata['sizes'][$size_name]) || !file_exists($webp_path)) {
                    // Start generation with quality from settings
                    $image = self::load_image($file_path, $original_extension);
                    if (!$image) {
                        Logger::log("Skipping WebP thumbnail generation for {$attachment_id}: unable to decode {$file_path}.");
                        continue;
                    }
                    $optionsForVariant = $smartOptions;
                    $optionsForVariant['context'] = [
                        'size_name' => $size_name,
                        'density' => '1x',
                        'width' => $dest_width,
                        'height' => $dest_height,
                        'source' => 'thumbnail',
                    ];
                    $optionsForVariant['upscaled'] = false;
                    self::generate_thumbnail(
                        $image,
                        $file_path,
                        $size_name,
                        $dest_width,
                        $dest_height,
                        $webp_path,
                        $original_extension,
                        $crop,
                        $quality,
                        $optionsForVariant
                    );
                    self::destroy_image($image);

                    $metadata['sizes'][$size_name] = [
                        'file' => basename($webp_path),
                        'webp' => basename($webp_path),
                        'width' => $dest_width,
                        'height' => $dest_height,
                        'extension' => 'webp',
                        'density' => '1x',
                        'is_retina' => false,
                    ];
                }

                if (
                    !isset($metadata['sizes'][$retina_size_name]) ||
                    !file_exists($retina_webp_path)
                ) {
                    $retina_image = self::load_image($file_path, $original_extension);
                    if ($retina_image) {
                        $retinaOptions = $smartOptions;
                        $retinaOptions['context'] = [
                            'size_name' => $retina_size_name,
                            'density' => '2x',
                            'width' => $retina_width,
                            'height' => $retina_height,
                            'source' => 'thumbnail',
                        ];
                        $retinaOptions['upscaled'] = $upscale_notice;
                        self::generate_thumbnail(
                            $retina_image,
                            $file_path,
                            $retina_size_name,
                            $retina_width,
                            $retina_height,
                            $retina_webp_path,
                            $original_extension,
                            $crop,
                            $quality,
                            $retinaOptions
                        );
                        self::destroy_image($retina_image);

                        $metadata['sizes'][$retina_size_name] = [
                            'file' => basename($retina_webp_path),
                            'webp' => basename($retina_webp_path),
                            'width' => $retina_width,
                            'height' => $retina_height,
                            'extension' => 'webp',
                            'density' => '2x',
                            'is_retina' => true,
                            'base_size' => $size_name,
                            'upscaled' => $upscale_notice,
                        ];

                        if ($upscale_notice) {
                            Logger::log("Retina upscaling (WebP) for {$file_path} to {$retina_width}x{$retina_height}");
                        }
                    } else {
                        Logger::log("Skipping WebP retina thumbnail for {$attachment_id}: unable to decode {$file_path}.");
                    }
                }
            } else {
                // Generate thumbnails in original format (JPEG/PNG/GIF)
                if (!isset($metadata['sizes'][$size_name]) || !file_exists($orig_ext_path)) {
                    $image = self::load_image($file_path, $original_extension);
                    if (!$image) {
                        Logger::log("Skipping original thumbnail generation for {$attachment_id}: unable to decode {$file_path}.");
                        continue;
                    }
                    $optionsForVariant = $smartOptions;
                    $optionsForVariant['context'] = [
                        'size_name' => $size_name,
                        'density' => '1x',
                        'width' => $dest_width,
                        'height' => $dest_height,
                        'source' => 'thumbnail',
                    ];
                    $optionsForVariant['upscaled'] = false;
                    self::generate_original_thumbnail(
                        $image,
                        $file_path,
                        $size_name,
                        $dest_width,
                        $dest_height,
                        $orig_ext_path,
                        $original_extension,
                        $crop,
                        $quality,
                        $optionsForVariant
                    );
                    self::destroy_image($image);

                    $metadata['sizes'][$size_name] = [
                        'file' => basename($orig_ext_path),
                        'width' => $dest_width,
                        'height' => $dest_height,
                        'extension' => $original_extension,
                        'density' => '1x',
                        'is_retina' => false,
                    ];
                }

                if (
                    !isset($metadata['sizes'][$retina_size_name]) ||
                    !file_exists($retina_orig_path)
                ) {
                    $retina_image = self::load_image($file_path, $original_extension);
                    if ($retina_image) {
                        $retinaOptions = $smartOptions;
                        $retinaOptions['context'] = [
                            'size_name' => $retina_size_name,
                            'density' => '2x',
                            'width' => $retina_width,
                            'height' => $retina_height,
                            'source' => 'thumbnail',
                        ];
                        $retinaOptions['upscaled'] = $upscale_notice;
                        self::generate_original_thumbnail(
                            $retina_image,
                            $file_path,
                            $retina_size_name,
                            $retina_width,
                            $retina_height,
                            $retina_orig_path,
                            $original_extension,
                            $crop,
                            $quality,
                            $retinaOptions
                        );
                        self::destroy_image($retina_image);

                        $metadata['sizes'][$retina_size_name] = [
                            'file' => basename($retina_orig_path),
                            'width' => $retina_width,
                            'height' => $retina_height,
                            'extension' => $original_extension,
                            'density' => '2x',
                            'is_retina' => true,
                            'base_size' => $size_name,
                            'upscaled' => $upscale_notice,
                        ];

                        if ($upscale_notice) {
                            Logger::log("Retina upscaling for {$file_path} to {$retina_width}x{$retina_height}");
                        }
                    } else {
                        Logger::log("Skipping original retina thumbnail for {$attachment_id}: unable to decode {$file_path}.");
                    }
                }
            }
        }

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
        $memoryTicket = MemoryGuard::ensureForImage($file_path);

        try {
            // Choose driver based on available PHP extensions
            if (extension_loaded('gd')) {
                $driver = new GdDriver();
            } elseif (extension_loaded('imagick') && class_exists('Imagick')) {
                $driver = new ImagickDriver();
            } else {
                Logger::log('No GD or Imagick available for Intervention Image');
                return null;
            }

            $manager = new ImageManager($driver);
            $image = $manager->read($file_path);
            Logger::log("Image loaded via Intervention 3.x: $file_path");
            return $image;
        } catch (\Exception $e) {
            Logger::log("Intervention Image 3.x failed: " . $e->getMessage());
            return null;
        } finally {
            MemoryGuard::restore($memoryTicket);
        }
    }

    /**
     * Generate a single WebP thumbnail
     *
     * @param ImageInterface $image Image object
     * @param string $file_path Original file path
     * @param string $size_name Size name
     * @param int $dest_width Destination width
     * @param int $dest_height Destination height
     * @param string $webp_path WebP file path
     * @param string $original_extension Original file extension
     * @param bool $crop Whether to crop
     * @param int $quality Image quality (60-100)
     * @return void
     */
    private static function generate_thumbnail($image, $file_path, $size_name, $dest_width, $dest_height, $webp_path, $original_extension, $crop = false, $quality = 85, array $smartOptions = [])
    {
        // Check if using Intervention Image 3.x
        if (!$image instanceof ImageInterface) {
            Logger::log("Invalid image object for thumbnail generation");
            return;
        }

        if (WebPSupport::is_webp_supported()) {
            try {
                $resized = self::resizeImage($image, $dest_width, $dest_height, $crop);
                if (!$resized) {
                    Logger::log("Failed to resize image for {$size_name}");
                    return;
                }

                // Save as WebP with quality, ensure original WebP uploads stay lossless
                $webp_quality = self::resolve_webp_quality($original_extension, $quality);
                $smartOptions['initial_quality'] = $webp_quality;

                $result = self::optimizeAndSave($resized, 'webp', $webp_path, $smartOptions);
                if (!$result) {
                    Logger::log("Fallback encode failed for {$size_name}");
                    return;
                }

                Logger::log(sprintf("Generated thumbnail via Intervention 3.x: %s, webp=%s, quality=%d, strategy=%s", $size_name, $webp_path, $result['quality'], $result['strategy']));
                return;
            } catch (\Exception $e) {
                Logger::log("Intervention thumbnail generation failed: " . $e->getMessage());
                return;
            }
        }
    }

    /**
     * Generate a single thumbnail in original format
     *
     * @param ImageInterface $image Image object
     * @param string $file_path Original file path
     * @param string $size_name Size name
     * @param int $dest_width Destination width
     * @param int $dest_height Destination height
     * @param string $dest_path Destination file path (original extension)
     * @param string $original_extension Original file extension
     * @param bool $crop Whether to crop
     * @param int $quality Image quality (60-100)
     * @return void
     */
    private static function generate_original_thumbnail($image, $file_path, $size_name, $dest_width, $dest_height, $dest_path, $original_extension, $crop = false, $quality = 85, array $smartOptions = [])
    {
        if (!$image instanceof ImageInterface) {
            Logger::log("Invalid image object for original thumbnail generation");
            return;
        }

        try {
            $resized = self::resizeImage($image, $dest_width, $dest_height, $crop);
            if (!$resized) {
                Logger::log("Failed to resize image for {$size_name}");
                return;
            }

            $ext = strtolower($original_extension);
            switch ($ext) {
                case 'jpg':
                case 'jpeg':
                    $smartOptions['initial_quality'] = $quality;
                    $result = self::optimizeAndSave($resized, 'jpeg', $dest_path, $smartOptions);
                    if (!$result) {
                        Logger::log("JPEG optimize fallback failed for {$size_name}");
                        break;
                    }
                    Logger::log(sprintf("Generated JPEG thumbnail: %s => %s, quality=%d, strategy=%s", $size_name, $dest_path, $result['quality'], $result['strategy']));
                    break;
                case 'png':
                    $result = self::optimizeAndSave($resized, 'png', $dest_path, $smartOptions);
                    if ($result) {
                        Logger::log(sprintf("Generated PNG thumbnail: %s => %s, strategy=%s", $size_name, $dest_path, $result['strategy']));
                    } else {
                        Logger::log("PNG optimize fallback failed for {$size_name}");
                    }
                    break;
                case 'gif':
                    $result = self::optimizeAndSave($resized, 'gif', $dest_path, $smartOptions);
                    if ($result) {
                        Logger::log(sprintf("Generated GIF thumbnail: %s => %s, strategy=%s", $size_name, $dest_path, $result['strategy']));
                    } else {
                        Logger::log("GIF optimize fallback failed for {$size_name}");
                    }
                    break;
                case 'webp':
                    // Fallback safety: if original is webp but webp disabled, still save webp
                    $webp_quality = self::resolve_webp_quality($original_extension, $quality);
                    $smartOptions['initial_quality'] = $webp_quality;
                    $result = self::optimizeAndSave($resized, 'webp', $dest_path, $smartOptions);
                    if (!$result) {
                        Logger::log("WebP optimize fallback failed for {$size_name}");
                        break;
                    }
                    Logger::log(sprintf("Generated WebP thumbnail: %s => %s, quality=%d, strategy=%s", $size_name, $dest_path, $result['quality'], $result['strategy']));
                    break;
                default:
                    // Default to JPEG if unknown
                    $fallback = preg_replace('/\.[^.]+$/', '.jpg', $dest_path);
                    $smartOptions['initial_quality'] = $quality;
                    $result = self::optimizeAndSave($resized, 'jpeg', $fallback, $smartOptions);
                    if (!$result) {
                        Logger::log("Default JPEG optimize fallback failed for {$size_name}");
                        break;
                    }
                    $dest_path = $fallback;
                    Logger::log(sprintf("Generated fallback JPEG thumbnail: %s => %s, quality=%d, strategy=%s", $size_name, $dest_path, $result['quality'], $result['strategy']));
                    break;
            }

            Logger::log("Generated original-format thumbnail: $size_name => $dest_path");
        } catch (\Exception $e) {
            Logger::log("Original thumbnail generation failed: " . $e->getMessage());
        }
    }

    /**
     * Resolve effective WebP quality ensuring original WebP uploads remain lossless.
     *
     * @param string $original_extension
     * @param int $requested_quality
     * @return int
     */
    private static function resolve_webp_quality($original_extension, $requested_quality)
    {
        if (strtolower($original_extension) === 'webp') {
            return 100;
        }

        return $requested_quality;
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

    /**
     * Resolve smart compression service singleton.
     *
     * @return SmartCompressionService
     */
    private static function smartCompression()
    {
        if (!self::$smartCompressionService) {
            self::$smartCompressionService = new SmartCompressionService();
        }

        return self::$smartCompressionService;
    }

    /**
     * Resize helper producing a fresh copy.
     *
     * @param ImageInterface $image
     * @param int            $width
     * @param int            $height
     * @param bool           $crop
     *
     * @return ImageInterface|null
     */
    private static function resizeImage(ImageInterface $image, $width, $height, $crop)
    {
        try {
            if ($crop) {
                return $image->cover($width, $height);
            }

            return $image->scale($width, $height);
        } catch (\Exception $exception) {
            Logger::log('Thumbnail resize failed: ' . $exception->getMessage());
            return null;
        }
    }

    /**
     * Resolve smart compression options from settings.
     *
     * @param Settings $settings
     * @param int      $quality
     *
     * @return array
     */
    private static function resolveSmartCompressionOptions(Settings $settings, $quality)
    {
        if (!$settings->get('smart_compression_enabled')) {
            return [
                'enabled' => false,
                'initial_quality' => $quality,
            ];
        }

        return [
            'enabled' => true,
            'initial_quality' => $quality,
            'min_quality' => (int) ($settings->get('smart_min_quality') ?? Settings::DEFAULT_SMART_MIN_QUALITY),
            'target_psnr' => (float) ($settings->get('smart_target_psnr') ?? Settings::DEFAULT_SMART_TARGET_PSNR),
            'max_iterations' => (int) ($settings->get('smart_max_iterations') ?? Settings::DEFAULT_SMART_MAX_ITERATIONS),
            'min_savings_percent' => (float) ($settings->get('smart_min_savings_percent') ?? Settings::DEFAULT_SMART_MIN_SAVINGS_PERCENT),
        ];
    }

    /**
     * Run smart compression if enabled, otherwise perform direct encode.
     *
     * @param ImageInterface $image
     * @param string         $format
     * @param string         $destination
     * @param array          $options
     *
     * @return array{quality:int,path:string,bytes:int,psnr:float|null,iterations:int,strategy:string}|null
     */
    private static function optimizeAndSave(ImageInterface $image, $format, $destination, array $options)
    {
        $format = strtolower($format);
        $initialQuality = (int) ($options['initial_quality'] ?? Settings::DEFAULT_THUMBNAIL_QUALITY);
        $quality = $initialQuality;

        if (!empty($options['enabled'])) {
            try {
                $service = self::smartCompression();
                $result = $service->encode(
                    $image,
                    $format,
                    $destination,
                    [
                        'initial_quality' => $initialQuality,
                        'min_quality' => (int) ($options['min_quality'] ?? Settings::DEFAULT_SMART_MIN_QUALITY),
                        'target_psnr' => (float) ($options['target_psnr'] ?? Settings::DEFAULT_SMART_TARGET_PSNR),
                        'max_iterations' => (int) ($options['max_iterations'] ?? Settings::DEFAULT_SMART_MAX_ITERATIONS),
                        'min_savings_percent' => (float) ($options['min_savings_percent'] ?? Settings::DEFAULT_SMART_MIN_SAVINGS_PERCENT),
                        'context' => $options['context'] ?? [],
                        'upscaled' => $options['upscaled'] ?? false,
                    ]
                );
                Logger::log(sprintf('Smart compression applied (%s): q=%d, bytes=%d, strategy=%s', $format, $result['quality'], $result['bytes'], $result['strategy']));
                return $result;
            } catch (\Throwable $exception) {
                Logger::log('Smart compression failed, falling back: ' . $exception->getMessage());
            }
        }

        // Direct encode fallback.
        try {
            switch ($format) {
                case 'webp':
                    $image->toWebp($quality)->save($destination);
                    break;
                case 'jpeg':
                case 'jpg':
                    $image->toJpeg($quality)->save($destination);
                    break;
                case 'png':
                    $image->toPng(false, true, 8)->save($destination);
                    break;
                case 'gif':
                    $image->toGif()->save($destination);
                    break;
                default:
                    $image->toJpeg($quality)->save($destination);
                    break;
            }

            $bytes = (int) filesize($destination);
            $result = [
                'quality' => $quality,
                'path' => $destination,
                'bytes' => $bytes,
                'psnr' => null,
                'iterations' => 1,
                'strategy' => 'direct',
                'baseline_bytes' => $bytes,
            ];

            $context = isset($options['context']) && is_array($options['context']) ? $options['context'] : [];
            if (!empty($options['upscaled'])) {
                $context['upscaled'] = (bool) $options['upscaled'];
            }

            SmartCompressionTelemetry::record([
                'format' => $format,
                'strategy' => 'direct',
                'quality' => $quality,
                'bytes' => $bytes,
                'baseline_bytes' => $bytes,
                'psnr' => null,
                'iterations' => 1,
                'context' => $context,
            ]);
            Logger::log(sprintf(
                'Telemetry recorded: format=%s strategy=%s quality=%d bytes=%d baseline=%d iterations=1',
                $format,
                'direct',
                $quality,
                $bytes,
                $bytes
            ));
            $result['telemetry_recorded'] = true;

            return $result;
        } catch (\Exception $exception) {
            Logger::log('Direct encode failed: ' . $exception->getMessage());
            return null;
        }
    }

    /**
     * Determine format to encode for original thumbnail path.
     *
     * @param string $original_extension
     *
     * @return string
     */
    private static function resolveFormatForExtension($original_extension)
    {
        $ext = strtolower($original_extension);
        if (in_array($ext, ['jpg', 'jpeg'], true)) {
            return 'jpeg';
        }

        if (in_array($ext, ['png', 'gif', 'webp'], true)) {
            return $ext;
        }

        return 'jpeg';
    }

    /**
     * Resolve smart compression settings array for logging.
     *
     * @param array $options
     *
     * @return array
     */
    private static function summarizeSmartOptions(array $options)
    {
        return [
            'enabled' => !empty($options['enabled']),
            'initial_quality' => (int) ($options['initial_quality'] ?? 0),
            'min_quality' => (int) ($options['min_quality'] ?? 0),
            'target_psnr' => (float) ($options['target_psnr'] ?? 0),
            'max_iterations' => (int) ($options['max_iterations'] ?? 0),
            'min_savings_percent' => (float) ($options['min_savings_percent'] ?? 0),
        ];
    }

}

// Backward compatibility alias
class_alias('LiteImage\Image\ThumbnailGenerator', 'LiteImage_Thumbnail_Generator');
