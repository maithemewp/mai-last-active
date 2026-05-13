<?php
declare(strict_types=1);

namespace Mai\LastActive;

final class ActivityRecorder {
	public function __construct( private readonly int $throttle ) {}

	public function register(): void {
		add_action( 'wp_login', $this->on_login(...), 10, 2 );
		add_action( 'shutdown', $this->on_shutdown(...) );
	}

	private function on_login( string $user_login, \WP_User $user ): void {
		self::touch( $user->ID, time() );
	}

	private function on_shutdown(): void {
		static $ran = false;
		if ( $ran ) return;
		$ran = true;

		if ( ! is_user_logged_in() )         return;
		if ( wp_doing_cron() )               return;
		if ( defined( 'WP_CLI' ) && WP_CLI ) return;
		if ( $this->is_user_switched() )     return;
		if ( ! apply_filters( 'mai_last_active_should_record', true ) ) return;

		$user_id = get_current_user_id();
		$last    = (int) get_user_meta( $user_id, Plugin::META_KEY, true );
		if ( time() - $last < $this->throttle ) return;

		self::touch( $user_id, time() );
	}

	/**
	 * Monotonic writer — never lowers the existing timestamp.
	 * Shared by login, shutdown, and backfill. Static because it has no state.
	 */
	public static function touch( int $user_id, int $timestamp ): bool {
		$current = (int) get_user_meta( $user_id, Plugin::META_KEY, true );
		if ( $timestamp <= $current ) return false;
		update_user_meta( $user_id, Plugin::META_KEY, $timestamp );
		return true;
	}

	private function is_user_switched(): bool {
		return class_exists( '\user_switching' )
			&& \user_switching::get_old_user() instanceof \WP_User;
	}
}
