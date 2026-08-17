<?php
/**
 * Serialized-safe and JSON-safe search and replace service.
 *
 * @package Brace
 */

namespace Brace\Services;

/**
 * THE hard-correctness component. Will handle: plain strings, serialized
 * arrays (with length recount), JSON values, nested serialization inside
 * JSON and JSON inside serialization, utf8mb4 and emoji safe.
 *
 * Skeleton only. The real implementation ships together with the first
 * module spec that needs it, along with the nasty-dataset fixtures under
 * tests/fixtures that prove correctness (see docs/modules/README.md).
 */
final class Replacer {

	/**
	 * Replace every occurrence of a value inside a subject that may be a
	 * plain string, a PHP-serialized payload, or JSON, preserving
	 * serialization integrity (string length recount included).
	 *
	 * TODO: implement with the first module spec that needs it.
	 *
	 * @param string $subject The stored value to run the replacement on.
	 * @param string $search  The value to search for.
	 * @param string $replace The replacement value.
	 * @return string
	 * @throws \BadMethodCallException Until implemented.
	 */
	public function replace( string $subject, string $search, string $replace ): string {
		throw new \BadMethodCallException( 'Brace\Services\Replacer::replace() is not implemented yet. It ships with the first module spec that needs it.' );
	}
}
