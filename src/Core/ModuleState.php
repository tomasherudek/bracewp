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
 */
enum ModuleState: string {
	case Enabled     = 'enabled';
	case Disabled    = 'disabled';
	case Unavailable = 'unavailable';
}
