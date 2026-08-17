<?php
/**
 * Brace uninstall routine.
 *
 * Runs when the plugin is deleted through the WordPress admin. Enumerates
 * every registered module (enabled or not), calls its uninstall() hook,
 * then removes all core options. Kept parsable on old PHP: if the server
 * runs anything below PHP 8.1 the plugin never booted, so only the core
 * option cleanup is needed there.
 *
 * @package Brace
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Give every module a chance to remove its own traces. Needs PHP 8.1 for the autoloaded classes.
if ( version_compare( PHP_VERSION, '8.1', '>=' ) && file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';

	foreach ( Brace\Core\Plugin::modules() as $brace_module_class ) {
		try {
			$brace_module = new $brace_module_class();
			if ( $brace_module instanceof Brace\Core\Module ) {
				$brace_module->uninstall();
			}
		} catch ( Throwable $brace_uninstall_error ) {
			// A broken module must not block uninstalling the rest.
			continue;
		}
	}

	unset( $brace_module_class, $brace_module );
}

// Core options.
delete_option( 'brace_modules' );
delete_option( 'brace_version' );
delete_option( 'brace_module_errors' );

// Per-module settings and logs, whether their module cleaned up or not.
global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup of dynamically named options; no API exists for LIKE deletes.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'brace\_settings\_%',
		'brace\_log\_%'
	)
);
