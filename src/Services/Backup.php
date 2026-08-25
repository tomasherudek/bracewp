<?php
/**
 * Table-level backup service.
 *
 * @package Brace
 */

namespace Brace\Services;

/**
 * Table-level SQL dump to wp-content/uploads/brace/backups/ (protected by
 * .htaccess and index.php). Used by every DestructiveOperation before
 * execute(). Refuses tables over the size threshold, telling the user
 * why. Explicitly NOT a full-site backup product: it is an operation
 * undo safety net.
 *
 * A backup identifier is a directory name under backups/; restoring is
 * importing the .sql files it contains (wp db import or any SQL client).
 */
final class Backup {

	/**
	 * Refuse to dump a table whose data is larger than this (512 MB).
	 */
	public const MAX_TABLE_BYTES = 536870912;

	/**
	 * Rows fetched per chunk while dumping.
	 */
	private const CHUNK_ROWS = 500;

	/**
	 * Filesystem helper for the protected backup directory.
	 *
	 * @var Filesystem
	 */
	private Filesystem $filesystem;

	/**
	 * Set up the service.
	 *
	 * @param ?Filesystem $filesystem Filesystem helper, created when omitted.
	 */
	public function __construct( ?Filesystem $filesystem = null ) {
		$this->filesystem = $filesystem ?? new Filesystem();
	}

	/**
	 * Start a new backup set and return its identifier.
	 *
	 * @return string Backup identifier (directory name under backups/).
	 */
	public function create(): string {
		$id = gmdate( 'Ymd-His' ) . '-' . substr( md5( uniqid( 'brace', true ) ), 0, 8 );

		$this->filesystem->ensureProtectedDirectory( 'backups/' . $id );

		return $id;
	}

	/**
	 * Dump one table into a backup set and return the identifier.
	 *
	 * @param string  $table Full table name including prefix.
	 * @param ?string $into  Backup identifier from create(); a new set is created when omitted.
	 * @return string Backup identifier, usable for restore.
	 * @throws \RuntimeException When the table is missing, oversized, or the dump cannot be written.
	 */
	public function dumpTable( string $table, ?string $into = null ): string {
		global $wpdb;

		$id  = $into ?? $this->create();
		$dir = $this->filesystem->ensureProtectedDirectory( 'backups/' . $id );

		$this->assertDumpable( $table );

		$file = $dir . '/' . $table . '.sql';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $file, 'wb' );

		if ( false === $handle ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: file path. */
					esc_html__( 'Brace could not write the backup file %s.', 'brace' ),
					esc_html( $file )
				)
			);
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names cannot be placeholders; they are validated against information_schema above.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $handle, "-- Brace backup of {$table}\nDROP TABLE IF EXISTS `{$table}`;\n" . ( $create[1] ?? '' ) . ";\n" );

		$offset = 0;
		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", self::CHUNK_ROWS, $offset ),
				ARRAY_A
			);

			if ( [] === $rows || null === $rows ) {
				break;
			}

			foreach ( $rows as $row ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				fwrite( $handle, $this->insertStatement( $table, $row ) );
			}

			$offset += self::CHUNK_ROWS;
		}
		// phpcs:enable

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		return $id;
	}

	/**
	 * Remove old backup sets past the retention count, oldest first.
	 *
	 * @param int $keep How many recent backup sets to keep. Zero removes all.
	 * @return void
	 */
	public function prune( int $keep ): void {
		$sets = $this->sets();

		if ( count( $sets ) <= $keep ) {
			return;
		}

		sort( $sets );

		foreach ( array_slice( $sets, 0, count( $sets ) - $keep ) as $set ) {
			$this->filesystem->removeDirectory( $this->backupsDir() . '/' . $set );
		}
	}

	/**
	 * Identifiers of every stored backup set.
	 *
	 * @return list<string>
	 */
	public function sets(): array {
		$dir = $this->backupsDir();

		if ( ! is_dir( $dir ) ) {
			return [];
		}

		$sets = [];
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( is_string( $entry ) && '.' !== $entry[0] && is_dir( $dir . '/' . $entry ) ) {
				$sets[] = $entry;
			}
		}

		return $sets;
	}

	/**
	 * Absolute path of the backups directory.
	 *
	 * @return string
	 */
	public function backupsDir(): string {
		return $this->filesystem->braceUploadsDir() . '/backups';
	}

	/**
	 * Refuse missing or oversized tables with a human sentence.
	 *
	 * @param string $table Full table name including prefix.
	 * @return void
	 * @throws \RuntimeException When the table is missing or oversized.
	 */
	private function assertDumpable( string $table ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$status = $wpdb->get_row(
			$wpdb->prepare( 'SELECT DATA_LENGTH AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ),
			ARRAY_A
		);

		if ( null === $status ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: database table name. */
					esc_html__( 'Brace cannot back up the table %s: it does not exist.', 'brace' ),
					esc_html( $table )
				)
			);
		}

		if ( (int) $status['bytes'] > self::MAX_TABLE_BYTES ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: 1: database table name, 2: table size in megabytes. */
					esc_html__( 'Brace refuses to back up the table %1$s: %2$s MB is over the 512 MB safety threshold. Re-run with --no-backup if your undo path is re-cloning production.', 'brace' ),
					esc_html( $table ),
					esc_html( (string) round( ( (int) $status['bytes'] ) / ( 1024 * 1024 ) ) )
				)
			);
		}
	}

	/**
	 * One INSERT statement for a dumped row.
	 *
	 * @param string               $table Full table name.
	 * @param array<string, mixed> $row   Column name to value.
	 * @return string
	 */
	private function insertStatement( string $table, array $row ): string {
		global $wpdb;

		$columns = [];
		$values  = [];

		foreach ( $row as $column => $value ) {
			$columns[] = '`' . str_replace( '`', '', (string) $column ) . '`';

			if ( null === $value ) {
				$values[] = 'NULL';
			} else {
				$values[] = $wpdb->prepare( '%s', $value );
			}
		}

		return sprintf( "INSERT INTO `%s` (%s) VALUES (%s);\n", $table, implode( ',', $columns ), implode( ',', $values ) );
	}
}
