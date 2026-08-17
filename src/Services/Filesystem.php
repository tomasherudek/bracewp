<?php
/**
 * Filesystem helpers.
 *
 * @package Brace
 */

namespace Brace\Services;

/**
 * Shared filesystem operations: protected upload directories for
 * backups and logs, writability probes, safe cleanup.
 *
 * Skeleton only. The real implementation ships together with the first
 * module spec that needs it.
 */
final class Filesystem {

	/**
	 * Ensure a directory exists under wp-content/uploads/brace/ and is
	 * protected from direct web access (.htaccess plus index.php).
	 *
	 * TODO: implement with the first module spec that needs it.
	 *
	 * @param string $relative_path Path relative to the brace uploads dir.
	 * @return string Absolute path to the protected directory.
	 * @throws \BadMethodCallException Until implemented.
	 */
	public function ensureProtectedDirectory( string $relative_path ): string {
		throw new \BadMethodCallException( 'Brace\Services\Filesystem::ensureProtectedDirectory() is not implemented yet. It ships with the first module spec that needs it.' );
	}

	/**
	 * Recursively delete a directory previously created by Brace.
	 *
	 * TODO: implement with the first module spec that needs it.
	 *
	 * @param string $absolute_path Absolute path inside the brace uploads dir.
	 * @return void
	 * @throws \BadMethodCallException Until implemented.
	 */
	public function removeDirectory( string $absolute_path ): void {
		throw new \BadMethodCallException( 'Brace\Services\Filesystem::removeDirectory() is not implemented yet. It ships with the first module spec that needs it.' );
	}
}
