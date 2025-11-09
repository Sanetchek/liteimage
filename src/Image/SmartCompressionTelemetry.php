<?php

/**
 * Smart compression telemetry collector.
 *
 * @package LiteImage
 * @since 3.4.0
 */

namespace LiteImage\Image;

use LiteImage\Support\Logger;

defined('ABSPATH') || exit;

/**
 * Class SmartCompressionTelemetry
 *
 * Collects statistics about smart compression runs and exposes aggregate metrics
 * for admin reporting. Falls back to in-memory storage when WordPress option
 * APIs are not available (e.g., during unit tests).
 */
class SmartCompressionTelemetry
{
    /**
     * Option key used to persist telemetry payload.
     */
    private const OPTION_KEY = 'liteimage_compression_metrics';

    /**
     * In-memory fallback store for non-WordPress contexts (tests).
     *
     * @var array|null
     */
    private static $memoryStore = null;

    /**
     * Record a single compression event.
     *
     * @param array{
     *     format:string,
     *     strategy:string,
     *     quality:int|null,
     *     bytes:int,
     *     baseline_bytes:int|null,
     *     psnr:float|null,
     *     iterations:int,
     *     context?:array<string,mixed>
     * } $payload
     * @return void
     */
    public static function record(array $payload)
    {
        if (empty($payload['format'])) {
            Logger::log('SmartCompressionTelemetry: skipped record due to missing format.');
            return;
        }

        $format = strtolower($payload['format']);
        $strategy = strtolower($payload['strategy'] ?? 'direct');
        $quality = isset($payload['quality']) ? (int) $payload['quality'] : null;
        $bytes = isset($payload['bytes']) ? max(0, (int) $payload['bytes']) : 0;
        $baselineBytes = isset($payload['baseline_bytes']) ? max(0, (int) $payload['baseline_bytes']) : null;
        $psnr = isset($payload['psnr']) ? (float) $payload['psnr'] : null;
        $iterations = isset($payload['iterations']) ? max(0, (int) $payload['iterations']) : 0;
        $context = isset($payload['context']) && is_array($payload['context']) ? $payload['context'] : [];

        $metrics = self::readStore();

        // Update totals.
        $metrics['totals']['count']++;
        $metrics['totals']['bytes'] += $bytes;
        $metrics['totals']['iterations_sum'] += $iterations;
        if ($quality !== null) {
            $metrics['totals']['quality_sum'] += $quality;
            $metrics['totals']['quality_count']++;
        }
        if ($psnr !== null) {
            $metrics['totals']['psnr_sum'] += $psnr;
            $metrics['totals']['psnr_count']++;
        }
        if ($baselineBytes !== null && $baselineBytes > 0) {
            $metrics['totals']['baseline_sum'] += $baselineBytes;
            $metrics['totals']['baseline_count']++;
            $metrics['totals']['savings_sum'] += max(0, $baselineBytes - $bytes);
        }

        // Strategy counters.
        if (!isset($metrics['strategies'][$strategy])) {
            $metrics['strategies'][$strategy] = 0;
        }
        $metrics['strategies'][$strategy]++;

        // Format-specific aggregates.
        if (!isset($metrics['formats'][$format])) {
            $metrics['formats'][$format] = self::defaultFormatBucket();
        }
        $metrics['formats'][$format]['count']++;
        $metrics['formats'][$format]['bytes'] += $bytes;
        $metrics['formats'][$format]['iterations_sum'] += $iterations;
        if ($quality !== null) {
            $metrics['formats'][$format]['quality_sum'] += $quality;
            $metrics['formats'][$format]['quality_count']++;
        }
        if ($psnr !== null) {
            $metrics['formats'][$format]['psnr_sum'] += $psnr;
            $metrics['formats'][$format]['psnr_count']++;
        }
        if ($baselineBytes !== null && $baselineBytes > 0) {
            $metrics['formats'][$format]['baseline_sum'] += $baselineBytes;
            $metrics['formats'][$format]['baseline_count']++;
            $metrics['formats'][$format]['savings_sum'] += max(0, $baselineBytes - $bytes);
        }

        // Context-specific counters.
        if (!empty($context['density'])) {
            $density = (string) $context['density'];
            if (!isset($metrics['densities'][$density])) {
                $metrics['densities'][$density] = 0;
            }
            $metrics['densities'][$density]++;
        }

        if (!empty($context['upscaled'])) {
            $metrics['extras']['upscaled_count']++;
        }

        $metrics['extras']['last_event'] = [
            'format' => $format,
            'strategy' => $strategy,
            'quality' => $quality,
            'bytes' => $bytes,
            'baseline_bytes' => $baselineBytes,
            'psnr' => $psnr,
            'iterations' => $iterations,
            'size_name' => $context['size_name'] ?? null,
            'width' => isset($context['width']) ? (int) $context['width'] : null,
            'height' => isset($context['height']) ? (int) $context['height'] : null,
            'density' => $context['density'] ?? null,
            'timestamp' => time(),
        ];

        $metrics['extras']['last_updated'] = time();

        self::writeStore($metrics);
    }

