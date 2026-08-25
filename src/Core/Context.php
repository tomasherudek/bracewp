<?php
/**
 * Execution contexts a module can opt into.
 *
 * @package Brace
 */

namespace Brace\Core;

/**
 * Where a module wants to be loaded. Core boots a module only in the
 * contexts it declares and skips the rest, so frontend requests never
 * pay for admin-only modules and vice versa.
 *
 * A backed enum until the plugin dropped to PHP 7.4; see RequirementType
 * for why the shape is what it is.
 */
final class Context {

	const Admin    = 'admin';
	const Frontend = 'frontend';
	const Cli      = 'cli';
	const Cron     = 'cron';

	/**
	 * Not instantiable: this is a namespace for constants, as the enum was.
	 */
	private function __construct() {
	}
}
