<?php
/**
 * Chunked processing service.
 *
 * @package Brace
 */

namespace Brace\Services;

/**
 * Runs a DestructiveOperation in chunks with time (about 5 seconds) and
 * memory ceilings per tick. Driven via REST polling from admin JS, or
 * synchronously via WP-CLI. No Action Scheduler dependency: Brace has
 * zero runtime dependencies.
 */
final class BatchRunner {

	/**
	 * Run one bounded tick of an operation and report progress.
	 *
	 * @param DestructiveOperation $operation The operation to advance.
	 * @param Batch                $batch     The budget for this tick.
	 * @return array{finished: bool, report: array<string, mixed>} Progress state for the caller to poll on.
	 */
	public function tick( DestructiveOperation $operation, Batch $batch ): array {
		$operation->execute( $batch );

		return [
			'finished' => $operation->finished(),
			'report'   => $operation->report(),
		];
	}

	/**
	 * Tick an operation until it reports finished. Safe under WP-CLI,
	 * where no request timeout applies; the per-tick budget still bounds
	 * memory growth between ticks.
	 *
	 * @param DestructiveOperation $operation The operation to run to completion.
	 * @param int                  $time_budget_seconds Time budget per tick.
	 * @param ?callable            $on_tick   Optional progress callback, receives the tick result.
	 * @return array<string, mixed> The final report.
	 */
	public function runToCompletion( DestructiveOperation $operation, int $time_budget_seconds = Batch::DEFAULT_TIME_BUDGET, ?callable $on_tick = null ): array {
		do {
			$progress = $this->tick( $operation, new Batch( $time_budget_seconds ) );

			if ( null !== $on_tick ) {
				$on_tick( $progress );
			}
		} while ( ! $progress['finished'] );

		return $progress['report'];
	}
}
