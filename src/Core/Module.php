<?php
/**
 * The module contract.
 *
 * @package Brace
 */

namespace Brace\Core;

/**
 * Contract every Brace module implements.
 *
 * Isolation rules: modules may depend on Core and Services, never on each
 * other. Shared behavior gets promoted to a Service. A disabled module is
 * inert code on disk: its class is never autoloaded.
 */
interface Module {

	/**
	 * Unique module slug, used as the flag key in the brace_modules option.
	 *
	 * @return string
	 */
	public function slug(): string;

	/**
	 * Human readable title shown in the admin.
	 *
	 * @return string
	 */
	public function title(): string;

	/**
	 * One or two sentences describing what the module does.
	 *
	 * @return string
	 */
	public function description(): string;

	/**
	 * Where it needs to load; core skips the rest.
	 *
	 * @return list<Context>
	 */
	public function contexts(): array;

	/**
	 * The server capabilities this module needs to run.
	 *
	 * @return Requirements
	 */
	public function requirements(): Requirements;

	/**
	 * Register hooks; called ONLY when the module is enabled.
	 *
	 * @return void
	 */
	public function boot(): void;

	/**
	 * Runs on toggle-on (setup: options, schedules).
	 *
	 * @return void
	 */
	public function activate(): void;

	/**
	 * Runs on toggle-off (teardown schedules etc.).
	 *
	 * @return void
	 */
	public function deactivate(): void;

	/**
	 * Remove every trace; called for ALL modules on plugin uninstall,
	 * enabled or not.
	 *
	 * @return void
	 */
	public function uninstall(): void;

	/**
	 * Renders the module's admin settings section, or null when the module
	 * has no settings surface.
	 *
	 * @return ?callable
	 */
	public function settingsView(): ?callable;
}
