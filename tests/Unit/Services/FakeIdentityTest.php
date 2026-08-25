<?php
/**
 * FakeIdentity unit tests.
 *
 * @package Brace
 */

namespace Brace\Tests\Unit\Services;

use Brace\Services\FakeIdentity;
use Brace\Tests\Unit\TestCase;

/**
 * The derivation must be a pure function of the entity id: same id, same
 * fake, on every run and every re-clone. These tests are the contract.
 */
final class FakeIdentityTest extends TestCase {

	public function test_email_carries_entity_and_id_for_traceback(): void {
		$identity = new FakeIdentity( 'admin@example.com' );

		$this->assertSame( 'admin+userid.42@example.com', $identity->email( 'userid', 42 ) );
		$this->assertSame( 'admin+order.1234@example.com', $identity->email( 'order', 1234 ) );
	}

	public function test_email_preserves_a_mailbox_that_already_has_a_plus(): void {
		$identity = new FakeIdentity( 'tom+shop@gmail.com' );

		$this->assertSame( 'tom+shop+userid.7@gmail.com', $identity->email( 'userid', 7 ) );
	}

	public function test_derivation_is_deterministic(): void {
		$first  = new FakeIdentity( 'admin@example.com' );
		$second = new FakeIdentity( 'admin@example.com' );

		foreach ( [ 1, 42, 999, 123456 ] as $id ) {
			$this->assertSame( $first->fullName( $id ), $second->fullName( $id ) );
			$this->assertSame( $first->email( 'userid', $id ), $second->email( 'userid', $id ) );
			$this->assertSame( $first->phone( $id ), $second->phone( $id ) );
			$this->assertSame( $first->street( $id ), $second->street( $id ) );
		}
	}

	public function test_emails_are_unique_per_id(): void {
		$identity = new FakeIdentity( 'admin@example.com' );
		$seen     = [];

		for ( $id = 1; $id <= 500; $id++ ) {
			$seen[] = $identity->email( 'userid', $id );
		}

		$this->assertCount( 500, array_unique( $seen ) );
	}

	public function test_user_and_order_entities_never_collide(): void {
		$identity = new FakeIdentity( 'admin@example.com' );

		$this->assertNotSame( $identity->email( 'userid', 42 ), $identity->email( 'order', 42 ) );
	}

	public function test_login_is_derived_from_the_id(): void {
		$identity = new FakeIdentity( 'admin@example.com' );

		$this->assertSame( 'user42', $identity->login( 42 ) );
	}

	public function test_names_vary_across_neighbouring_ids(): void {
		$identity = new FakeIdentity( 'admin@example.com' );
		$names    = [];

		for ( $id = 1; $id <= 20; $id++ ) {
			$names[] = $identity->fullName( $id );
		}

		// Consecutive ids must not walk both pools in lockstep.
		$this->assertGreaterThan( 15, count( array_unique( $names ) ) );
	}

	/**
	 * The marker is the whole point of the pool: a fake must be obvious on
	 * sight in the orders screen, and greppable in a dump. A pool of
	 * plausible names silently defeats both, and does it without failing
	 * anything, so it gets a test of its own.
	 *
	 * @return void
	 */
	public function test_every_generated_name_is_visibly_fake(): void {
		$identity = new FakeIdentity( 'admin@example.com' );

		for ( $id = 1; $id <= 200; $id++ ) {
			$this->assertMatchesRegularExpression( '/^F[A-Z][a-z]+$/', $identity->firstName( $id ) );
			$this->assertMatchesRegularExpression( '/^F[A-Z][a-z]+$/', $identity->lastName( $id ) );
			$this->assertMatchesRegularExpression( '/^F[A-Z][a-z]+ F[A-Z][a-z]+$/', $identity->fullName( $id ) );
		}
	}

	/**
	 * Names must be ASCII. A staging dump gets grepped, diffed and pasted
	 * into tools with mixed encoding handling; accented fakes were part of
	 * what made the old pool read as real data.
	 *
	 * @return void
	 */
	public function test_names_are_plain_ascii(): void {
		$identity = new FakeIdentity( 'admin@example.com' );

		for ( $id = 1; $id <= 200; $id++ ) {
			$this->assertSame( $identity->fullName( $id ), (string) preg_replace( '/[^\x20-\x7E]/', '', $identity->fullName( $id ) ) );
		}
	}

	public function test_phone_keeps_a_valid_shape(): void {
		$identity = new FakeIdentity( 'admin@example.com' );

		$this->assertSame( '700000042', $identity->phone( 42 ) );
		$this->assertMatchesRegularExpression( '/^\d{9}$/', $identity->phone( 987654321 ) );
	}

	public function test_street_carries_the_id(): void {
		$identity = new FakeIdentity( 'admin@example.com' );

		$this->assertSame( 'Testovaci 42', $identity->street( 42 ) );
	}

	public function test_ip_and_user_agent_are_neutral(): void {
		$identity = new FakeIdentity( 'admin@example.com' );

		$this->assertSame( '127.0.0.1', $identity->ip() );
		$this->assertStringContainsString( 'Brace', $identity->userAgent() );
	}

	/**
	 * @dataProvider invalidMailboxes
	 */
	public function test_invalid_mailbox_is_rejected( string $mailbox ): void {
		$this->expectException( \InvalidArgumentException::class );

		new FakeIdentity( $mailbox );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function invalidMailboxes(): array {
		return [
			'no at sign'    => [ 'admin.example.com' ],
			'empty local'   => [ '@example.com' ],
			'empty domain'  => [ 'admin@' ],
			'empty string'  => [ '' ],
		];
	}
}
