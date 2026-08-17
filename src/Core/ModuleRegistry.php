<?php
/**
 * The static module registry.
 *
 * @package Brace
 */

namespace Brace\Core;

/**
 * Holds the slug to class map of all shipped modules plus the enable flags.
 *
 * Flags live in the autoloaded brace_modules option (kept tiny: one bool
 * per module). A disabled module's class is never autoloaded; the registry
 * only instantiates a module when explicitly asked to.
 */
final class ModuleRegistry {

	public const OPTION = 'brace_modules';

	/**
	 * Registered modules, slug to class name.
	 *
	 * @var array<string, class-string<Module>>
	 */
	private array $modules = [];

	/**
	 * Register a module class under its slug.
	 *
	 * @param string $slug         Unique module slug.
	 * @param string $module_class Fully qualified class name implementing Module.
	 * @return void
	 * @throws \InvalidArgumentException When the slug is taken or the class is not a Module.
	 */
	public function register( string $slug, string $module_class ): void {
		if ( isset( $this->modules[ $slug ] ) ) {
			throw new \InvalidArgumentException( sprintf( 'Brace module slug "%s" is already registered.', esc_html( $slug ) ) );
		}

		if ( ! is_subclass_of( $module_class, Module::class ) ) {
			throw new \InvalidArgumentException( sprintf( 'Brace module class "%s" must implement %s.', esc_html( $module_class ), esc_html( Module::class ) ) );
		}

		$this->modules[ $slug ] = $module_class;
	}

	/**
	 * Whether a slug is registered.
	 *
	 * @param string $slug Module slug.
	 * @return bool
	 */
	public function has( string $slug ): bool {
		return isset( $this->modules[ $slug ] );
	}

	/**
	 * All registered modules.
	 *
	 * @return array<string, class-string<Module>> Slug to class name.
	 */
	public function all(): array {
		return $this->modules;
	}

	/**
	 * Enable flags for every registered module.
	 *
	 * Unregistered slugs in the stored option are ignored; missing slugs
	 * default to disabled (every module is OFF by default).
	 *
	 * @return array<string, bool> Slug to enabled flag.
	 */
	public function flags(): array {
		$stored = get_option( self::OPTION, [] );

		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		$flags = [];

		foreach ( array_keys( $this->modules ) as $slug ) {
			$flags[ $slug ] = ! empty( $stored[ $slug ] );
		}

		return $flags;
	}

	/**
	 * Whether a module is enabled.
	 *
	 * @param string $slug Module slug.
	 * @return bool
	 */
	public function isEnabled( string $slug ): bool {
		$flags = $this->flags();

		return $flags[ $slug ] ?? false;
	}

	/**
	 * Slugs of all enabled modules.
	 *
	 * @return list<string>
	 */
	public function enabledSlugs(): array {
		return array_keys( array_filter( $this->flags() ) );
	}

	/**
	 * Turn a module on.
	 *
	 * @param string $slug Module slug.
	 * @return void
	 */
	public function enable( string $slug ): void {
		$this->setFlag( $slug, true );
	}

	/**
	 * Turn a module off.
	 *
	 * @param string $slug Module slug.
	 * @return void
	 */
	public function disable( string $slug ): void {
		$this->setFlag( $slug, false );
	}

	/**
	 * Instantiate a registered module.
	 *
	 * @param string $slug Module slug.
	 * @return Module
	 * @throws \InvalidArgumentException When the slug is unknown.
	 */
	public function make( string $slug ): Module {
		$this->assertKnown( $slug );

		$module_class = $this->modules[ $slug ];

		return new $module_class();
	}

	/**
	 * Persist one flag. The option stays autoloaded (needed every request).
	 *
	 * @param string $slug    Module slug.
	 * @param bool   $enabled New flag value.
	 * @return void
	 */
	private function setFlag( string $slug, bool $enabled ): void {
		$this->assertKnown( $slug );

		$flags          = $this->flags();
		$flags[ $slug ] = $enabled;

		update_option( self::OPTION, $flags );
	}

	/**
	 * Throw when a slug is not registered.
	 *
	 * @param string $slug Module slug.
	 * @return void
	 * @throws \InvalidArgumentException When the slug is unknown.
	 */
	private function assertKnown( string $slug ): void {
		if ( ! isset( $this->modules[ $slug ] ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown Brace module slug "%s".', esc_html( $slug ) ) );
		}
	}
}
