<?php
/**
 * Per-module settings storage.
 *
 * @package Brace
 */

namespace Brace\Core;

/**
 * Stores each module's settings in its own brace_settings_<module> option
 * with autoload off: a module's settings hit the database only when that
 * module boots. Practicing the DB hygiene we preach.
 */
final class Settings {

	/**
	 * Option name for a module's settings.
	 *
	 * @param string $module Module slug.
	 * @return string
	 */
	public function optionName( string $module ): string {
		return 'brace_settings_' . $module;
	}

	/**
	 * All settings of one module.
	 *
	 * @param string $module Module slug.
	 * @return array<string, mixed>
	 */
	public function all( string $module ): array {
		$stored = get_option( $this->optionName( $module ), [] );

		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * One setting value.
	 *
	 * @param string $module        Module slug.
	 * @param string $key           Setting key.
	 * @param mixed  $default_value Fallback when the key is not stored.
	 * @return mixed
	 */
	public function get( string $module, string $key, $default_value = null ) {
		$all = $this->all( $module );

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default_value;
	}

	/**
	 * Store one setting value. The option is created with autoload off.
	 *
	 * @param string $module Module slug.
	 * @param string $key    Setting key.
	 * @param mixed  $value  Value to store.
	 * @return void
	 */
	public function set( string $module, string $key, $value ): void {
		$all         = $this->all( $module );
		$all[ $key ] = $value;

		update_option( $this->optionName( $module ), $all, false );
	}

	/**
	 * Remove a module's whole settings option.
	 *
	 * @param string $module Module slug.
	 * @return void
	 */
	public function delete( string $module ): void {
		delete_option( $this->optionName( $module ) );
	}
}
