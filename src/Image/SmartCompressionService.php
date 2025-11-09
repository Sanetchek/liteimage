<?php

/**
 * Smart compression service for LiteImage plugin
 *
 * @package LiteImage
 * @since 3.3.0
 */

namespace LiteImage\Image;

use Intervention\Image\Interfaces\EncodedImageInterface;
use Intervention\Image\Interfaces\ImageInterface;
use LiteImage\Admin\Settings;
use LiteImage\Support\Filesystem;
use LiteImage\Support\Logger;

defined('ABSPATH') || exit;

/**
 * Class SmartCompressionService
 *
 * Provides adaptive, PHP-only compression heuristics for resized images.
 * Iteratively searches for the lowest quality setting that satisfies a
 * perceptual (PSNR) threshold when Imagick is available, and falls back to
 * filesize-based heuristics otherwise.
 */
class SmartCompressionService
{
    /**
     * Adaptive encode entrypoint.
     *
     * @param ImageInterface $image       Already resized image reference.
     * @param string         $format      Target format (jpeg|png|webp|gif|...).
     * @param string         $destination Destination file path.
     * @param array          $options     [
     *                                       'initial_quality' => int,
     *                                       'min_quality' => int,
     *                                       'target_psnr' => float,
     *                                       'max_iterations' => int,
     *                                       'min_savings_percent' => float
     *                                   ]
     *
     * @return array{
     *     quality:int,
     *     path:string,
     *     bytes:int,
     *     psnr:float|null,
     *     iterations:int,
     *     strategy:string
     * }
     */
    public function encode(ImageInterface $image, string $format, string $destination, array $options = [])
    {
        $format = strtolower($format);
        $initialQuality = (int) ($options['initial_quality'] ?? Settings::DEFAULT_THUMBNAIL_QUALITY);
        $minQuality = (int) ($options['min_quality'] ?? Settings::DEFAULT_SMART_MIN_QUALITY);
        $targetPsnr = (float) ($options['target_psnr'] ?? Settings::DEFAULT_SMART_TARGET_PSNR);
        $maxIterations = (int) ($options['max_iterations'] ?? Settings::DEFAULT_SMART_MAX_ITERATIONS);
        $minSavingsPercent = (float) ($options['min_savings_percent'] ?? Settings::DEFAULT_SMART_MIN_SAVINGS_PERCENT);

        if ($minQuality > $initialQuality) {
            $minQuality = max(Settings::DEFAULT_SMART_MIN_QUALITY, min($initialQuality, 100));
        }

        switch ($format) {
            case 'jpg':
            case 'jpeg':
                $result = $this->encodeLossy($image, 'jpeg', $destination, $initialQuality, $minQuality, $targetPsnr, $maxIterations, $minSavingsPercent, $options);
                break;
            case 'webp':
                $result = $this->encodeLossy($image, 'webp', $destination, $initialQuality, $minQuality, $targetPsnr, $maxIterations, $minSavingsPercent, $options);
                break;
            case 'png':
                $result = $this->encodePng($image, $destination, $options);
                break;
            case 'gif':
                $result = $this->encodeGif($image, $destination, $options);
                break;
            default:
                Logger::log("SmartCompressionService: unsupported format {$format}, fallback to JPEG");
                $result = $this->encodeLossy($image, 'jpeg', $destination, $initialQuality, $minQuality, $targetPsnr, $maxIterations, $minSavingsPercent, $options);
                break;
        }

        if ($result) {
            $this->recordTelemetry($format, $result, $options);
            $result['telemetry_recorded'] = true;
        }

        return $result;
    }

