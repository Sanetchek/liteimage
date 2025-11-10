<?php

/**
 * Memory guard utilities for LiteImage.
 *
 * @package LiteImage
 * @since 3.4.0
 */

namespace LiteImage\Support;

use function apply_filters;
use function getimagesize;
use function ini_get;
use function ini_set;
use function memory_get_usage;

defined('ABSPATH') || exit;

/**
 * Class MemoryGuard
 *
 * Dynamically adjusts PHP memory limit to accommodate large image processing.
 */
class MemoryGuard
{
    private const DEFAULT_MAX_LIMIT = 536870912; // 512 MB.
    private const SAFETY_BUFFER = 16777216; // 16 MB.
    private const BYTES_PER_PIXEL_ESTIMATE = 5;

    /**
     * Ensure there is enough memory to read the supplied image.
     *
     * @param string $filePath Absolute path to the image.
     * @return MemoryGuardTicket
     */
    public static function ensureForImage($filePath)
    {
        $info = @getimagesize($filePath);
        if (!$info || empty($info[0]) || empty($info[1])) {
            return MemoryGuardTicket::unchanged();
        }

        $requiredBytes = self::estimateRequiredBytes((int) $info[0], (int) $info[1]);
        $currentUsage = memory_get_usage(true);
        $currentLimit = self::parseIniBytes(ini_get('memory_limit'));

        if ($currentLimit < 0) {
            return MemoryGuardTicket::unchanged();
        }

        $desiredBytes = $currentUsage + $requiredBytes + self::SAFETY_BUFFER;
        if ($currentLimit >= $desiredBytes) {
            return MemoryGuardTicket::unchanged();
        }

        $maxLimit = (int) apply_filters(
            'liteimage/memory_guard/max_limit',
            self::DEFAULT_MAX_LIMIT,
            $filePath,
            $info
        );

        if ($maxLimit > 0) {
            $desiredBytes = min($desiredBytes, $maxLimit);
        }

        if ($desiredBytes <= $currentLimit) {
            return MemoryGuardTicket::unchanged();
        }

        $desiredLimit = self::formatIniBytes($desiredBytes);
        $previous = ini_set('memory_limit', $desiredLimit);

        if ($previous === false) {
            Logger::log([
                'context' => 'MemoryGuard::ensureForImage',
                'event' => 'ini_set_failed',
                'desired_limit' => $desiredLimit,
                'file' => $filePath,
            ]);

            return MemoryGuardTicket::unchanged();
        }

        Logger::log([
            'context' => 'MemoryGuard::ensureForImage',
            'event' => 'limit_increased',
            'file' => $filePath,
            'width' => (int) $info[0],
            'height' => (int) $info[1],
            'previous_limit' => $previous,
            'new_limit' => $desiredLimit,
        ]);

        return MemoryGuardTicket::changed((string) $previous);
    }

    /**
     * Restore the previous memory limit.
     *
     * @param MemoryGuardTicket|null $ticket Ticket returned by ensureForImage.
     * @return void
     */
    public static function restore(MemoryGuardTicket $ticket = null)
    {
        if ($ticket === null || !$ticket->hasChanged()) {
            return;
        }

        $previousLimit = $ticket->getPreviousLimit();

        if ($previousLimit === null) {
            return;
        }

        $result = ini_set('memory_limit', $previousLimit);
        Logger::log([
            'context' => 'MemoryGuard::restore',
            'event' => ($result === false) ? 'restore_failed' : 'restored',
            'previous_limit' => $previousLimit,
        ]);
    }

    /**
     * Estimate required bytes for GD/Imagick to process the image.
     *
     * @param int $width Image width.
     * @param int $height Image height.
     * @return int Estimated bytes.
     */
    private static function estimateRequiredBytes($width, $height)
    {
        $pixels = max(1, $width) * max(1, $height);
        return (int) ($pixels * self::BYTES_PER_PIXEL_ESTIMATE);
    }

    /**
     * Convert an ini memory value to bytes.
     *
     * @param string|false $value Memory value from ini_get.
     * @return int
     */
    private static function parseIniBytes($value)
    {
        if ($value === false) {
            return -1;
        }

        $value = trim($value);
        if ($value === '') {
            return -1;
        }

        if ($value === '-1') {
            return -1;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        switch ($unit) {
            case 'g':
                return (int) ($number * 1024 * 1024 * 1024);
            case 'm':
                return (int) ($number * 1024 * 1024);
            case 'k':
                return (int) ($number * 1024);
            default:
                return (int) $number;
        }
    }

    /**
     * Convert bytes to an ini memory value string.
     *
     * @param int $bytes Desired bytes.
     * @return string
     */
    private static function formatIniBytes($bytes)
    {
        $megabytes = (int) ceil($bytes / (1024 * 1024));
        return $megabytes . 'M';
    }
}

/**
 * Ticket that represents a memory limit change.
 */
final class MemoryGuardTicket
{
    /**
     * @var string|null
     */
    private $previousLimit;

    /**
     * @var bool
     */
    private $changed;

    private function __construct($previousLimit, $changed)
    {
        $this->previousLimit = $previousLimit;
        $this->changed = $changed;
    }

    /**
     * Create ticket for unchanged limit.
     *
     * @return self
     */
    public static function unchanged()
    {
        return new self(null, false);
    }

    /**
     * Create ticket representing a change.
     *
     * @param string $previousLimit Previous memory limit value.
     * @return self
     */
    public static function changed($previousLimit)
    {
        return new self($previousLimit, true);
    }

    /**
     * Whether the limit was changed.
     *
     * @return bool
     */
    public function hasChanged()
    {
        return $this->changed;
    }

    /**
     * Get previous limit string.
     *
     * @return string|null
     */
    public function getPreviousLimit()
    {
        return $this->previousLimit;
    }
}


