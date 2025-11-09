<?php

/**
 * Filesystem helper for LiteImage plugin.
 *
 * @package LiteImage
 * @since 3.4.0
 */

namespace LiteImage\Support;

defined('ABSPATH') || exit;

/**
 * Class Filesystem
 *
 * Provides convenient access to the WordPress filesystem API.
 */
class Filesystem
{
	/**
	 * Retrieve an initialized WP_Filesystem instance.
	 *
	 * @return \WP_Filesystem_Base
	 */
	public static function instance(): \WP_Filesystem_Base
	{
		global $wp_filesystem;

		if ($wp_filesystem instanceof \WP_Filesystem_Base) {
			return $wp_filesystem;
		}

		if (!function_exists('WP_Filesystem')) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if (!WP_Filesystem()) {
			throw new \RuntimeException('LiteImage Filesystem: unable to initialize WP_Filesystem.');
		}

		global $wp_filesystem; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

		if (!$wp_filesystem instanceof \WP_Filesystem_Base) {
			throw new \RuntimeException('LiteImage Filesystem: WP_Filesystem is not initialized.');
		}

		return $wp_filesystem;
	}

	/**
	 * Move a file using the WordPress filesystem.
	 *
	 * @param string $source
	 * @param string $destination
	 * @param bool   $overwrite
	 *
	 * @return bool
	 */
	public static function move(string $source, string $destination, bool $overwrite = true): bool
	{
		return self::instance()->move($source, $destination, $overwrite);
	}

	/**
	 * Copy a file using the WordPress filesystem.
	 *
	 * @param string   $source
	 * @param string   $destination
	 * @param bool     $overwrite
	 * @param int|bool $mode
	 *
	 * @return bool
	 */
	public static function copy(string $source, string $destination, bool $overwrite = true, $mode = false): bool
	{
		return self::instance()->copy($source, $destination, $overwrite, $mode);
	}

	/**
	 * Delete a file using WordPress helpers.
	 *
	 * @param string $path
	 * @return void
	 */
	public static function deleteFile(string $path): void
	{
		if ($path === '') {
			return;
		}

		if (function_exists('wp_delete_file')) {
			wp_delete_file($path);
			return;
		}

		if (is_file($path)) {
			self::instance()->delete($path);
		}
	}
}