    /**
     * Lossy encoding with iterative quality selection.
     *
     * @param ImageInterface $image
     * @param string         $format
     * @param string         $destination
     * @param int            $initialQuality
     * @param int            $minQuality
     * @param float          $targetPsnr
     * @param int            $maxIterations
     * @param float          $minSavingsPercent
     *
     * @return array
     */
    private function encodeLossy(
        ImageInterface $image,
        string $format,
        string $destination,
        int $initialQuality,
        int $minQuality,
        float $targetPsnr,
        int $maxIterations,
        float $minSavingsPercent,
        array $options
    ) {
        $referencePath = $this->createReferenceImage($image);
        $supportsMetrics = $referencePath && $this->canComputePsnr();

        $baselineCandidate = $this->encodeToTemp($image, $format, $initialQuality);
        if (!$baselineCandidate) {
            throw new \RuntimeException('SmartCompressionService: unable to encode baseline candidate.');
        }

        $baselineBytes = $baselineCandidate['bytes'];
        $baselineData = [
            'quality' => $initialQuality,
            'path' => $baselineCandidate['path'],
            'bytes' => $baselineCandidate['bytes'],
            'psnr' => null,
            'iterations' => 1,
            'strategy' => $supportsMetrics ? 'psnr' : 'size',
            'baseline_bytes' => $baselineBytes,
        ];
        $iterations = 1;
        $bestCandidate = null;

        if ($supportsMetrics) {
            $qualityHigh = $initialQuality - 1;
            $qualityLow = $minQuality;

            $baselinePsnr = $this->computePsnr($referencePath, $baselineCandidate['path']);
            if ($baselinePsnr === null) {
                $supportsMetrics = false;
            } else {
                $baselineData['psnr'] = $baselinePsnr;
                $bestCandidate = $baselineData;
            }
        }

        if ($supportsMetrics) {
            while ($qualityLow <= $qualityHigh && $iterations < $maxIterations) {
                $iterations++;
                $candidateQuality = (int) floor(($qualityLow + $qualityHigh) / 2);
                if ($candidateQuality === ($bestCandidate['quality'] ?? $initialQuality)) {
                    break;
                }
                $candidate = $this->encodeToTemp($image, $format, $candidateQuality);

                if (!$candidate) {
                    Logger::log("SmartCompressionService: encoding failure at quality {$candidateQuality}");
                    break;
                }

                $psnr = $this->computePsnr($referencePath, $candidate['path']);
                if ($psnr === null) {
                    // Metrics failed mid-run, fall back to filesize heuristic.
                    $supportsMetrics = false;
                    $bestCandidate = $this->fallbackSizeStrategy(
                        $image,
                        $format,
                        $destination,
                        $initialQuality,
                        $minQuality,
                        $maxIterations,
                        $minSavingsPercent,
                        $baselineData
                    );
                    $iterations += $bestCandidate['iterations'];
                    $this->cleanupTemp([$candidate['path'], $referencePath]);
                    return $bestCandidate;
                }

                Logger::log("SmartCompressionService: quality {$candidateQuality}, PSNR {$psnr}");

                if ($psnr >= $targetPsnr) {
                    if ($bestCandidate) {
                        $this->cleanupTemp([$bestCandidate['path']]);
                    }
                    $bestCandidate = [
                        'quality' => $candidateQuality,
                        'path' => $candidate['path'],
                        'bytes' => $candidate['bytes'],
                        'psnr' => $psnr,
                        'iterations' => $iterations,
                        'strategy' => 'psnr',
                        'baseline_bytes' => $baselineBytes,
                    ];
                    $qualityHigh = $candidateQuality - 1;
                } else {
                    $qualityLow = $candidateQuality + 1;
                    $this->cleanupTemp([$candidate['path']]);
                }
            }

            if ($bestCandidate) {
                $this->commitCandidate($bestCandidate['path'], $destination);
                $this->cleanupTemp([$referencePath]);
                $bestCandidate['path'] = $destination;
                return $bestCandidate;
            }
        }

        // Fall back to filesize-based iterative reduction.
        $fallback = $this->fallbackSizeStrategy(
            $image,
            $format,
            $destination,
            $initialQuality,
            $minQuality,
            $maxIterations,
            $minSavingsPercent,
            $baselineData
        );

        $this->cleanupTemp([$referencePath]);
        return $fallback;
    }

