<?php
/**
 * Requirements unit tests.
 *
 * @package Brace
 */

namespace Brace\Tests\Unit\Core;

use Brace\Core\Requirements;
use Brace\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Requirement declarations and their checks.
 */
final class RequirementsTest extends TestCase {

	public function test_none_is_satisfied(): void {
		$requirements = Requirements::none();

		$this->assertTrue( $requirements->satisfied() );
		$this->assertSame( [], $requirements->unmet() );
	}

	public function test_missing_php_extension_is_unmet(): void {
		$requirements = Requirements::none()->phpExtension( 'brace_nonexistent_extension' );

		$this->assertFalse( $requirements->satisfied() );
		$this->assertCount( 1, $requirements->unmet() );
		$this->assertStringContainsString( 'brace_nonexistent_extension', $requirements->unmet()[0] );
	}

	public function test_loaded_php_extension_is_satisfied(): void {
		$requirements = Requirements::none()->phpExtension( 'spl' );

		$this->assertTrue( $requirements->satisfied() );
	}

	public function test_wp_version_satisfied(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8' );

		$this->assertTrue( Requirements::none()->wpVersion( '6.7' )->satisfied() );
	}

	public function test_wp_version_unmet(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );

		$requirements = Requirements::none()->wpVersion( '6.7' );

		$this->assertFalse( $requirements->satisfied() );
		$this->assertStringContainsString( '6.7', $requirements->unmet()[0] );
	}

	public function test_writable_path_unmet(): void {
		Functions\when( 'wp_is_writable' )->justReturn( false );

		$requirements = Requirements::none()->writablePath( '/some/path' );

		$this->assertFalse( $requirements->satisfied() );
		$this->assertStringContainsString( '/some/path', $requirements->unmet()[0] );
	}

	public function test_writable_path_satisfied(): void {
		Functions\when( 'wp_is_writable' )->justReturn( true );

		$this->assertTrue( Requirements::none()->writablePath( '/some/path' )->satisfied() );
	}

	public function test_tiny_memory_requirement_is_satisfied(): void {
		$this->assertTrue( Requirements::none()->memory( 1 )->satisfied() );
	}

	public function test_binary_on_path_is_satisfied(): void {
		$this->assertTrue( Requirements::none()->binary( 'sh' )->satisfied() );
	}

	public function test_missing_binary_is_unmet(): void {
		$requirements = Requirements::none()->binary( 'brace-nonexistent-binary' );

		$this->assertFalse( $requirements->satisfied() );
		$this->assertStringContainsString( 'brace-nonexistent-binary', $requirements->unmet()[0] );
	}

	public function test_multisite_unmet_on_single_site(): void {
		Functions\when( 'is_multisite' )->justReturn( false );

		$this->assertFalse( Requirements::none()->multisite()->satisfied() );
	}

	public function test_multisite_satisfied_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );

		$this->assertTrue( Requirements::none()->multisite()->satisfied() );
	}

	public function test_requirements_chain_and_collect_all_failures(): void {
		Functions\when( 'is_multisite' )->justReturn( false );

		$requirements = Requirements::none()
			->phpExtension( 'brace_nonexistent_extension' )
			->phpExtension( 'spl' )
			->multisite();

		$this->assertCount( 2, $requirements->unmet() );
	}

	public function test_bytes_parses_ini_shorthand(): void {
		$this->assertSame( -1, Requirements::bytes( '-1' ) );
		$this->assertSame( -1, Requirements::bytes( '' ) );
		$this->assertSame( 512, Requirements::bytes( '512' ) );
		$this->assertSame( 64 * 1024, Requirements::bytes( '64K' ) );
		$this->assertSame( 256 * 1024 * 1024, Requirements::bytes( '256M' ) );
		$this->assertSame( 1024 * 1024 * 1024, Requirements::bytes( '1G' ) );
		$this->assertSame( 2 * 1024 * 1024 * 1024, Requirements::bytes( '2g' ) );
	}
}
