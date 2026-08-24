<?php
/**
 * WP-CLI surface of the Staging Anonymize module.
 *
 * @package Brace
 */

namespace Brace\Modules\StagingAnonymize;

use Brace\Services\Backup;
use Brace\Services\Batch;
use Brace\Services\BatchRunner;
use Brace\Services\Environment;
use Brace\Services\FakeIdentity;

/**
 * The scriptable surface. The intended automation is: clone production,
 * then run this as the last step of the sync script, so anonymization is
 * part of the clone rather than a step someone remembers.
 */
final class Cli {

	/**
	 * The owning module.
	 *
	 * @var StagingAnonymizeModule
	 */
	private StagingAnonymizeModule $module;

	/**
	 * Environment verdict service.
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * Register the commands.
	 *
	 * @param StagingAnonymizeModule $module The owning module.
	 * @return void
	 */
	public static function register( StagingAnonymizeModule $module ): void {
		$cli = new self( $module );

		\WP_CLI::add_command( 'brace staging-anonymize status', [ $cli, 'status' ] );
		\WP_CLI::add_command( 'brace staging-anonymize dry-run', [ $cli, 'dryRun' ] );
		\WP_CLI::add_command( 'brace staging-anonymize run', [ $cli, 'run' ] );
		\WP_CLI::add_command( 'brace staging-anonymize purge-backup', [ $cli, 'purgeBackup' ] );
	}

	/**
	 * Set up the command handler.
	 *
	 * @param StagingAnonymizeModule $module The owning module.
	 */
	private function __construct( StagingAnonymizeModule $module ) {
		$this->module      = $module;
		$this->environment = new Environment();
	}

	/**
	 * Show the environment verdict, detected storages, and last run.
	 *
	 * Exits non-zero on a production verdict, so a deploy script can gate on it.
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 * @return void
	 */
	public function status( array $args = [], array $assoc_args = [] ): void {
		$reason    = $this->environment->stagingReason();
		$operation = $this->operation();
		$last      = $this->module->lastRun();

		\WP_CLI::log( 'Host:            ' . $this->environment->host() );
		\WP_CLI::log( 'Verdict:         ' . ( null === $reason ? 'PRODUCTION' : 'staging' ) );

		if ( null !== $reason ) {
			\WP_CLI::log( 'Reason:          ' . $reason );
		}

		$storages = $operation->detectedStorages();

		\WP_CLI::log( 'Scope:           ' . ( $operation->wooDetected() ? 'WordPress users + WooCommerce customers and orders' : 'WordPress users only (no WooCommerce data found)' ) );
		\WP_CLI::log( 'Order storage:   ' . ( [] === $storages ? 'none' : implode( ', ', $storages ) ) );
		\WP_CLI::log( 'Target mailbox:  ' . $this->module->mailbox() );
		\WP_CLI::log( 'Excluded roles:  ' . implode( ', ', $this->module->excludedRoles() ) );
		\WP_CLI::log( 'Outgoing mail:   ' . ( $this->module->mailBlocked() ? 'blocked' : 'ALLOWED' ) );
		\WP_CLI::log( 'Backups stored:  ' . implode( ', ', ( new Backup() )->sets() ) );

		if ( null !== $last && isset( $last['time'] ) ) {
			\WP_CLI::log( 'Last run:        ' . gmdate( 'Y-m-d H:i:s', (int) $last['time'] ) . ' UTC' );
		} else {
			\WP_CLI::log( 'Last run:        never' );
		}

		if ( null === $reason ) {
			\WP_CLI::error( 'This site reads as production. Anonymization will refuse to run.' );
		}
	}

