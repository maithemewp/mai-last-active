<?php
declare(strict_types=1);

namespace Mai\LastActive;

final class Backfill {
	private const BATCH_SIZE = 500;
	public  const CRON_HOOK  = 'mai_last_active_backfill_batch';

	public function register(): void {
		add_action( self::CRON_HOOK, $this->run_batch(...) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'mai-last-active backfill', $this->cli(...) );
		}
	}

	/** Activation hook target. Idempotent. */
	public static function schedule_initial_run(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK, [ 0 ] ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK, [ 0 ] );
		}
	}

	/** Deactivation hook target. Clears any in-flight batches. */
	public static function clear_scheduled(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function run_batch( int $offset = 0 ): int {
		$user_ids = get_users( [
			'fields'  => 'ID',
			'number'  => self::BATCH_SIZE,
			'offset'  => $offset,
			'orderby' => 'ID',
			'order'   => 'ASC',
		] );

		if ( empty( $user_ids ) ) return 0;

		$touched = 0;
		foreach ( $user_ids as $user_id ) {
			if ( $this->seed_user( (int) $user_id ) ) $touched++;
		}

		// Re-schedule the next batch only when the current one was full.
		if ( count( $user_ids ) === self::BATCH_SIZE ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK, [ $offset + self::BATCH_SIZE ] );
		}

		return $touched;
	}

	public function seed_user( int $user_id ): bool {
		$seed = $this->compute_seed( $user_id );
		if ( $seed === 0 ) return false;

		// Monotonic write — never lowers the existing timestamp.
		return ActivityRecorder::touch( $user_id, $seed );
	}

	/** Returns the seed timestamp that would be written, or null if no write would happen. */
	public function would_seed( int $user_id ): ?int {
		$seed = $this->compute_seed( $user_id );
		if ( $seed === 0 ) return null;
		$existing = (int) get_user_meta( $user_id, Plugin::META_KEY, true );
		return $seed > $existing ? $seed : null;
	}

	private function compute_seed( int $user_id ): int {
		$tokens = get_user_meta( $user_id, 'session_tokens', true );
		if ( ! is_array( $tokens ) || ! $tokens ) return 0;

		$seed = 0;
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) && isset( $token['login'] ) ) {
				$login = (int) $token['login'];
				if ( $login > $seed ) $seed = $login;
			}
		}
		return $seed;
	}

	/**
	 * `wp mai-last-active backfill [--dry-run]`
	 *
	 * Synchronously walks every user, seeding mai_last_active from session_tokens.
	 * Use when WP-Cron is disabled, or when you want immediate completion after activation.
	 */
	public function cli( array $args, array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );

		$offset  = 0;
		$touched = 0;
		$total   = (int) count_users()['total_users'];
		$bar     = \WP_CLI\Utils\make_progress_bar( 'Backfilling mai_last_active', $total );

		do {
			$user_ids = get_users( [
				'fields'  => 'ID',
				'number'  => self::BATCH_SIZE,
				'offset'  => $offset,
				'orderby' => 'ID',
				'order'   => 'ASC',
			] );

			foreach ( $user_ids as $user_id ) {
				$user_id = (int) $user_id;
				if ( $dry_run ) {
					if ( $this->would_seed( $user_id ) !== null ) $touched++;
				} else {
					if ( $this->seed_user( $user_id ) ) $touched++;
				}
				$bar->tick();
			}

			$offset += self::BATCH_SIZE;
		} while ( count( $user_ids ) === self::BATCH_SIZE );

		$bar->finish();
		\WP_CLI::success( sprintf(
			$dry_run ? '%d users would be touched.' : '%d users updated.',
			$touched
		) );
	}
}
