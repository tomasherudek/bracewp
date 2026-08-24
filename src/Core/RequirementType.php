<?php
/**
 * Kinds of server capabilities a module can require.
 *
 * @package Brace
 */

namespace Brace\Core;

/**
 * The kinds of requirements a module can declare via Requirements.
 *
 * This was a backed enum until the plugin dropped to PHP 7.4. The case
 * names are kept verbatim so every call site still reads
 * RequirementType::PhpExtension; what changed is that the value is now a
 * plain string, so anything that consumed ->value now consumes the
 * constant directly. Should the minimum ever rise to 8.1 again, this file
 * turns back into an enum and the call sites do not move.
 *
 * Constants are PascalCase rather than the usual UPPER_CASE precisely
 * because they stand in for enum cases; see phpcs.xml.dist.
 */
final class RequirementType {

	const PhpExtension = 'php_extension';
	const WpVersion    = 'wp_version';
	const WritablePath = 'writable_path';
	const Memory       = 'memory';
	const Binary       = 'binary';
	const Multisite    = 'multisite';
	const WooCommerce  = 'woocommerce';

	/**
	 * Not instantiable: this is a namespace for constants, as the enum was.
	 */
	private function __construct() {
	}
}