	/**
	 * Report what a run would change, without changing anything.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Accepted: table, json. Default: table.
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 * @return void
	 */
	public function dryRun( array $args = [], array $assoc_args = [] ): void {
		$report = $this->operation()->dryRun();

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::log( (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		\WP_CLI::log( 'Order storage detected: ' . ( [] === $report['storages'] ? 'none' : implode( ', ', $report['storages'] ) ) );
		\WP_CLI::log( '' );

		$rows = [];
		foreach ( $report['estimate'] as $table => $count ) {
			$rows[] = [
				'table' => $table,
				'rows'  => $count,
			];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'table', 'rows' ] );

		\WP_CLI::log( '' );
		\WP_CLI::log( 'Sample of the fakes this run would write:' );
		foreach ( $report['sample'] as $label => $value ) {
			\WP_CLI::log( '  ' . $label . ': ' . $value );
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( 'Users kept untouched (excluded roles): ' . $report['excluded_users'] );

		if ( isset( $report['pending_scheduled_actions'] ) ) {
			\WP_CLI::log( 'Pending Action Scheduler jobs (NOT touched): ' . $report['pending_scheduled_actions'] );
		}

		\WP_CLI::log( '' );
		\WP_CLI::warning( 'Kept on purpose, may still contain personal data:' );
		foreach ( $report['kept_on_purpose'] as $note ) {
			\WP_CLI::log( '  - ' . $note );
		}
	}

	/**
	 * Anonymize every customer and order on this copy.
	 *
	 * ## OPTIONS
	 *
	 * --confirm-host=<host>
	 * : The host of this site, typed out. Refuses when it does not match.
	 *
	 * [--no-backup]
	 * : Skip the pre-run backup. Use when your undo path is re-cloning production.
	 *
	 * [--batch-size=<rows>]
	 * : Rows processed per chunk. Default: 200.
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 * @return void
	 */
	public function run( array $args = [], array $assoc_args = [] ): void {
		$reason = $this->environment->stagingReason();

		if ( null === $reason ) {
			\WP_CLI::error(
				'This site reads as production. Anonymization refuses to run. If this really is a copy, set WP_ENVIRONMENT_TYPE or define BRACE_ENVIRONMENT as "staging" in wp-config.php.'
			);
		}

		$expected = $this->environment->host();
		$given    = strtolower( trim( (string) ( $assoc_args['confirm-host'] ?? '' ) ) );
		$given    = 0 === strpos( $given, 'www.' ) ? substr( $given, 4 ) : $given;

		if ( $given !== $expected ) {
			\WP_CLI::error( sprintf( 'Host confirmation failed. Re-run with --confirm-host=%s', $expected ) );
		}

		\WP_CLI::log( 'Staging confirmed (' . $reason . ').' );

		$operation = $this->operation( isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : AnonymizeOperation::DEFAULT_CHUNK );

		if ( ! isset( $assoc_args['no-backup'] ) ) {
			\WP_CLI::log( 'Backing up affected tables...' );
			$backup_id = $operation->backup();
			\WP_CLI::log( 'Backup: ' . $backup_id );
			\WP_CLI::warning( 'That backup contains the original personal data. Run "wp brace staging-anonymize purge-backup" once you have verified this copy.' );
		}

		\WP_CLI::log( 'Anonymizing...' );

		$runner = new BatchRunner();
		$report = $runner->runToCompletion(
			$operation,
			Batch::DEFAULT_TIME_BUDGET,
			static function () use ( $operation ): void {
				\WP_CLI::log( '  stage: ' . $operation->currentStage() );
			}
		);

		$this->module->setMailBlocked( true );
		$this->module->storeLastRun( $report );

		foreach ( $report['changed'] as $stage => $count ) {
			\WP_CLI::log( sprintf( '  %-16s %d', $stage, $count ) );
		}

		\WP_CLI::log( 'Outgoing email is now blocked on this copy.' );
		\WP_CLI::warning( 'Kept on purpose: order notes (all of them) and wc-logs files in uploads. Both can still contain personal data.' );
		\WP_CLI::success( 'Anonymization complete.' );
	}

	/**
	 * Delete stored backups, which still contain the original personal data.
	 *
	 * ## OPTIONS
	 *
	 * [--keep=<count>]
	 * : How many recent backup sets to keep. Default: 0 (delete all).
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 * @return void
	 */
	public function purgeBackup( array $args = [], array $assoc_args = [] ): void {
		$backup = new Backup();
		$before = count( $backup->sets() );

		$backup->prune( isset( $assoc_args['keep'] ) ? (int) $assoc_args['keep'] : 0 );

		\WP_CLI::success( sprintf( 'Removed %d backup set(s).', $before - count( $backup->sets() ) ) );
	}

	/**
	 * Build the operation from the module's settings.
	 *
	 * @param int $chunk Rows per chunk.
	 * @return AnonymizeOperation
	 */
	private function operation( int $chunk = AnonymizeOperation::DEFAULT_CHUNK ): AnonymizeOperation {
		return new AnonymizeOperation(
			new FakeIdentity( $this->module->mailbox() ),
			$this->module->excludedRoles(),
			$chunk
		);
	}
}