    /**
     * Fallback heuristic using filesize deltas.
     *
     * @return array
     */
    private function fallbackSizeStrategy(
        ImageInterface $image,
        string $format,
        string $destination,
        int $initialQuality,
        int $minQuality,
        int $maxIterations,
        float $minSavingsPercent,
        ?array $baselineData = null
    ) {
        if ($baselineData === null) {
            $initialCandidate = $this->encodeToTemp($image, $format, $initialQuality);
            if (!$initialCandidate) {
                throw new \RuntimeException('SmartCompressionService fallback could not encode initial candidate.');
            }
            $baselineData = [
                'quality' => $initialQuality,
                'path' => $initialCandidate['path'],
                'bytes' => $initialCandidate['bytes'],
                'psnr' => null,
                'iterations' => 1,
                'baseline_bytes' => $initialCandidate['bytes'],
            ];
        }

        $bestQuality = $baselineData['quality'];
        $bestCandidate = [
            'path' => $baselineData['path'],
            'bytes' => $baselineData['bytes'],
            'quality' => $baselineData['quality'],
            'psnr' => $baselineData['psnr'],
            'iterations' => $baselineData['iterations'] ?? 1,
            'strategy' => 'size',
            'baseline_bytes' => $baselineData['baseline_bytes'],
        ];

        $iterations = $bestCandidate['iterations'];
        $currentQuality = $baselineData['quality'];
        $lastSize = $baselineData['bytes'];
        $bestSize = $lastSize;
        $baselineBytes = $baselineData['baseline_bytes'];

        while ($iterations < $maxIterations && ($currentQuality - 5) >= $minQuality) {
            $iterations++;
            $currentQuality -= 5;
            $candidate = $this->encodeToTemp($image, $format, $currentQuality);
            if (!$candidate) {
                Logger::log("SmartCompressionService fallback: failed at quality {$currentQuality}");
                break;
            }

            $reduction = $lastSize > 0 ? (($lastSize - $candidate['bytes']) / $lastSize) * 100.0 : 0.0;
            Logger::log("SmartCompressionService fallback: quality {$currentQuality}, size {$candidate['bytes']} bytes, reduction {$reduction}%");

            if ($reduction >= $minSavingsPercent) {
                $this->cleanupTemp([$bestCandidate['path']]);
                $bestCandidate = $candidate;
                $bestQuality = $currentQuality;
                $bestSize = $candidate['bytes'];
                $lastSize = $candidate['bytes'];
            } else {
                $this->cleanupTemp([$candidate['path']]);
                break;
            }
        }

        $this->commitCandidate($bestCandidate['path'], $destination);

        return [
            'quality' => $bestQuality,
            'path' => $destination,
            'bytes' => $bestSize,
            'psnr' => null,
            'iterations' => $iterations,
            'strategy' => 'size',
            'baseline_bytes' => $baselineBytes,
        ];
    }

    /**
     * Encode PNG with palette reduction when possible.
     */
    private function encodePng(ImageInterface $image, string $destination, array $options)
    {
        $encoded = $this->encodeDirect($image, 'png');
        if ($encoded) {
            $encoded->save($destination);
            $bytes = filesize($destination);
            return [
                'quality' => 100,
                'path' => $destination,
                'bytes' => $bytes,
                'psnr' => null,
                'iterations' => 1,
                'strategy' => 'lossless',
                'baseline_bytes' => $bytes,
            ];
        }

        throw new \RuntimeException('SmartCompressionService: unable to encode PNG.');
    }

    /**
     * Encode GIF (no adaptive tuning available).
     */
    private function encodeGif(ImageInterface $image, string $destination, array $options)
    {
        $encoded = $this->encodeDirect($image, 'gif');
        if ($encoded) {
            $encoded->save($destination);
            $bytes = filesize($destination);
            return [
                'quality' => 100,
                'path' => $destination,
                'bytes' => $bytes,
                'psnr' => null,
                'iterations' => 1,
                'strategy' => 'lossless',
                'baseline_bytes' => $bytes,
            ];
        }

        throw new \RuntimeException('SmartCompressionService: unable to encode GIF.');
    }

    /**
     * Save an image to a temporary file with a given quality.
     *
     * @param ImageInterface $image
     * @param string         $format
     * @param int            $quality
     *
     * @return array{path:string,bytes:int}|null
     */
    private function encodeToTemp(ImageInterface $image, string $format, int $quality)
    {
        $encoded = $this->encodeDirect($image, $format, $quality);
        if (!$encoded) {
            return null;
        }

        $tempPath = $this->createTempPath($format);
        try {
            $encoded->save($tempPath);
            return [
                'path' => $tempPath,
                'bytes' => (int) filesize($tempPath),
            ];
        } catch (\Exception $exception) {
            Logger::log('SmartCompressionService: failed to save temp file: ' . $exception->getMessage());
            $this->cleanupTemp([$tempPath]);
            return null;
        }
    }

    /**
     * Encode image directly using Intervention.
     */
    private function encodeDirect(ImageInterface $image, string $format, ?int $quality = null): ?EncodedImageInterface
    {
        try {
            $copy = clone $image;
        } catch (\Throwable $throwable) {
            Logger::log('SmartCompressionService: failed to clone image: ' . $throwable->getMessage());
            $copy = $image;
        }

        switch ($format) {
            case 'jpeg':
                return $copy->toJpeg($quality ?? Settings::DEFAULT_THUMBNAIL_QUALITY);
            case 'webp':
                $q = $quality ?? Settings::DEFAULT_THUMBNAIL_QUALITY;
                if ($q < 0) {
                    $q = 0;
                }
                if ($q > 100) {
                    $q = 100;
                }
                return $copy->toWebp($q);
            case 'png':
                return $copy->toPng(false, true, 8);
            case 'gif':
                return $copy->toGif();
            default:
                return null;
        }
    }

