<?php
/**
 * Kinds of server capabilities a module can require.
 *
 * @package Brace
 */

namespace Brace\Core;

/**
 * The kinds of requirements a module can declare via Requirements.
 */
enum RequirementType: string {
	case PhpExtension = 'php_extension';
	case WpVersion    = 'wp_version';
	case WritablePath = 'writable_path';
	case Memory       = 'memory';
	case Binary       = 'binary';
	case Multisite    = 'multisite';
}
