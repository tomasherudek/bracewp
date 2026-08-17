<?php
/**
 * WP-CLI surface.
 *
 * @package Brace
 */

namespace Brace\Core;

/**
 * First-class WP-CLI commands: every module capability is scriptable.
 * Module-specific commands live with their modules; core ships the
 * module management commands.
 */
final class Cli {

	/**
	 * The plugin core.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Register the commands when running under WP-CLI.
	 *
	 * @param Plugin $plugin The plugin core.
	 * @return void
	 */
	public static function register( Plugin $plugin ): void {
		if ( ! defined( 'WP_CLI' ) || ! constant( 'WP_CLI' ) ) {
			return;
		}

		$cli = new self( $plugin );

		\WP_CLI::add_command( 'brace module list', [ $cli, 'listModules' ] );
		\WP_CLI::add_command( 'brace module enable', [ $cli, 'enableModule' ] );
		\WP_CLI::add_command( 'brace module disable', [ $cli, 'disableModule' ] );
	}

	/**
	 * Set up the command handler.
	 *
	 * @param Plugin $plugin The plugin core.
	 */
	private function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * List all modules and their state.
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 * @return void
	 */
	public function listModules( array $args = [], array $assoc_args = [] ): void {
		$registry = $this->plugin->registry();
		$rows     = [];

		foreach ( array_keys( $registry->all() ) as $slug ) {
			$module = $registry->make( $slug );
			$rows[] = [
				'slug'  => $slug,
				'title' => $module->title(),
				'state' => $this->plugin->stateOf( $module )->value,
			];
		}

		if ( [] === $rows ) {
			\WP_CLI::log( 'No modules registered yet.' );
			return;
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'slug', 'title', 'state' ] );
	}

	/**
	 * Enable a module.
	 *
	 * @param array<int, string>    $args       Positional arguments: the module slug.
	 * @param array<string, string> $assoc_args Named arguments.
	 * @return void
	 */
	public function enableModule( array $args = [], array $assoc_args = [] ): void {
		$slug   = $this->requireSlug( $args );
		$module = $this->plugin->registry()->make( $slug );

		$unmet = $module->requirements()->unmet();
		if ( [] !== $unmet ) {
			\WP_CLI::error( implode( ' ', $unmet ) );
		}

		$module->activate();
		$this->plugin->registry()->enable( $slug );

		\WP_CLI::success( sprintf( 'Module "%s" enabled.', $slug ) );
	}

	/**
	 * Disable a module.
	 *
	 * @param array<int, string>    $args       Positional arguments: the module slug.
	 * @param array<string, string> $assoc_args Named arguments.
	 * @return void
	 */
	public function disableModule( array $args = [], array $assoc_args = [] ): void {
		$slug   = $this->requireSlug( $args );
		$module = $this->plugin->registry()->make( $slug );

		$module->deactivate();
		$this->plugin->registry()->disable( $slug );

		\WP_CLI::success( sprintf( 'Module "%s" disabled.', $slug ) );
	}

	/**
	 * Extract and validate the slug argument.
	 *
	 * @param array<int, string> $args Positional arguments.
	 * @return string
	 */
	private function requireSlug( array $args ): string {
		$slug = $args[0] ?? '';

		if ( '' === $slug || ! $this->plugin->registry()->has( $slug ) ) {
			\WP_CLI::error( 'Unknown module slug. Run "wp brace module list" to see all modules.' );
		}

		return $slug;
	}
}
