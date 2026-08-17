<?php
/**
 * ModuleRegistry unit tests.
 *
 * @package Brace
 */

namespace Brace\Tests\Unit\Core;

use Brace\Core\Module;
use Brace\Core\ModuleRegistry;
use Brace\Tests\Unit\Fixture\FakeModule;
use Brace\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Register, enable, disable, and flag behavior of the registry.
 */
final class ModuleRegistryTest extends TestCase {

	/**
	 * Registry under test, with the fake module registered.
	 *
	 * @var ModuleRegistry
	 */
	private ModuleRegistry $registry;

	/**
	 * Build a registry with one fake module.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->registry = new ModuleRegistry();
		$this->registry->register( 'fake', FakeModule::class );
	}

	public function test_register_and_has(): void {
		$this->assertTrue( $this->registry->has( 'fake' ) );
		$this->assertFalse( $this->registry->has( 'ghost' ) );
	}

	public function test_all_returns_slug_to_class_map(): void {
		$this->assertSame( [ 'fake' => FakeModule::class ], $this->registry->all() );
	}

	public function test_register_duplicate_slug_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->registry->register( 'fake', FakeModule::class );
	}

	public function test_register_non_module_class_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->registry->register( 'bad', \stdClass::class );
	}

	public function test_flags_default_to_disabled(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$this->assertSame( [ 'fake' => false ], $this->registry->flags() );
		$this->assertFalse( $this->registry->isEnabled( 'fake' ) );
	}

	public function test_flags_read_the_stored_option(): void {
		Functions\when( 'get_option' )->justReturn( [ 'fake' => true ] );

		$this->assertSame( [ 'fake' => true ], $this->registry->flags() );
		$this->assertTrue( $this->registry->isEnabled( 'fake' ) );
	}

	public function test_flags_ignore_unregistered_slugs(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'fake'  => true,
				'ghost' => true,
			]
		);

		$this->assertSame( [ 'fake' => true ], $this->registry->flags() );
	}

	public function test_flags_cast_stored_values_to_bool(): void {
		Functions\when( 'get_option' )->justReturn( [ 'fake' => '1' ] );

		$this->assertSame( [ 'fake' => true ], $this->registry->flags() );
	}

	public function test_enable_writes_the_option(): void {
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\expect( 'update_option' )
			->once()
			->with( ModuleRegistry::OPTION, [ 'fake' => true ] )
			->andReturn( true );

		$this->registry->enable( 'fake' );
	}

	public function test_disable_writes_the_option(): void {
		Functions\when( 'get_option' )->justReturn( [ 'fake' => true ] );
		Functions\expect( 'update_option' )
			->once()
			->with( ModuleRegistry::OPTION, [ 'fake' => false ] )
			->andReturn( true );

		$this->registry->disable( 'fake' );
	}

	public function test_enable_unknown_slug_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->registry->enable( 'ghost' );
	}

	public function test_enabled_slugs_lists_only_enabled_modules(): void {
		Functions\when( 'get_option' )->justReturn( [ 'fake' => false ] );

		$this->assertSame( [], $this->registry->enabledSlugs() );

		Functions\when( 'get_option' )->justReturn( [ 'fake' => true ] );

		$this->assertSame( [ 'fake' ], $this->registry->enabledSlugs() );
	}

	public function test_make_returns_a_module_instance(): void {
		$module = $this->registry->make( 'fake' );

		$this->assertInstanceOf( Module::class, $module );
		$this->assertSame( 'fake', $module->slug() );
	}

	public function test_make_unknown_slug_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->registry->make( 'ghost' );
	}
}