    /**
     * Retrieve aggregate summary.
     *
     * @return array<string,mixed>
     */
    public static function get_summary()
    {
        $metrics = self::readStore();

        $totals = $metrics['totals'];
        $summary = [
            'total_operations' => $totals['count'],
            'average_quality' => $totals['quality_count'] ? $totals['quality_sum'] / $totals['quality_count'] : null,
            'average_iterations' => $totals['count'] ? $totals['iterations_sum'] / $totals['count'] : 0,
            'average_psnr' => $totals['psnr_count'] ? $totals['psnr_sum'] / $totals['psnr_count'] : null,
            'average_savings_percent' => self::computeSavingsPercent($totals['baseline_sum'], $totals['savings_sum']),
            'strategies' => $metrics['strategies'],
            'formats' => [],
            'densities' => $metrics['densities'],
            'last_event' => $metrics['extras']['last_event'],
            'last_updated' => $metrics['extras']['last_updated'],
            'upscaled_count' => $metrics['extras']['upscaled_count'],
        ];

        foreach ($metrics['formats'] as $format => $bucket) {
            $summary['formats'][$format] = [
                'count' => $bucket['count'],
                'average_quality' => $bucket['quality_count'] ? $bucket['quality_sum'] / $bucket['quality_count'] : null,
                'average_iterations' => $bucket['count'] ? $bucket['iterations_sum'] / $bucket['count'] : 0,
                'average_psnr' => $bucket['psnr_count'] ? $bucket['psnr_sum'] / $bucket['psnr_count'] : null,
                'average_savings_percent' => self::computeSavingsPercent($bucket['baseline_sum'], $bucket['savings_sum']),
            ];
        }

        return $summary;
    }

    /**
     * Reset metrics (mainly for tests or manual purge).
     *
     * @return void
     */
    public static function clear()
    {
        self::writeStore(self::emptyStore());
    }

    /**
     * Ensure the in-memory/option payload exists.
     *
     * @return array
     */
    private static function readStore(): array
    {
        if (function_exists('get_option')) {
            $value = get_option(self::OPTION_KEY);
            if (!is_array($value)) {
                $value = self::emptyStore();
            }
            return $value;
        }

        if (self::$memoryStore === null) {
            self::$memoryStore = self::emptyStore();
        }

        return self::$memoryStore;
    }

    /**
     * Persist metrics back to storage.
     *
     * @param array $metrics
     * @return void
     */
    private static function writeStore(array $metrics)
    {
        if (function_exists('update_option')) {
            update_option(self::OPTION_KEY, $metrics);
            return;
        }

        self::$memoryStore = $metrics;
    }

    /**
     * Structure of an empty metrics store.
     *
     * @return array
     */
    private static function emptyStore(): array
    {
        return [
            'totals' => [
                'count' => 0,
                'bytes' => 0,
                'iterations_sum' => 0,
                'quality_sum' => 0,
                'quality_count' => 0,
                'psnr_sum' => 0.0,
                'psnr_count' => 0,
                'baseline_sum' => 0,
                'baseline_count' => 0,
                'savings_sum' => 0,
            ],
            'formats' => [],
            'strategies' => [
                'psnr' => 0,
                'size' => 0,
                'direct' => 0,
                'lossless' => 0,
            ],
            'densities' => [],
            'extras' => [
                'last_event' => null,
                'last_updated' => null,
                'upscaled_count' => 0,
            ],
        ];
    }

    /**
     * Default bucket for a single format.
     *
     * @return array<string,int|float>
     */
    private static function defaultFormatBucket(): array
    {
        return [
            'count' => 0,
            'bytes' => 0,
            'iterations_sum' => 0,
            'quality_sum' => 0,
            'quality_count' => 0,
            'psnr_sum' => 0.0,
            'psnr_count' => 0,
            'baseline_sum' => 0,
            'baseline_count' => 0,
            'savings_sum' => 0,
        ];
    }

    /**
     * Compute savings percentage helper.
     *
     * @param int $baselineSum
     * @param int $savingsSum
     * @return float|null
     */
    private static function computeSavingsPercent($baselineSum, $savingsSum)
    {
        if ($baselineSum <= 0 || $savingsSum <= 0) {
            return null;
        }

        return ($savingsSum / $baselineSum) * 100.0;
    }
}


