<?php
/**
 * The plugin core.
 *
 * @package Brace
 */

namespace Brace\Core;

/**
 * Boots the plugin: registers shipped modules, wires the admin and WP-CLI
 * surfaces, and boots enabled modules behind the safety layers.
 *
 * Safety layers implemented here:
 * 2. Requirements re-check at every boot; a module whose requirements
 *    vanished is soft-skipped with a notice, never fataled.
 * 3. Fatal containment: each module boots inside try/catch, and a
 *    shutdown handler detects a fatal during a module's boot window and
 *    auto-disables that module.
 */
final class Plugin {

	public const VERSION_OPTION = 'brace_version';
	public const ERRORS_OPTION  = 'brace_module_errors';

	/**
	 * Singleton instance.
	 *
	 * @var ?Plugin
	 */
	private static ?Plugin $instance = null;

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	private string $file;

	/**
	 * The module registry.
	 *
	 * @var ModuleRegistry
	 */
	private ModuleRegistry $registry;

	/**
	 * Per-module settings storage.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Per-module logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Slug of the module currently inside its boot window, if any.
	 * Used by the shutdown handler for fatal containment.
	 *
	 * @var ?string
	 */
	private ?string $bootingModule = null;

	/**
	 * The shipped modules, slug to class name.
	 *
	 * Single source of truth: used at boot and by uninstall.php, which
	 * enumerates ALL modules (enabled or not) for their uninstall() hook.
	 *
	 * @return array<string, class-string<Module>>
	 */
	public static function modules(): array {
		return [];
	}

	/**
	 * Boot the plugin once.
	 *
	 * @param string $file Absolute path to the main plugin file.
	 * @return Plugin
	 */
	public static function boot( string $file ): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self( $file );
			self::$instance->run();
		}

		return self::$instance;
	}

	/**
	 * The booted instance, if any.
	 *
	 * @return ?Plugin
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Set up collaborators.
	 *
	 * @param string $file Absolute path to the main plugin file.
	 */
	private function __construct( string $file ) {
		$this->file     = $file;
		$this->registry = new ModuleRegistry();
		$this->settings = new Settings();
		$this->logger   = new Logger();
	}

	/**
	 * The module registry.
	 *
	 * @return ModuleRegistry
	 */
	public function registry(): ModuleRegistry {
		return $this->registry;
	}

	/**
	 * Per-module settings storage.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Per-module logger.
	 *
	 * @return Logger
	 */
	public function logger(): Logger {
		return $this->logger;
	}

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @return string
	 */
	public function file(): string {
		return $this->file;
	}

	/**
	 * Effective state of a module for the admin and WP-CLI surfaces.
	 *
	 * @param Module $module Module instance.
	 * @return ModuleState
	 */
	public function stateOf( Module $module ): ModuleState {
		if ( ! $module->requirements()->satisfied() ) {
			return ModuleState::Unavailable;
		}

		return $this->registry->isEnabled( $module->slug() ) ? ModuleState::Enabled : ModuleState::Disabled;
	}

	/**
	 * The context of the current request.
	 *
	 * @return Context
	 */
	public function currentContext(): Context {
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
			return Context::Cli;
		}

		if ( wp_doing_cron() ) {
			return Context::Cron;
		}

		if ( is_admin() ) {
			return Context::Admin;
		}

		return Context::Frontend;
	}

	/**
	 * Boot every enabled module for the current context, behind the
	 * safety layers.
	 *
	 * @return void
	 */
	public function bootModules(): void {
		register_shutdown_function( [ $this, 'containFatal' ] );

		$context = $this->currentContext();

		foreach ( $this->registry->enabledSlugs() as $slug ) {
			$module = $this->registry->make( $slug );

			// Context gating: skip modules that do not load here.
			if ( ! in_array( $context, $module->contexts(), true ) ) {
				continue;
			}

			// Safety layer 2: re-check requirements every boot, hosting changes.
			$unmet = $module->requirements()->unmet();
			if ( [] !== $unmet ) {
				$this->recordError(
					sprintf(
						/* translators: 1: module title, 2: reasons the module was skipped. */
						__( 'Brace module "%1$s" was skipped because your server no longer supports it: %2$s', 'brace' ),
						$module->title(),
						implode( ' ', $unmet )
					)
				);
				continue;
			}

			// Safety layer 3: fatal containment around the boot window.
			$this->bootingModule = $slug;

			try {
				$module->boot();
			} catch ( \Throwable $error ) {
				$this->disableAfterError( $slug, $error->getMessage() );
			}

			$this->bootingModule = null;
		}
	}

	/**
	 * Shutdown handler: if a fatal happened while a module was booting,
	 * auto-disable that module so the next request survives.
	 *
	 * @return void
	 */
	public function containFatal(): void {
		if ( null === $this->bootingModule ) {
			return;
		}

		$error = error_get_last();

		if ( null === $error ) {
			return;
		}

		if ( ! in_array( $error['type'], [ E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR ], true ) ) {
			return;
		}

		$this->disableAfterError( $this->bootingModule, $error['message'] );
	}

	/**
	 * Wire everything up. Called once from boot().
	 *
	 * @return void
	 */
	private function run(): void {
		foreach ( self::modules() as $slug => $module_class ) {
			$this->registry->register( $slug, $module_class );
		}

		$this->maybeUpgrade();

		if ( is_admin() ) {
			Admin::register( $this );
		}

		Cli::register( $this );

		add_action( 'plugins_loaded', [ $this, 'bootModules' ] );
	}

	/**
	 * Run upgrade migrations when the stored version is behind.
	 *
	 * @return void
	 */
	private function maybeUpgrade(): void {
		$stored = get_option( self::VERSION_OPTION );

		if ( BRACE_VERSION === $stored ) {
			return;
		}

		// Future migrations run here, keyed on $stored.
		update_option( self::VERSION_OPTION, BRACE_VERSION );
	}

	/**
	 * Disable a module after an error and queue an admin notice.
	 *
	 * @param string $slug    Module slug.
	 * @param string $message The error message.
	 * @return void
	 */
	private function disableAfterError( string $slug, string $message ): void {
		$this->registry->disable( $slug );
		$this->logger->log( $slug, $message, 'error' );
		$this->recordError(
			sprintf(
				/* translators: 1: module slug, 2: error message. */
				__( 'Brace module "%1$s" disabled itself after an error: %2$s', 'brace' ),
				$slug,
				$message
			)
		);

		$this->bootingModule = null;
	}

	/**
	 * Queue a notice for the next admin page load.
	 *
	 * @param string $message Notice text.
	 * @return void
	 */
	private function recordError( string $message ): void {
		$errors = get_option( self::ERRORS_OPTION, [] );

		if ( ! is_array( $errors ) ) {
			$errors = [];
		}

		$errors[] = $message;

		update_option( self::ERRORS_OPTION, $errors, false );
	}
}
