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
 */
final class Filesystem {

	/**
	 * Ensure a directory exists under wp-content/uploads/brace/ and is
	 * protected from direct web access (.htaccess plus index.php).
	 *
	 * @param string $relative_path Path relative to the brace uploads dir.
	 * @return string Absolute path to the protected directory.
	 * @throws \RuntimeException When the directory cannot be created.
	 */
	public function ensureProtectedDirectory( string $relative_path ): string {
		$base = $this->braceUploadsDir();
		$path = $base . '/' . ltrim( $relative_path, '/' );

		if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: filesystem path. */
					esc_html__( 'Brace could not create the directory %s.', 'brace' ),
					esc_html( $path )
				)
			);
		}

		// Protect the brace root and the target: deny web access, block listing.
		foreach ( array_unique( [ $base, $path ] ) as $directory ) {
			$this->dropProtectionFiles( $directory );
		}

		return $path;
	}

	/**
	 * Recursively delete a directory previously created by Brace.
	 *
	 * Refuses paths outside wp-content/uploads/brace/, so a bug elsewhere
	 * cannot turn this into a generic recursive delete.
	 *
	 * @param string $absolute_path Absolute path inside the brace uploads dir.
	 * @return void
	 * @throws \InvalidArgumentException When the path is outside the brace uploads dir.
	 */
	public function removeDirectory( string $absolute_path ): void {
		$base = $this->braceUploadsDir();
		$real = realpath( $absolute_path );

		if ( false === $real || 0 !== strpos( $real . '/', $base . '/' ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Brace refuses to delete "%s": it is outside %s.', esc_html( $absolute_path ), esc_html( $base ) )
			);
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $real, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			if ( $item instanceof \SplFileInfo && $item->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				rmdir( $item->getPathname() );
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $real );
	}

	/**
	 * Absolute path of the brace directory under uploads.
	 *
	 * @return string
	 */
	public function braceUploadsDir(): string {
		$uploads = wp_upload_dir( null, false );

		return rtrim( (string) $uploads['basedir'], '/' ) . '/brace';
	}

	/**
	 * Write the .htaccess and index.php protection files into a directory.
	 *
	 * @param string $directory Absolute directory path.
	 * @return void
	 */
	private function dropProtectionFiles( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$htaccess = $directory . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
			file_put_contents( $htaccess, "Require all denied\n" );
		}

		$index = $directory . '/index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
			file_put_contents( $index, "<?php // Silence is golden.\n" );
		}
	}
}