    /**
     * Create a lossless reference for PSNR comparison.
     */
    private function createReferenceImage(ImageInterface $image): ?string
    {
        if (!$this->canComputePsnr()) {
            return null;
        }

        $tempPath = $this->createTempPath('png');
        try {
            $image->copy()->toPng(false, true, 8)->save($tempPath);
            return $tempPath;
        } catch (\Exception $exception) {
            Logger::log('SmartCompressionService: failed to create reference image: ' . $exception->getMessage());
            $this->cleanupTemp([$tempPath]);
            return null;
        }
    }

    /**
     * Compute PSNR between two files when Imagick is available.
     */
    private function computePsnr(string $referencePath, string $candidatePath): ?float
    {
        if (!$this->canComputePsnr()) {
            return null;
        }

        try {
            $reference = new \Imagick($referencePath);
            $candidate = new \Imagick($candidatePath);
            $metrics = $reference->compareImages($candidate, \Imagick::METRIC_PSNR);
            $psnr = $metrics[1] ?? null;
            $reference->clear();
            $candidate->clear();
            $reference->destroy();
            $candidate->destroy();

            if ($psnr === null || $psnr <= 0) {
                return null;
            }

            if (!is_finite($psnr)) {
                return 99.99;
            }

            return (float) $psnr;
        } catch (\Exception $exception) {
            Logger::log('SmartCompressionService: PSNR computation failed: ' . $exception->getMessage());
            return null;
        }
    }

    /**
     * Check if PSNR metrics can be computed.
     */
    private function canComputePsnr(): bool
    {
        return class_exists('\Imagick') && method_exists('\Imagick', 'compareImages');
    }

    /**
     * Generate a temporary file path for intermediate encodes.
     */
    private function createTempPath(string $extension): string
    {
        $base = tempnam(sys_get_temp_dir(), 'liteimg_');
        if ($base === false) {
            throw new \RuntimeException('SmartCompressionService: unable to allocate temporary file.');
        }

        $path = $base . '.' . ltrim($extension, '.');
        return $path;
    }

    /**
     * Commit candidate file to destination.
     */
	private function commitCandidate(string $candidatePath, string $destination): void
	{
		$filesystem = Filesystem::instance();

		if (!$filesystem->move($candidatePath, $destination, true)) {
			if (!$filesystem->copy($candidatePath, $destination, true)) {
				throw new \RuntimeException('SmartCompressionService: unable to move optimized file to destination.');
			}

			Filesystem::deleteFile($candidatePath);
		}
	}

    /**
     * Cleanup temporary files.
     *
     * @param array<int,string> $paths
     */
    private function cleanupTemp(array $paths): void
    {
        foreach ($paths as $path) {
			if (!$path) {
                continue;
            }
			if (is_file($path)) {
				Filesystem::deleteFile($path);
			}
        }
    }

    /**
     * Create telemetry record from encoding result.
     *
     * @param string $format
     * @param array  $result
     * @param array  $options
     * @return void
     */
    private function recordTelemetry($format, array $result, array $options)
    {
        $context = isset($options['context']) && is_array($options['context']) ? $options['context'] : [];
        if (!empty($options['upscaled'])) {
            $context['upscaled'] = (bool) $options['upscaled'];
        }

        SmartCompressionTelemetry::record([
            'format' => $format,
            'strategy' => $result['strategy'] ?? 'direct',
            'quality' => $result['quality'] ?? null,
            'bytes' => $result['bytes'] ?? 0,
            'baseline_bytes' => $result['baseline_bytes'] ?? null,
            'psnr' => $result['psnr'] ?? null,
            'iterations' => $result['iterations'] ?? 0,
            'context' => $context,
        ]);

        Logger::log(sprintf(
            'Telemetry recorded: format=%s strategy=%s quality=%s bytes=%d baseline=%s iterations=%d psnr=%s',
            $format,
            $result['strategy'] ?? 'direct',
            $result['quality'] !== null ? (string) $result['quality'] : 'n/a',
            $result['bytes'] ?? 0,
            isset($result['baseline_bytes']) ? (string) $result['baseline_bytes'] : 'n/a',
            $result['iterations'] ?? 0,
            $result['psnr'] !== null ? number_format((float) $result['psnr'], 2) : 'n/a'
        ));
    }
}


