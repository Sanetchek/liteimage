<?php

namespace {
	if (!function_exists('wp_delete_file')) {
		/**
		 * Test shim for wp_delete_file.
		 *
		 * @param string $path
		 * @return bool
		 */
		function wp_delete_file($path)
		{
			global $_liteimage_deleted_paths;
			if (!is_array($_liteimage_deleted_paths)) {
				$_liteimage_deleted_paths = [];
			}

			$_liteimage_deleted_paths[] = $path;
			return true;
		}
	}
}

namespace LiteImage\Tests\Support {

use LiteImage\Support\Filesystem;
use PHPUnit\Framework\TestCase;

class FilesystemTest extends TestCase
{
	protected function setUp(): void
	{
		global $_liteimage_deleted_paths;
		$_liteimage_deleted_paths = [];
	}

	public function testDeleteFileInvokesWordPressHelper(): void
	{
		global $_liteimage_deleted_paths;

		$path = '/tmp/liteimage-sample.webp';

		Filesystem::deleteFile($path);

		$this->assertSame([$path], $_liteimage_deleted_paths);
	}

	public function testDeleteFileIgnoresEmptyPath(): void
	{
		global $_liteimage_deleted_paths;

		Filesystem::deleteFile('');

		$this->assertSame([], $_liteimage_deleted_paths);
	}
}

}


