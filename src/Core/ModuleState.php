<?php
/**
 * The states a module can be in.
 *
 * @package Brace
 */

namespace Brace\Core;

/**
 * Effective state of a module as shown in the admin and WP-CLI.
 *
 * Unavailable means the server does not meet the module's requirements,
 * so the toggle is disabled with a human explanation.
 *
 * A backed enum until the plugin dropped to PHP 7.4; see RequirementType
 * for why the shape is what it is.
 */
final class ModuleState {

	const Enabled     = 'enabled';
	const Disabled    = 'disabled';
	const Unavailable = 'unavailable';

	/**
	 * Not instantiable: this is a namespace for constants, as the enum was.
	 */
	private function __construct() {
	}
}
