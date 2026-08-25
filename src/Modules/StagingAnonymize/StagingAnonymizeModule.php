<?php
/**
 * Staging Anonymize module.
 *
 * @package Brace
 */

namespace Brace\Modules\StagingAnonymize;

use Brace\Core\Admin;
use Brace\Core\Context;
use Brace\Core\Module;
use Brace\Core\Plugin;
use Brace\Core\Requirements;
use Brace\Services\Batch;
use Brace\Services\BatchRunner;
use Brace\Services\Environment;
use Brace\Services\FakeIdentity;

/**
 * Anonymizes users on a staging copy — and WooCommerce customers and
 * orders too, when the shop data is present: deterministic fakes keyed by
 * production ids, traceable through plus-addressed emails, never runnable
 * on production. Spec: docs/modules/003-staging-anonymize.md.
 *
 * Version 0 is CLI-first: the run itself happens through WP-CLI, the admin page
 * carries settings, status, and the copy-paste commands. After a run the
 * module blocks all outgoing wp_mail() traffic until the toggle here or
 * a future Staging Guard takes over.
 */
final class StagingAnonymizeModule implements Module {

	public const SLUG        = 'staging-anonymize';
	public const SAVE_ACTION = 'brace_staging_anonymize_save';
	public const TICK_ACTION = 'brace_staging_anonymize_tick';

	/**
	 * Setting key holding the position of a browser-driven run in progress.
	 */
	private const RUN_STATE = 'run_state';

	/**
	 * Seconds after which an abandoned run stops blocking a new one, so a
	 * closed browser tab does not lock the module until someone digs the
	 * option out of the database.
	 */
	private const RUN_STALE_AFTER = 120;

	/**
	 * Module slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return self::SLUG;
	}

	/**
	 * Module title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Staging Anonymize', 'brace' );
	}

	/**
	 * Module description.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Rewrites every user on a staging copy into a deterministic fake, traceable back to production by id — plus WooCommerce customers and orders when the shop data is there. On a copy that still reads as production it warns loudly and only runs once the hostname is typed out.', 'brace' );
	}

	/**
	 * The mail block must load everywhere: cron and frontend requests
	 * send mail too.
	 *
	 * @return list<string> Context constants.
	 */
	public function contexts(): array {
		return [ Context::Admin, Context::Frontend, Context::Cli, Context::Cron ];
	}

