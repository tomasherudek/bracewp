<?php
/**
 * Plugin Name: Brace
 * Plugin URI: https://tomherudek.com/brace/
 * Description: Braces your WordPress. A modular toolbox where every module is off by default. No nags, no tracking, clean uninstall.
 * Version: 0.1.0
 * Requires at least: 6.7
 * Requires PHP: 8.1
 * Author: Tom Herudek
 * Author URI: https://tomherudek.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: brace
 *
 * This bootstrap file must stay parsable on PHP 5.6. Do not use any syntax
 * newer than PHP 5.6 in this file (no scalar type hints, no return types,
 * no null coalescing). The version guard below has to be able to run on
 * ancient servers and bail out with a notice instead of a fatal error.
 *
 * @package Brace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BRACE_VERSION', '0.1.0' );
define( 'BRACE_MIN_PHP', '8.1' );
define( 'BRACE_MIN_WP', '6.7' );
define( 'BRACE_FILE', __FILE__ );
define( 'BRACE_DIR', plugin_dir_path( __FILE__ ) );
define( 'BRACE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Collect environment problems that prevent Brace from booting.
 *
 * @return string[] List of human readable sentences. Empty when the server is fine.
 */
function brace_environment_errors() {
	$errors = array();

	if ( version_compare( PHP_VERSION, BRACE_MIN_PHP, '<' ) ) {
		$errors[] = sprintf(
			/* translators: 1: required PHP version, 2: current PHP version. */
			__( 'Brace needs PHP %1$s or newer, your server runs %2$s.', 'brace' ),
			BRACE_MIN_PHP,
			PHP_VERSION
		);
	}

	global $wp_version;
	if ( isset( $wp_version ) && version_compare( $wp_version, BRACE_MIN_WP, '<' ) ) {
		$errors[] = sprintf(
			/* translators: 1: required WordPress version, 2: current WordPress version. */
			__( 'Brace needs WordPress %1$s or newer, this site runs %2$s.', 'brace' ),
			BRACE_MIN_WP,
			$wp_version
		);
	}

	if ( empty( $errors ) && ! file_exists( BRACE_DIR . 'vendor/autoload.php' ) ) {
		$errors[] = __( 'Brace is missing its autoloader. Run "composer install" inside the plugin directory.', 'brace' );
	}

	return $errors;
}

/**
 * Print the environment errors as an admin notice.
 *
 * @return void
 */
function brace_environment_notice() {
	$errors = brace_environment_errors();

	if ( empty( $errors ) ) {
		return;
	}

	echo '<div class="notice notice-error">';
	foreach ( $errors as $error ) {
		echo '<p><strong>Brace:</strong> ' . esc_html( $error ) . '</p>';
	}
	echo '</div>';
}

$brace_environment_errors = brace_environment_errors();

if ( ! empty( $brace_environment_errors ) ) {
	add_action( 'admin_notices', 'brace_environment_notice' );
	return;
}

unset( $brace_environment_errors );

require BRACE_DIR . 'vendor/autoload.php';

Brace\Core\Plugin::boot( __FILE__ );
