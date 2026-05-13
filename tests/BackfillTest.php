<?php
declare(strict_types=1);

namespace Mai\LastActive\Tests;

use Mai\LastActive\Backfill;
use Mai\LastActive\Plugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( Backfill::class )]
final class BackfillTest extends TestCase {

	protected function setUp(): void {
		wp_test_reset();
	}

	public function test_register_adds_cron_hook(): void {
		( new Backfill() )->register();
		$this->assertCount( 1, wp_test_hooks( Backfill::CRON_HOOK ) );
	}

	public function test_schedule_initial_run_schedules_offset_zero(): void {
		Backfill::schedule_initial_run();
		$events = wp_test_scheduled();
		$this->assertCount( 1, $events );
		$this->assertSame( Backfill::CRON_HOOK, $events[0]['hook'] );
		$this->assertSame( [ 0 ], $events[0]['args'] );
	}

	public function test_schedule_initial_run_is_idempotent(): void {
		Backfill::schedule_initial_run();
		Backfill::schedule_initial_run();
		Backfill::schedule_initial_run();
		$this->assertCount( 1, wp_test_scheduled() );
	}

	public function test_clear_scheduled_removes_pending_events(): void {
		wp_schedule_single_event( time() + 5, Backfill::CRON_HOOK, [ 0 ] );
		wp_schedule_single_event( time() + 5, Backfill::CRON_HOOK, [ 500 ] );

		Backfill::clear_scheduled();

		$this->assertSame( [], wp_test_scheduled() );
		$this->assertContains( Backfill::CRON_HOOK, wp_test_cleared_hooks() );
	}

	public function test_seed_user_returns_false_when_no_session_tokens(): void {
		$this->assertFalse( ( new Backfill() )->seed_user( 1 ) );
	}

	public function test_seed_user_picks_max_login_from_session_tokens(): void {
		update_user_meta( 1, 'session_tokens', [
			'a' => [ 'login' => 1000, 'ip' => 'x' ],
			'b' => [ 'login' => 5000, 'ip' => 'y' ],
			'c' => [ 'login' => 2000, 'ip' => 'z' ],
		] );

		$wrote = ( new Backfill() )->seed_user( 1 );
		$this->assertTrue( $wrote );
		$this->assertSame( 5000, wp_test_get_user_meta_raw( 1, Plugin::META_KEY ) );
	}

	public function test_seed_user_handles_malformed_token_entries(): void {
		update_user_meta( 1, 'session_tokens', [
			'a' => 'not-an-array',
			'b' => [ 'expiration' => 5000 ],   // missing login
			'c' => [ 'login' => 3000 ],
		] );

		$wrote = ( new Backfill() )->seed_user( 1 );
		$this->assertTrue( $wrote );
		$this->assertSame( 3000, wp_test_get_user_meta_raw( 1, Plugin::META_KEY ) );
	}

	public function test_seed_user_handles_empty_session_tokens_array(): void {
		update_user_meta( 1, 'session_tokens', [] );
		$this->assertFalse( ( new Backfill() )->seed_user( 1 ) );
	}

	public function test_seed_user_respects_monotonic_guard(): void {
		update_user_meta( 1, Plugin::META_KEY, 9000 );
		update_user_meta( 1, 'session_tokens', [
			'a' => [ 'login' => 5000 ],   // older than existing
		] );

		$this->assertFalse( ( new Backfill() )->seed_user( 1 ) );
		$this->assertSame( 9000, wp_test_get_user_meta_raw( 1, Plugin::META_KEY ) );
	}

	public function test_would_seed_returns_null_when_no_write(): void {
		update_user_meta( 1, Plugin::META_KEY, 9000 );
		update_user_meta( 1, 'session_tokens', [ 'a' => [ 'login' => 5000 ] ] );

		$this->assertNull( ( new Backfill() )->would_seed( 1 ) );
	}

	public function test_would_seed_returns_timestamp_when_write_would_happen(): void {
		update_user_meta( 1, 'session_tokens', [ 'a' => [ 'login' => 5000 ] ] );
		$this->assertSame( 5000, ( new Backfill() )->would_seed( 1 ) );
	}

	public function test_run_batch_reschedules_when_full(): void {
		// Stub 600 users in the store; first batch returns 500.
		wp_test_set_users( range( 1, 600 ) );

		( new Backfill() )->run_batch( 0 );

		$events = wp_test_scheduled();
		$this->assertCount( 1, $events );
		$this->assertSame( [ 500 ], $events[0]['args'] );
	}

	public function test_run_batch_does_not_reschedule_when_partial(): void {
		wp_test_set_users( range( 1, 200 ) );   // less than BATCH_SIZE (500)
		( new Backfill() )->run_batch( 0 );
		$this->assertSame( [], wp_test_scheduled() );
	}

	public function test_run_batch_returns_zero_when_no_users(): void {
		$result = ( new Backfill() )->run_batch( 999 );
		$this->assertSame( 0, $result );
		$this->assertSame( [], wp_test_scheduled() );
	}

	public function test_run_batch_counts_touched_users(): void {
		wp_test_set_users( [ 1, 2, 3 ] );
		update_user_meta( 1, 'session_tokens', [ 'a' => [ 'login' => 5000 ] ] );
		update_user_meta( 2, 'session_tokens', [ 'a' => [ 'login' => 6000 ] ] );
		// user 3 has no session_tokens

		$touched = ( new Backfill() )->run_batch( 0 );
		$this->assertSame( 2, $touched );
	}
}
