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
 *
 * Skeleton only. The real implementation ships together with the first
 * module spec that needs batching.
 */
final class BatchRunner {

	/**
	 * Run one bounded tick of an operation and report progress.
	 *
	 * TODO: implement with the first module spec that needs batching.
	 *
	 * @param DestructiveOperation $operation The operation to advance.
	 * @param Batch                $batch     The budget for this tick.
	 * @return array<string, mixed> Progress state for the caller to poll on.
	 * @throws \BadMethodCallException Until implemented.
	 */
	public function tick( DestructiveOperation $operation, Batch $batch ): array {
		throw new \BadMethodCallException( 'Brace\Services\BatchRunner::tick() is not implemented yet. It ships with the first module spec that needs batching.' );
	}
}
