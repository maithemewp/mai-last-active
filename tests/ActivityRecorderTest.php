<?php
declare(strict_types=1);

namespace Mai\LastActive\Tests;

use Mai\LastActive\ActivityRecorder;
use Mai\LastActive\Plugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

#[CoversClass( ActivityRecorder::class )]
final class ActivityRecorderTest extends TestCase {

	protected function setUp(): void {
		wp_test_reset();
		// Reset the `static $ran` flag inside on_shutdown between tests.
		$this->reset_static_ran();
	}

	private function recorder( int $throttle = 4 * HOUR_IN_SECONDS ): ActivityRecorder {
		return new ActivityRecorder( $throttle );
	}

	private function invoke_shutdown( ActivityRecorder $r ): void {
		$ref = new ReflectionMethod( $r, 'on_shutdown' );
		$ref->setAccessible( true );
		$ref->invoke( $r );
	}

	private function invoke_login( ActivityRecorder $r, int $user_id ): void {
		$ref = new ReflectionMethod( $r, 'on_login' );
		$ref->setAccessible( true );
		$ref->invoke( $r, 'someuser', new \WP_User( $user_id, 'someuser' ) );
	}

	private function reset_static_ran(): void {
		// No-op — $ran is now an instance property, so each new recorder
		// starts fresh. Kept as a hook in case future tests need it.
	}

	public function test_register_adds_hooks(): void {
		$this->recorder()->register();

		$login    = wp_test_hooks( 'wp_login' );
		$shutdown = wp_test_hooks( 'shutdown' );

		$this->assertCount( 1, $login );
		$this->assertSame( 10, $login[0]['priority'] );
		$this->assertCount( 1, $shutdown );
	}

	public function test_on_login_writes_current_time(): void {
		$this->invoke_login( $this->recorder(), 42 );
		$value = (int) wp_test_get_user_meta_raw( 42, Plugin::META_KEY );
		$this->assertGreaterThan( time() - 5, $value );
	}

	public function test_touch_is_monotonic_does_not_lower(): void {
		update_user_meta( 7, Plugin::META_KEY, 5_000 );
		$wrote = ActivityRecorder::touch( 7, 1_000 );
		$this->assertFalse( $wrote );
		$this->assertSame( 5_000, wp_test_get_user_meta_raw( 7, Plugin::META_KEY ) );
	}

	public function test_touch_is_monotonic_writes_higher(): void {
		update_user_meta( 7, Plugin::META_KEY, 5_000 );
		$wrote = ActivityRecorder::touch( 7, 10_000 );
		$this->assertTrue( $wrote );
		$this->assertSame( 10_000, wp_test_get_user_meta_raw( 7, Plugin::META_KEY ) );
	}

	public function test_touch_equal_does_not_write(): void {
		update_user_meta( 7, Plugin::META_KEY, 5_000 );
		$this->assertFalse( ActivityRecorder::touch( 7, 5_000 ) );
	}

	public function test_shutdown_bails_when_logged_out(): void {
		wp_test_set_logged_in( false );
		$this->invoke_shutdown( $this->recorder() );
		$this->assertNull( wp_test_get_user_meta_raw( 1, Plugin::META_KEY ) );
	}

	public function test_shutdown_bails_during_cron(): void {
		wp_test_set_logged_in( true );
		wp_test_set_current_user_id( 1 );
		wp_test_set_doing_cron( true );
		$this->invoke_shutdown( $this->recorder() );
		$this->assertNull( wp_test_get_user_meta_raw( 1, Plugin::META_KEY ) );
	}

	public function test_shutdown_bails_when_switched(): void {
		wp_test_set_logged_in( true );
		wp_test_set_current_user_id( 1 );
		wp_test_set_user_switched( true );
		$this->invoke_shutdown( $this->recorder() );
		$this->assertNull( wp_test_get_user_meta_raw( 1, Plugin::META_KEY ) );
	}

	public function test_shutdown_bails_when_filter_returns_false(): void {
		wp_test_set_logged_in( true );
		wp_test_set_current_user_id( 1 );
		wp_test_set_filter( 'mai_last_active_should_record', static fn() => false );
		$this->invoke_shutdown( $this->recorder() );
		$this->assertNull( wp_test_get_user_meta_raw( 1, Plugin::META_KEY ) );
	}

	public function test_shutdown_bails_within_throttle(): void {
		wp_test_set_logged_in( true );
		wp_test_set_current_user_id( 1 );
		update_user_meta( 1, Plugin::META_KEY, time() - 60 );   // 1 minute ago
		$this->invoke_shutdown( $this->recorder( HOUR_IN_SECONDS ) );
		// Value unchanged — still within the 1h throttle.
		$this->assertSame( time() - 60, wp_test_get_user_meta_raw( 1, Plugin::META_KEY ) );
	}

	public function test_shutdown_writes_past_throttle(): void {
		wp_test_set_logged_in( true );
		wp_test_set_current_user_id( 1 );
		update_user_meta( 1, Plugin::META_KEY, time() - 2 * HOUR_IN_SECONDS );   // 2 hours ago
		$this->invoke_shutdown( $this->recorder( HOUR_IN_SECONDS ) );
		$this->assertGreaterThan( time() - 5, (int) wp_test_get_user_meta_raw( 1, Plugin::META_KEY ) );
	}

	public function test_shutdown_ran_flag_prevents_double_write(): void {
		wp_test_set_logged_in( true );
		wp_test_set_current_user_id( 1 );
		$recorder = $this->recorder( HOUR_IN_SECONDS );

		// First call writes.
		$this->invoke_shutdown( $recorder );
		$first = (int) wp_test_get_user_meta_raw( 1, Plugin::META_KEY );
		$this->assertGreaterThan( 0, $first );

		// Reset the meta to a low value to detect any second write.
		update_user_meta( 1, Plugin::META_KEY, 1 );

		// Second call on the SAME recorder bails because $ran is now true.
		$this->invoke_shutdown( $recorder );
		$this->assertSame( 1, wp_test_get_user_meta_raw( 1, Plugin::META_KEY ) );
	}
}
