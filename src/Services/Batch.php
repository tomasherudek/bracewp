<?php
/**
 * Batch budget value object.
 *
 * @package Brace
 */

namespace Brace\Services;

/**
 * The time and memory budget for one processing tick. BatchRunner hands
 * one of these to a DestructiveOperation's execute() so work stays
 * bounded on low-end hosting.
 */
final class Batch {

	/**
	 * Default time budget per tick, in seconds.
	 */
	public const DEFAULT_TIME_BUDGET = 5;

	/**
	 * Default memory headroom per tick, in bytes (32 MB).
	 */
	public const DEFAULT_MEMORY_BUDGET = 33554432;

	/**
	 * Time budget in seconds.
	 *
	 * @var int
	 */
	private int $timeBudgetSeconds;

	/**
	 * Memory budget in bytes, measured as growth since the tick started.
	 *
	 * @var int
	 */
	private int $memoryBudgetBytes;

	/**
	 * Timestamp when the tick started.
	 *
	 * @var float
	 */
	private float $startedAt;

	/**
	 * Memory usage when the tick started.
	 *
	 * @var int
	 */
	private int $startedWithMemory;

	/**
	 * Start a new tick budget.
	 *
	 * @param int $time_budget_seconds Time budget in seconds.
	 * @param int $memory_budget_bytes Memory growth budget in bytes.
	 */
	public function __construct( int $time_budget_seconds = self::DEFAULT_TIME_BUDGET, int $memory_budget_bytes = self::DEFAULT_MEMORY_BUDGET ) {
		$this->timeBudgetSeconds = $time_budget_seconds;
		$this->memoryBudgetBytes = $memory_budget_bytes;
		$this->startedAt         = microtime( true );
		$this->startedWithMemory = memory_get_usage();
	}

	/**
	 * Whether this tick should stop and yield.
	 *
	 * @return bool
	 */
	public function shouldStop(): bool {
		if ( microtime( true ) - $this->startedAt >= $this->timeBudgetSeconds ) {
			return true;
		}

		return memory_get_usage() - $this->startedWithMemory >= $this->memoryBudgetBytes;
	}
}
