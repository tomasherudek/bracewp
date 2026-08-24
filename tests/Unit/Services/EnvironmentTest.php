<?php
/**
 * Environment unit tests.
 *
 * @package Brace
 */

namespace Brace\Tests\Unit\Services;

use Brace\Services\Environment;
use Brace\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * The verdict decides whether a destructive module may run at all, so the
 * asymmetry matters: reading production as staging is the dangerous
 * direction, and every ambiguous case must fall to "production".
 */
final class EnvironmentTest extends TestCase {

	/**
	 * Stub the WP functions the service calls.
	 *
	 * @param string $home_url         What home_url() returns.
	 * @param string $environment_type What wp_get_environment_type() returns.
	 * @return void
	 */
	private function stubWp( string $home_url, string $environment_type = 'production' ): void {
		Functions\when( 'home_url' )->justReturn( $home_url );
		Functions\when( 'wp_get_environment_type' )->justReturn( $environment_type );
		Functions\when( 'wp_parse_url' )->alias(
			static fn( $url, $component = -1 ) => parse_url( $url, $component )
		);
	}

	public function test_production_domain_reads_as_production(): void {
		$this->stubWp( 'https://eshop.cz' );

		$environment = new Environment();

		$this->assertFalse( $environment->isStaging() );
		$this->assertNull( $environment->stagingReason() );
	}

	public function test_subdomain_alone_is_not_enough_to_read_as_staging(): void {
		// A bare subdomain mismatch is 001-staging-guard's job. Until the
		// hashed baseline exists, this must NOT silently read as staging.
		$this->stubWp( 'https://staging.eshop.cz' );

		$this->assertFalse( ( new Environment() )->isStaging() );
	}

	public function test_environment_type_staging_reads_as_staging(): void {
		$this->stubWp( 'https://staging.eshop.cz', 'staging' );

		$environment = new Environment();

		$this->assertTrue( $environment->isStaging() );
		$this->assertStringContainsString( 'staging', (string) $environment->stagingReason() );
	}

	public function test_environment_type_local_reads_as_staging(): void {
		$this->stubWp( 'https://eshop.cz', 'local' );

		$this->assertTrue( ( new Environment() )->isStaging() );
	}

	/**
	 * @dataProvider nonPublicHosts
	 */
	public function test_non_public_host_reads_as_staging( string $url ): void {
		$this->stubWp( $url );

		$this->assertTrue( ( new Environment() )->isStaging(), $url . ' should read as staging' );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function nonPublicHosts(): array {
		return [
			'localhost'  => [ 'http://localhost' ],
			'ip literal' => [ 'http://192.168.1.10' ],
			'test tld'   => [ 'http://eshop.test' ],
			'local tld'  => [ 'http://eshop.local' ],
			'single label' => [ 'http://devbox' ],
		];
	}

	public function test_host_is_normalized(): void {
		$this->stubWp( 'https://WWW.EshopSHOP.cz' );

		$this->assertSame( 'eshopshop.cz', ( new Environment() )->host() );
	}
}
