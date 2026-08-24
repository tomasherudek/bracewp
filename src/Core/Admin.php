<?php
/**
 * The admin settings page.
 *
 * @package Brace
 */

namespace Brace\Core;

/**
 * Brace owns a top level admin menu. The menu's own page lists every
 * registered module with an enable toggle; modules whose requirements are
 * unmet get a disabled toggle plus the human explanation. Every enabled
 * module that has a settings surface gets its own submenu page underneath,
 * so a module never has to fight for room on a shared screen.
 *
 * Server-rendered PHP and vanilla JS only, no React, no build step.
 */
final class Admin {

	public const PAGE_SLUG   = 'brace';
	public const SAVE_ACTION = 'brace_save_modules';

	/**
	 * Dashicon shown next to the top level menu entry.
	 */
	private const MENU_ICON = 'dashicons-shield-alt';

	/**
	 * The plugin core.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Hook suffixes of the pages we registered, so asset loading can key
	 * off what WordPress actually gave us instead of guessing the string.
	 *
	 * @var list<string>
	 */
	private array $hookSuffixes = [];

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
	 * The submenu slug of a module's settings page.
	 *
	 * @param string $slug Module slug.
	 * @return string
	 */
	public static function modulePageSlug( string $slug ): string {
		return self::PAGE_SLUG . '-' . $slug;
	}

	/**
	 * Register the top level menu, the module list, and one submenu page
	 * per enabled module that has settings.
	 *
	 * @return void
	 */
	public function addMenu(): void {
		$this->hookSuffixes = [];

		$this->hookSuffixes[] = (string) add_menu_page(
			__( 'Brace', 'brace' ),
			__( 'Brace', 'brace' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'renderPage' ],
			self::MENU_ICON
		);

		// Without this, WordPress labels the first submenu entry "Brace" too.
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Brace modules', 'brace' ),
			__( 'Modules', 'brace' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'renderPage' ]
		);

		foreach ( $this->modulesWithSettings() as $module ) {
			$view = $module->settingsView();

			if ( null === $view ) {
				continue;
			}

			$suffix = add_submenu_page(
				self::PAGE_SLUG,
				$module->title(),
				$module->title(),
				'manage_options',
				self::modulePageSlug( $module->slug() ),
				function () use ( $module, $view ): void {
					$this->renderModulePage( $module, $view );
				}
			);

			if ( is_string( $suffix ) ) {
				$this->hookSuffixes[] = $suffix;
			}
		}
	}

	/**
	 * Enqueue the admin assets, only on our own pages.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueueAssets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->hookSuffixes, true ) ) {
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
	 * Render the module list, the top level page.
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
					'<a href="https://github.com/tomasherudek/bracewp">Tom Herudek</a>'
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
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render one module's settings page.
	 *
	 * @param Module   $module The module.
	 * @param callable $view   Its settings renderer.
	 * @return void
	 */
	private function renderModulePage( Module $module, $view ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap brace-admin brace-module-page">
			<h1><?php echo esc_html( $module->title() ); ?></h1>
			<p><?php echo esc_html( $module->description() ); ?></p>
			<?php $view(); ?>
		</div>
		<?php
	}

	/**
	 * Enabled modules that expose a settings surface, in registration order.
	 *
	 * @return list<Module>
	 */
	private function modulesWithSettings(): array {
		$registry = $this->plugin->registry();
		$modules  = [];

		foreach ( array_keys( $registry->all() ) as $slug ) {
			if ( ! $registry->isEnabled( $slug ) ) {
				continue;
			}

			$module = $registry->make( $slug );

			if ( null === $module->settingsView() ) {
				continue;
			}

			$modules[] = $module;
		}

		return $modules;
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
			<td>
				<strong><?php echo esc_html( $module->title() ); ?></strong>
				<?php if ( ModuleState::Enabled === $state && null !== $module->settingsView() ) : ?>
					<p class="description">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::modulePageSlug( $module->slug() ) ) ); ?>">
							<?php esc_html_e( 'Settings', 'brace' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</td>
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
