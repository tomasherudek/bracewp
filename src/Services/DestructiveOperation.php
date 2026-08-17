<?php
/**
 * The destructive operation contract.
 *
 * @package Brace
 */

namespace Brace\Services;

/**
 * Safety layer 4: any module action that writes or deletes data MUST go
 * through this contract. The default path is the dry run; the UI shows
 * the diff or report before anything executes. No destructive code path
 * may exist outside this interface (enforced by review plus a CI grep
 * for direct $wpdb writes outside Services).
 */
interface DestructiveOperation {

	/**
	 * Estimate the scope before anything runs: affected tables, row
	 * counts, sizes. Cheap; used to size guard and to inform the user.
	 *
	 * @return array<string, int>
	 */
	public function estimate(): array;

	/**
	 * Compute what WOULD change without changing anything. This is the
	 * default path; the UI shows this report first.
	 *
	 * @return array<string, mixed>
	 */
	public function dryRun(): array;

	/**
	 * Back up the affected data before execute() may run.
	 *
	 * @return string Backup identifier, usable for restore.
	 */
	public function backup(): string;

	/**
	 * Perform the change in time- and memory-bounded chunks.
	 *
	 * @param Batch $batch The budget for this tick.
	 * @return void
	 */
	public function execute( Batch $batch ): void;

	/**
	 * Report what actually changed, after execute() completed.
	 *
	 * @return array<string, mixed>
	 */
	public function report(): array;
}
