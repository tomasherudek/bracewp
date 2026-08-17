<?php
/**
 * The admin settings page.
 *
 * @package Brace
 */

namespace Brace\Core;

/**
 * Minimal settings page under Settings, Brace: lists every registered
 * module with an enable toggle. Modules whose requirements are unmet get
 * a disabled toggle plus the human explanation. Server-rendered PHP and
 * vanilla JS only, no React, no build step.
 */
final class Admin {

	public const PAGE_SLUG   = 'brace';
	public const SAVE_ACTION = 'brace_save_modules';

	/**
	 * The plugin core.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Hook everything into the admin.
	 *
	 * @param Plugin $plugin The plugin core.
	 * @return void
	 */
	public static function register( Plugin $plugin ): void {
		$admin = new self( $plugin );

		add_action( 'admin_menu', [ $admin, 'addMenu' ] );
		add_action( 'admin_post_' . self::SAVE_ACTION, [ $admin, 'saveModules' ] );
		add_action( 'admin_enqueue_scripts', [ $admin, 'enqueueAssets' ] );
		add_action( 'admin_notices', [ $admin, 'printModuleErrorNotices' ] );
	}

	/**
	 * Set up the page.
	 *
	 * @param Plugin $plugin The plugin core.
	 */
	private function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register the settings page.
	 *
	 * @return void
	 */
	public function addMenu(): void {
		add_options_page(
			__( 'Brace', 'brace' ),
			__( 'Brace', 'brace' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'renderPage' ]
		);
	}

	/**
	 * Enqueue the admin assets, only on our own page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueueAssets( string $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'brace-admin', BRACE_URL . 'assets/admin.css', [], BRACE_VERSION );
		wp_enqueue_script( 'brace-admin', BRACE_URL . 'assets/admin.js', [], BRACE_VERSION, true );
	}

	/**
	 * Print queued module error notices, then clear the queue.
	 *
	 * @return void
	 */
	public function printModuleErrorNotices(): void {
		$errors = get_option( Plugin::ERRORS_OPTION, [] );

		if ( ! is_array( $errors ) || [] === $errors ) {
			return;
		}

		foreach ( $errors as $message ) {
			if ( ! is_string( $message ) ) {
				continue;
			}
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		}

		delete_option( Plugin::ERRORS_OPTION );
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function renderPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$registry = $this->plugin->registry();
		$modules  = $registry->all();
		?>
		<div class="wrap brace-admin">
			<h1><?php esc_html_e( 'Brace', 'brace' ); ?></h1>
			<p><?php esc_html_e( 'Every module is off by default. Enable only what you need; a disabled module is inert code on disk.', 'brace' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
				<?php wp_nonce_field( self::SAVE_ACTION ); ?>

				<table class="widefat striped brace-modules">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Enabled', 'brace' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Module', 'brace' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Description', 'brace' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( [] === $modules ) : ?>
							<tr>
								<td colspan="3"><?php esc_html_e( 'No modules registered yet. The first modules ship in an upcoming release.', 'brace' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( array_keys( $modules ) as $slug ) : ?>
								<?php $this->renderModuleRow( $registry->make( $slug ) ); ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<?php if ( [] !== $modules ) : ?>
					<?php submit_button( __( 'Save modules', 'brace' ) ); ?>
				<?php endif; ?>
			</form>

			<p class="brace-footer">
				<?php
				printf(
					/* translators: %s: link to the Brace homepage. */
					esc_html__( 'Brace by %s', 'brace' ),
					'<a href="https://tomherudek.com/brace/">Tom Herudek</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle the module toggles form submit.
	 *
	 * @return void
	 */
	public function saveModules(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Brace modules.', 'brace' ) );
		}

		check_admin_referer( self::SAVE_ACTION );

		$submitted = [];
		if ( isset( $_POST['brace_enabled'] ) ) {
			$submitted = array_map( 'sanitize_key', (array) wp_unslash( $_POST['brace_enabled'] ) );
		}

		$registry = $this->plugin->registry();

		foreach ( array_keys( $registry->all() ) as $slug ) {
			$wanted  = in_array( $slug, $submitted, true );
			$current = $registry->isEnabled( $slug );

			if ( $wanted === $current ) {
				continue;
			}

			$module = $registry->make( $slug );

			if ( $wanted ) {
				if ( ! $module->requirements()->satisfied() ) {
					continue;
				}
				$module->activate();
				$registry->enable( $slug );
			} else {
				$module->deactivate();
				$registry->disable( $slug );
			}
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'    => self::PAGE_SLUG,
					'updated' => 'true',
				],
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Render one module row.
	 *
	 * @param Module $module Module instance.
	 * @return void
	 */
	private function renderModuleRow( Module $module ): void {
		$state = $this->plugin->stateOf( $module );
		$unmet = $module->requirements()->unmet();
		?>
		<tr>
			<td>
				<input
					type="checkbox"
					name="brace_enabled[]"
					value="<?php echo esc_attr( $module->slug() ); ?>"
					<?php checked( ModuleState::Enabled === $state ); ?>
					<?php disabled( ModuleState::Unavailable === $state ); ?>
				/>
			</td>
			<td><strong><?php echo esc_html( $module->title() ); ?></strong></td>
			<td>
				<?php echo esc_html( $module->description() ); ?>
				<?php if ( [] !== $unmet ) : ?>
					<p class="description"><?php echo esc_html( implode( ' ', $unmet ) ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
}
