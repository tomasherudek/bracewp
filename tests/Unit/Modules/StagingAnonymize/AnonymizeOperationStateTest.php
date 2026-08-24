<?php
/**
 * Tests for the resumable state of the anonymization stage machine.
 *
 * @package Brace
 */

namespace Brace\Tests\Unit\Modules\StagingAnonymize;

use Brace\Modules\StagingAnonymize\AnonymizeOperation;
use Brace\Services\Batch;
use Brace\Services\FakeIdentity;
use Brace\Tests\Unit\TestCase;

/**
 * A browser-driven run ticks through admin-ajax, so every tick is a separate
 * request with a freshly constructed operation. The only thing carrying the
 * run forward is state()/restore(). If it stops round-tripping, a run does
 * not fail loudly — it silently restarts at stage zero on every tick and
 * never terminates, so these are the tests that keep that from shipping.
 *
 * No database is touched here: state() and restore() are pure bookkeeping.
 */
final class AnonymizeOperationStateTest extends TestCase {

	/**
	 * Build an operation with a throwaway identity.
	 *
	 * @return AnonymizeOperation
	 */
	private function operation(): AnonymizeOperation {
		return new AnonymizeOperation( new FakeIdentity( 'admin@example.com' ), [ 'administrator' ], 200 );
	}

	/**
	 * A fresh operation starts at the first stage with nothing done.
	 *
	 * @return void
	 */
	public function test_starts_at_the_first_stage(): void {
		$state = $this->operation()->state();

		$this->assertSame( 0, $state['stage'] );
		$this->assertSame( 0, $state['cursor'] );
		$this->assertFalse( $state['prepared'] );
		$this->assertSame( [], $state['changed'] );
		$this->assertNull( $state['hash'] );
	}

	/**
	 * State survives the trip through a second object, which is what one
	 * AJAX tick handing off to the next actually is.
	 *
	 * @return void
	 */
	public function test_state_round_trips_through_a_new_instance(): void {
		$saved = [
			'stage'    => 3,
			'cursor'   => 4821,
			'prepared' => true,
			'changed'  => [
				'users'       => 1200,
				'orders_hpos' => 340,
			],
			'hash'     => '$P$Bexamplehashvalue',
		];

		$resumed = $this->operation();
		$resumed->restore( $saved );

		$this->assertSame( $saved, $resumed->state() );
	}

	/**
	 * The password hash must travel with the run. It is generated once, and
	 * a resumed run that re-generated it would leave the user table split
	 * across two different passwords.
	 *
	 * @return void
	 */
	public function test_password_hash_is_carried_across_ticks(): void {
		$resumed = $this->operation();
		$resumed->restore( [ 'hash' => '$P$Boriginalhash' ] );

		$this->assertSame( '$P$Boriginalhash', $resumed->state()['hash'] );
	}

	/**
	 * Restoring reports the stage the run was interrupted in, so the UI can
	 * name it rather than starting its progress display from scratch.
	 *
	 * @return void
	 */
	public function test_restored_run_reports_its_current_stage(): void {
		$resumed = $this->operation();
		$resumed->restore( [ 'stage' => 1 ] );

		$this->assertSame( 'users', $resumed->currentStage() );
		$this->assertFalse( $resumed->finished() );
	}

	/**
	 * A state at or past the last stage means the run is over.
	 *
	 * The literal is the number of stages; adding a stage moves it.
	 *
	 * @return void
	 */
	public function test_restoring_a_completed_state_reports_finished(): void {
		$resumed = $this->operation();
		$resumed->restore( [ 'stage' => 11 ] );

		$this->assertTrue( $resumed->finished() );
		$this->assertSame( 'done', $resumed->currentStage() );
	}

	/**
	 * A finished run does not flush again on the next tick.
	 *
	 * The flush is what makes an anonymized copy actually *look* anonymized:
	 * the stages write through $wpdb, so a persistent object cache keeps
	 * serving the old rows until something drops them. A browser-driven run
	 * keeps ticking a finished operation, and the early return in execute()
	 * is the only thing keeping that from flushing the cache on every poll.
	 * Without it, a site-wide flush fires in a loop.
	 *
	 * @return void
	 */
	public function test_a_finished_run_does_not_flush_again(): void {
		\Brain\Monkey\Functions\expect( 'wp_cache_flush' )->never();

		$resumed = $this->operation();
		$resumed->restore( [ 'stage' => 11 ] );

		$resumed->execute( new Batch() );

		$this->assertTrue( $resumed->finished() );
	}

	/**
	 * The stored state is an option: it round-trips through the database and
	 * can come back corrupted, truncated, or hand-edited. Restoring must
	 * clamp rather than trust, because an out-of-range stage index would
	 * fatal the match() in execute() instead of just misbehaving.
	 *
	 * @param mixed $stage    The stored stage value.
	 * @param int   $expected The clamped result.
	 * @return void
	 *
	 * @dataProvider provideUntrustworthyStages
	 */
	public function test_restore_clamps_an_out_of_range_stage( $stage, int $expected ): void {
		$resumed = $this->operation();
		$resumed->restore( [ 'stage' => $stage ] );

		$this->assertSame( $expected, $resumed->state()['stage'] );
	}

	/**
	 * Stage values a corrupted option could plausibly hold.
	 *
	 * @return array<string, array{mixed, int}>
	 */
	public static function provideUntrustworthyStages(): array {
		return [
			'negative'      => [ -5, 0 ],
			'past the end'  => [ 999, 11 ],
			'not a number'  => [ 'users', 0 ],
			'null'          => [ null, 0 ],
			'floating'      => [ 2.9, 2 ],
		];
	}

	/**
	 * A missing or malformed state must not throw: an empty option is the
	 * normal case for the very first tick.
	 *
	 * @return void
	 */
	public function test_restore_tolerates_an_empty_state(): void {
		$resumed = $this->operation();
		$resumed->restore( [] );

		$this->assertSame( 0, $resumed->state()['stage'] );
		$this->assertSame( [], $resumed->state()['changed'] );
	}

	/**
	 * A negative cursor would re-process rows already handled; clamping to
	 * zero is the safe direction since the operation is idempotent.
	 *
	 * @return void
	 */
	public function test_restore_clamps_a_negative_cursor(): void {
		$resumed = $this->operation();
		$resumed->restore( [ 'cursor' => -42 ] );

		$this->assertSame( 0, $resumed->state()['cursor'] );
	}
}
