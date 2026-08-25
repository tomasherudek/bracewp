<?php
/**
 * Renders the module's settings screen outside a browser, to catch a fatal
 * before someone finds it by clicking. Prints the markup length and the
 * presence of the run controls; prints nothing useful if it dies, which is
 * the point.
 *
 * Run:
 *   wp eval-file wp-content/plugins/brace/tests/fixtures/staging-anonymize/render-check.php
 *
 * @package Brace
 */

use Brace\Core\Plugin;
use Brace\Modules\StagingAnonymize\StagingAnonymizeModule;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';

wp_set_current_user( 1 );

$registry = Plugin::instance()->registry();

if ( ! $registry->has( StagingAnonymizeModule::SLUG ) ) {
	WP_CLI::error( 'Module not registered.' );
}

$view = $registry->make( StagingAnonymizeModule::SLUG )->settingsView();

if ( null === $view ) {
	WP_CLI::error( 'Module has no settings view.' );
}

ob_start();
$view();
$html = (string) ob_get_clean();

WP_CLI::log( 'Rendered bytes:      ' . strlen( $html ) );
WP_CLI::log( 'Run button present:  ' . ( false !== strpos( $html, 'brace-sa-run' ) ? 'yes' : 'NO' ) );
WP_CLI::log( 'Host confirm field:  ' . ( false !== strpos( $html, 'brace-sa-host' ) ? 'yes' : 'NO' ) );
WP_CLI::log( 'Scope line:          ' . ( false !== strpos( $html, 'In scope on this site' ) ? 'yes' : 'NO' ) );
WP_CLI::success( 'Settings screen rendered without a fatal.' );
