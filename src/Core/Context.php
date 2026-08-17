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
 */
enum Context: string {
	case Admin    = 'admin';
	case Frontend = 'frontend';
	case Cli      = 'cli';
	case Cron     = 'cron';
}
