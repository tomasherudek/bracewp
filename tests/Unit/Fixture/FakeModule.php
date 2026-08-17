<?php
/**
 * Test double module.
 *
 * @package Brace
 */

namespace Brace\Tests\Unit\Fixture;

use Brace\Core\Context;
use Brace\Core\Module;
use Brace\Core\Requirements;

/**
 * Minimal Module implementation for registry tests.
 */
final class FakeModule implements Module {

	/**
	 * Slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'fake';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function title(): string {
		return 'Fake Module';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description(): string {
		return 'A test double.';
	}

	/**
	 * Contexts.
	 *
	 * @return list<Context>
	 */
	public function contexts(): array {
		return [ Context::Admin ];
	}

	/**
	 * Requirements.
	 *
	 * @return Requirements
	 */
	public function requirements(): Requirements {
		return Requirements::none();
	}

	/**
	 * Boot. No-op.
	 *
	 * @return void
	 */
	public function boot(): void {
	}

	/**
	 * Activate. No-op.
	 *
	 * @return void
	 */
	public function activate(): void {
	}

	/**
	 * Deactivate. No-op.
	 *
	 * @return void
	 */
	public function deactivate(): void {
	}

	/**
	 * Uninstall. No-op.
	 *
	 * @return void
	 */
	public function uninstall(): void {
	}

	/**
	 * Settings view.
	 *
	 * @return ?callable
	 */
	public function settingsView(): ?callable {
		return null;
	}
}