	/**
	 * Server requirements: the plugin baseline, nothing more.
	 *
	 * WooCommerce is deliberately not required. Plain WordPress users are
	 * personal data on their own, and a staging copy of a site without a
	 * shop still needs them gone. The operation detects shop data per
	 * table and skips what is not there.
	 *
	 * @return Requirements
	 */
	public function requirements(): Requirements {
		return Requirements::none();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->mailBlocked() ) {
			add_filter( 'pre_wp_mail', '__return_false' );
		}

		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
			Cli::register( $this );
		}

		if ( is_admin() ) {
			add_action( 'admin_post_' . self::SAVE_ACTION, [ $this, 'saveSettings' ] );
			add_action( 'wp_ajax_' . self::TICK_ACTION, [ $this, 'ajaxTick' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueueRunner' ] );
		}
	}

	/**
	 * Attach the run-page script, on this module's page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueueRunner( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, Admin::modulePageSlug( self::SLUG ) ) ) {
			return;
		}

		wp_enqueue_script( 'brace-staging-anonymize', BRACE_URL . 'assets/staging-anonymize.js', [], BRACE_VERSION, true );
		wp_localize_script(
			'brace-staging-anonymize',
			'braceStagingAnonymize',
			[
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				'action'            => self::TICK_ACTION,
				'nonce'             => wp_create_nonce( self::TICK_ACTION ),
				'readsAsProduction' => ! ( new Environment() )->isStaging(),
				'i18n'              => [
					'confirm'           => __( 'This rewrites every user and order on this site. It cannot be undone without a backup or a fresh clone. Continue?', 'brace' ),
					'confirmProduction' => __( 'WARNING: this site reads as PRODUCTION. If it really is the live site, this destroys real customer data. Continue only if you are certain this is a disposable copy. Rewrite every user and order on this site?', 'brace' ),
					'backingUp'         => __( 'Backing up affected tables...', 'brace' ),
					/* translators: %s: name of the stage currently running. */
					'stage'             => __( 'Working: %s', 'brace' ),
					'done'              => __( 'Anonymization complete. Outgoing email is now blocked on this copy.', 'brace' ),
					/* translators: %s: reason the run stopped. */
					'failed'            => __( 'Run failed: %s', 'brace' ),
					'network'           => __( 'The request failed. The run is paused, not rolled back — reload and start again to resume.', 'brace' ),
				],
			]
		);
	}

	/**
	 * One bounded tick of a browser-driven run.
	 *
	 * Every guard the CLI applies is re-applied here on every single tick,
	 * not just when the run starts: a request that arrives after someone
	 * has pointed this site back at production must not be allowed to
	 * continue a run that was legitimate when it began. A production
	 * verdict alone no longer blocks the tick — hosts like WP Engine's
	 * staging URLs are indistinguishable from production — but the typed
	 * hostname must match on every tick, so a repoint mid-run still stops
	 * the run: the host changes and the confirmation stops matching.
	 *
	 * @return void
	 */
	public function ajaxTick(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to run this.', 'brace' ) ], 403 );
		}

		check_ajax_referer( self::TICK_ACTION );

		$environment = new Environment();

		$given = strtolower( trim( (string) ( $_POST['confirm_host'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- compared verbatim against home_url()'s host, never stored or echoed.
		$given = 0 === strpos( $given, 'www.' ) ? substr( $given, 4 ) : $given;

		if ( $given !== $environment->host() ) {
			wp_send_json_error(
				[
					/* translators: %s: the site's own hostname. */
					'message' => sprintf( __( 'Host confirmation failed. Type %s exactly.', 'brace' ), $environment->host() ),
				],
				400
			);
		}

		$state = $this->runState();
		$owner = (int) ( $state['owner'] ?? 0 );

		if ( $owner > 0 && get_current_user_id() !== $owner && ! $this->runIsStale( $state ) ) {
			wp_send_json_error( [ 'message' => __( 'Another administrator is running this right now.', 'brace' ) ], 409 );
		}

		$operation = new AnonymizeOperation( new FakeIdentity( $this->mailbox() ), $this->excludedRoles() );

		// A dedicated first step: dumping tables can take a whole request
		// on its own, and sharing one with the stage machine would mean
		// neither gets a full budget.
		if ( 'backup' === ( $_POST['step'] ?? '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- compared against a literal.
			wp_send_json_success(
				[
					'step'   => 'backup',
					'backup' => $operation->backup(),
				]
			);
		}

		if ( [] !== $state ) {
			$operation->restore( $state );
		}

		( new BatchRunner() )->tick( $operation, new Batch() );

		if ( ! $operation->finished() ) {
			$this->storeRunState( $operation->state() );

			wp_send_json_success(
				[
					'step'     => 'run',
					'finished' => false,
					'stage'    => $operation->currentStage(),
					'changed'  => $operation->report()['changed'],
				]
			);
		}

		$report = $operation->report();

		$this->clearRunState();
		$this->setMailBlocked( true );
		$this->storeLastRun( $report );

		wp_send_json_success(
			[
				'step'     => 'run',
				'finished' => true,
				'stage'    => 'done',
				'changed'  => $report['changed'],
				'kept'     => $report['kept_on_purpose'],
			]
		);
	}

	/**
	 * The stored position of a run in progress, empty when none.
	 *
	 * @return array<string, mixed>
	 */
	private function runState(): array {
		$stored = $this->setting( self::RUN_STATE, [] );

		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Whether a stored run has been abandoned long enough to take over.
	 *
	 * @param array<string, mixed> $state The stored run state.
	 * @return bool
	 */
	private function runIsStale( array $state ): bool {
		return time() - (int) ( $state['touched'] ?? 0 ) > self::RUN_STALE_AFTER;
	}

	/**
	 * Persist the position of a run in progress.
	 *
	 * @param array<string, mixed> $state State from the operation.
	 * @return void
	 */
	private function storeRunState( array $state ): void {
		$state['owner']   = get_current_user_id();
		$state['touched'] = time();

		$this->writeSetting( self::RUN_STATE, $state );
	}

	/**
	 * Forget any run in progress.
	 *
	 * @return void
	 */
	private function clearRunState(): void {
		$this->writeSetting( self::RUN_STATE, [] );
	}

	/**
	 * Toggle-on setup: nothing to prepare.
	 *
	 * @return void
	 */
	public function activate(): void {
	}

	/**
	 * Toggle-off teardown: settings survive a toggle, including the mail
	 * block state — but a disabled module registers no hooks, so the
	 * block stops applying. The settings page says so.
	 *
	 * @return void
	 */
	public function deactivate(): void {
	}

	/**
	 * Remove every trace on plugin uninstall.
	 *
	 * @return void
	 */
	public function uninstall(): void {
		delete_option( 'brace_settings_' . self::SLUG );
		delete_option( 'brace_log_' . self::SLUG );
	}

	/**
	 * The settings page renderer.
	 *
	 * @return ?callable
	 */
	public function settingsView(): ?callable {
		return function (): void {
			$this->renderSettings();
		};
	}

	/**
	 * The target mailbox for the plus-addressing scheme.
	 *
	 * @return string
	 */
	public function mailbox(): string {
		$stored = $this->setting( 'mailbox', '' );

		if ( is_string( $stored ) && '' !== $stored ) {
			return $stored;
		}

		return (string) get_option( 'admin_email' );
	}

	/**
	 * Roles whose users keep their identity and password.
	 *
	 * @return list<string>
	 */
	public function excludedRoles(): array {
		$stored = $this->setting( 'excluded_roles', [ 'administrator' ] );

		return is_array( $stored ) ? array_values( array_map( 'strval', $stored ) ) : [ 'administrator' ];
	}

	/**
	 * Whether outgoing mail is currently blocked by this module.
	 *
	 * @return bool
	 */
	public function mailBlocked(): bool {
		return (bool) $this->setting( 'mail_blocked', false );
	}

	/**
	 * Set the mail block flag.
	 *
	 * @param bool $blocked New state.
	 * @return void
	 */
	public function setMailBlocked( bool $blocked ): void {
		$this->writeSetting( 'mail_blocked', $blocked );
	}

	/**
	 * Store the post-run report.
	 *
	 * @param array<string, mixed> $report The operation report.
	 * @return void
	 */
	public function storeLastRun( array $report ): void {
		$report['time'] = time();
		$this->writeSetting( 'last_run', $report );
	}

	/**
	 * The stored last-run report, if any.
	 *
	 * @return ?array<string, mixed>
	 */
	public function lastRun(): ?array {
		$stored = $this->setting( 'last_run', null );

		return is_array( $stored ) ? $stored : null;
	}

	/**
	 * Handle the settings form submit.
	 *
	 * @return void
	 */
	public function saveSettings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage this module.', 'brace' ) );
		}

		check_admin_referer( self::SAVE_ACTION );

		$plugin = Plugin::instance();
		if ( null === $plugin ) {
			return;
		}

		$settings = $plugin->settings();

		$mailbox = isset( $_POST['brace_sa_mailbox'] ) ? sanitize_email( wp_unslash( $_POST['brace_sa_mailbox'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslashSanitize -- sanitize_email sanitizes.
		$settings->set( self::SLUG, 'mailbox', $mailbox );

		$roles_raw = isset( $_POST['brace_sa_roles'] ) ? sanitize_text_field( wp_unslash( $_POST['brace_sa_roles'] ) ) : 'administrator';
		$roles     = array_values( array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $roles_raw ) ) ) ) );
		$settings->set( self::SLUG, 'excluded_roles', [] !== $roles ? $roles : [ 'administrator' ] );

		$this->setMailBlocked( isset( $_POST['brace_sa_mail_blocked'] ) );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'    => 'brace-' . self::SLUG,
					'updated' => 'true',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the settings page body.
	 *
	 * @return void
	 */
	private function renderSettings(): void {
		$environment = new Environment();
		$reason      = $environment->stagingReason();
		$last        = $this->lastRun();
		?>
		<?php if ( null === $reason ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php esc_html_e( 'This site reads as PRODUCTION — no staging signal found. Be careful: if this really is the live site, anonymization would destroy real customer data. If it is a copy on a host that only looks public (WP Engine and similar staging URLs do), you can still run it below by typing this site\'s hostname. Better yet, set WP_ENVIRONMENT_TYPE to "staging" (or define BRACE_ENVIRONMENT as "staging") in wp-config.php and the warning goes away.', 'brace' ); ?>
			</p></div>
		<?php else : ?>
			<div class="notice notice-info inline"><p>
				<?php
				printf(
					/* translators: %s: detection reason sentence. */
					esc_html__( 'Staging detected: %s.', 'brace' ),
					esc_html( $reason )
				);
				?>
			</p></div>
		<?php endif; ?>

		<?php if ( null !== $last && isset( $last['time'] ) ) : ?>
			<div class="notice notice-success inline"><p>
				<?php
				printf(
					/* translators: %s: date and time of the last anonymization run. */
					esc_html__( 'Customer data on this copy was anonymized on %s. Kept on purpose: wc-logs files.', 'brace' ),
					esc_html( wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), (int) $last['time'] ) )
				);
				?>
			</p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
			<?php wp_nonce_field( self::SAVE_ACTION ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="brace_sa_mailbox"><?php esc_html_e( 'Target mailbox', 'brace' ); ?></label></th>
					<td>
						<input type="email" id="brace_sa_mailbox" name="brace_sa_mailbox" class="regular-text" value="<?php echo esc_attr( $this->mailbox() ); ?>" />
						<p class="description"><?php esc_html_e( 'All fake addresses deliver here via plus-addressing: user 42 becomes yourname+userid.42@yourdomain. The production user id stays visible in every address.', 'brace' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="brace_sa_roles"><?php esc_html_e( 'Excluded roles', 'brace' ); ?></label></th>
					<td>
						<input type="text" id="brace_sa_roles" name="brace_sa_roles" class="regular-text" value="<?php echo esc_attr( implode( ', ', $this->excludedRoles() ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Comma-separated. Users with these roles keep their identity and password, so you do not lock yourself out.', 'brace' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Outgoing email', 'brace' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="brace_sa_mail_blocked" <?php checked( $this->mailBlocked() ); ?> />
							<?php esc_html_e( 'Block all outgoing email (wp_mail) on this site', 'brace' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Switched on automatically after an anonymization run: thousands of addresses then point at one real mailbox, and a single bulk action would flood it. Untick only while deliberately testing email.', 'brace' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<h2><?php esc_html_e( 'Run it from here', 'brace' ); ?></h2>

		<?php if ( null === $reason ) : ?>
			<p><strong><?php esc_html_e( 'This copy reads as production.', 'brace' ); ?></strong>
			<?php esc_html_e( 'Typing the hostname below overrides that warning — make sure you are on a disposable copy before you do.', 'brace' ); ?></p>
		<?php endif; ?>

		<?php $operation = new AnonymizeOperation( new FakeIdentity( $this->mailbox() ), $this->excludedRoles() ); ?>
		<p>
			<?php
			echo esc_html(
				$operation->wooDetected()
					? __( 'In scope on this site: WordPress users and WooCommerce customers and orders.', 'brace' )
					: __( 'In scope on this site: WordPress users only — no WooCommerce data found.', 'brace' )
			);
			?>
		</p>

		<div id="brace-sa-runner">
			<p>
				<label>
					<input type="checkbox" id="brace-sa-backup" checked />
					<?php esc_html_e( 'Back up the affected tables first', 'brace' ); ?>
				</label>
				<span class="description"><?php esc_html_e( 'The backup contains the very data being removed. Purge it once you have verified this copy.', 'brace' ); ?></span>
			</p>
			<p>
				<label for="brace-sa-host">
					<?php
					printf(
						/* translators: %s: the site's own hostname. */
						esc_html__( 'Type %s to confirm:', 'brace' ),
						'<code>' . esc_html( $environment->host() ) . '</code>'
					);
					?>
				</label><br />
				<input type="text" id="brace-sa-host" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr( $environment->host() ); ?>" />
			</p>
			<p>
				<button type="button" class="button button-primary" id="brace-sa-run"><?php esc_html_e( 'Anonymize this copy', 'brace' ); ?></button>
			</p>
			<div id="brace-sa-progress" hidden></div>
		</div>

		<h2><?php esc_html_e( 'Or over WP-CLI', 'brace' ); ?></h2>
		<p><?php esc_html_e( 'Same operation, same guards. Use this to make anonymization the last step of your staging sync script, instead of something someone remembers to click:', 'brace' ); ?></p>
		<pre><code>wp brace staging-anonymize status
wp brace staging-anonymize dry-run
wp brace staging-anonymize run --confirm-host=<?php echo esc_html( $environment->host() ); ?>

wp brace staging-anonymize purge-backup   <?php echo esc_html__( '# after verifying: the backup still contains the original PII', 'brace' ); ?></code></pre>
		<?php
	}

	/**
	 * One stored setting of this module.
	 *
	 * @param string $key           Setting key.
	 * @param mixed  $default_value Fallback value.
	 * @return mixed
	 */
	private function setting( string $key, $default_value ) {
		$plugin = Plugin::instance();

		if ( null === $plugin ) {
			return $default_value;
		}

		return $plugin->settings()->get( self::SLUG, $key, $default_value );
	}

	/**
	 * Write one of this module's settings, if the plugin is booted.
	 *
	 * The counterpart to setting(). A missing Plugin instance means the
	 * write is dropped, which matches how setting() falls back to its
	 * default: neither side pretends the store exists when it does not.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Value to store.
	 * @return void
	 */
	private function writeSetting( string $key, $value ): void {
		$plugin = Plugin::instance();

		if ( null === $plugin ) {
			return;
		}

		$plugin->settings()->set( self::SLUG, $key, $value );
	}
}
