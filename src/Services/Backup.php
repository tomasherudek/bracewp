<?php
/**
 * Table-level backup service.
 *
 * @package Brace
 */

namespace Brace\Services;

/**
 * Table-level SQL dump to wp-content/uploads/brace/backups/ (hash-named
 * directory, protected by .htaccess and index.php). Used by every
 * DestructiveOperation before execute(). Keeps the last N dumps and
 * refuses when disk or table size is over threshold, telling the user
 * why. Explicitly NOT a full-site backup product: it is an operation
 * undo safety net.
 *
 * Skeleton only. The real implementation ships together with the first
 * destructive module spec.
 */
final class Backup {

	/**
	 * Dump one table to a protected file and return the backup identifier.
	 *
	 * TODO: implement with the first destructive module spec.
	 *
	 * @param string $table Full table name including prefix.
	 * @return string Backup identifier.
	 * @throws \BadMethodCallException Until implemented.
	 */
	public function dumpTable( string $table ): string {
		throw new \BadMethodCallException( 'Brace\Services\Backup::dumpTable() is not implemented yet. It ships with the first destructive module spec.' );
	}

	/**
	 * Remove old backups past the retention count.
	 *
	 * TODO: implement with the first destructive module spec.
	 *
	 * @param int $keep How many recent backups to keep.
	 * @return void
	 * @throws \BadMethodCallException Until implemented.
	 */
	public function prune( int $keep ): void {
		throw new \BadMethodCallException( 'Brace\Services\Backup::prune() is not implemented yet. It ships with the first destructive module spec.' );
	}
}
