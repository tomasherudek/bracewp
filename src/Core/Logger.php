<?php
/**
 * Minimal per-module logger.
 *
 * @package Brace
 */

namespace Brace\Core;

/**
 * Per-module ring buffer stored in a capped, non-autoloaded option.
 * Never makes external calls (trust contract); a debug tab surfaces
 * the entries later.
 */
final class Logger {

	/**
	 * Maximum entries kept per module.
	 */
	public const MAX_ENTRIES = 100;

	/**
	 * Option name for a module's log.
	 *
	 * @param string $module Module slug.
	 * @return string
	 */
	public function optionName( string $module ): string {
		return 'brace_log_' . $module;
	}

	/**
	 * Append a log entry, dropping the oldest entries past the cap.
	 *
	 * @param string $module  Module slug.
	 * @param string $message Log message.
	 * @param string $level   One of info, warning, error.
	 * @return void
	 */
	public function log( string $module, string $message, string $level = 'info' ): void {
		$entries = $this->entries( $module );

		$entries[] = [
			'time'    => time(),
			'level'   => $level,
			'message' => $message,
		];

		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, -self::MAX_ENTRIES );
		}

		update_option( $this->optionName( $module ), $entries, false );
	}

	/**
	 * All log entries of one module, oldest first.
	 *
	 * @param string $module Module slug.
	 * @return list<array{time: int, level: string, message: string}>
	 */
	public function entries( string $module ): array {
		$stored = get_option( $this->optionName( $module ), [] );

		return is_array( $stored ) ? array_values( $stored ) : [];
	}

	/**
	 * Remove a module's log option.
	 *
	 * @param string $module Module slug.
	 * @return void
	 */
	public function clear( string $module ): void {
		delete_option( $this->optionName( $module ) );
	}
}
