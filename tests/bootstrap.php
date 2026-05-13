<?php
/**
 * PHPUnit bootstrap.
 *
 * Stubs only the WP surface used by the units under test. No DB, no HTTP, no
 * WP core. Each test must call `wp_test_reset()` in `setUp()` to clear state.
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// --- WordPress time constants ----------------------------------------------

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) define( 'MINUTE_IN_SECONDS', 60 );
if ( ! defined( 'HOUR_IN_SECONDS' ) )   define( 'HOUR_IN_SECONDS',   60 * MINUTE_IN_SECONDS );
if ( ! defined( 'DAY_IN_SECONDS' ) )    define( 'DAY_IN_SECONDS',    24 * HOUR_IN_SECONDS );
if ( ! defined( 'WEEK_IN_SECONDS' ) )   define( 'WEEK_IN_SECONDS',    7 * DAY_IN_SECONDS );
if ( ! defined( 'MONTH_IN_SECONDS' ) )  define( 'MONTH_IN_SECONDS',  30 * DAY_IN_SECONDS );
if ( ! defined( 'YEAR_IN_SECONDS' ) )   define( 'YEAR_IN_SECONDS',  365 * DAY_IN_SECONDS );

// --- In-memory state stores -------------------------------------------------

global $wp_test_state;

function wp_test_reset(): void {
	global $wp_test_state;
	$wp_test_state = [
		'user_meta'        => [],   // [user_id][key] = value (single)
		'filters'          => [],   // [name] = callable returning value
		'hooks'            => [],   // [hook][priority][] = ['fn'=>callable,'args'=>int]
		'scheduled'        => [],   // sequence of ['ts'=>int,'hook'=>string,'args'=>array]
		'cleared_hooks'    => [],   // list of hook names cleared via wp_clear_scheduled_hook
		'users'            => [],   // list of user IDs returned by get_users (filterable by test)
		'is_logged_in'     => false,
		'current_user_id'  => 0,
		'doing_cron'       => false,
		'is_switched'      => false,
		'options'          => [
			'date_format' => 'F j, Y',
			'time_format' => 'g:i a',
		],
	];
}

wp_test_reset();

// --- Stub WP functions ------------------------------------------------------

function add_action( string $hook, callable $fn, int $priority = 10, int $args = 1 ): bool {
	global $wp_test_state;
	$wp_test_state['hooks'][ $hook ][ $priority ][] = [ 'fn' => $fn, 'args' => $args ];
	return true;
}

function add_filter( string $hook, callable $fn, int $priority = 10, int $args = 1 ): bool {
	return add_action( $hook, $fn, $priority, $args );
}

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	global $wp_test_state;
	if ( isset( $wp_test_state['filters'][ $hook ] ) ) {
		return ( $wp_test_state['filters'][ $hook ] )( $value, ...$args );
	}
	return $value;
}

function get_user_meta( int $user_id, string $key, bool $single = false ): mixed {
	global $wp_test_state;
	$value = $wp_test_state['user_meta'][ $user_id ][ $key ] ?? '';
	return $single ? $value : ( $value === '' ? [] : [ $value ] );
}

function update_user_meta( int $user_id, string $key, mixed $value ): bool {
	global $wp_test_state;
	$wp_test_state['user_meta'][ $user_id ][ $key ] = $value;
	return true;
}

function is_user_logged_in(): bool {
	global $wp_test_state;
	return (bool) $wp_test_state['is_logged_in'];
}

function get_current_user_id(): int {
	global $wp_test_state;
	return (int) $wp_test_state['current_user_id'];
}

function wp_doing_cron(): bool {
	global $wp_test_state;
	return (bool) $wp_test_state['doing_cron'];
}

function wp_schedule_single_event( int $timestamp, string $hook, array $args = [] ): bool {
	global $wp_test_state;
	$wp_test_state['scheduled'][] = [ 'ts' => $timestamp, 'hook' => $hook, 'args' => $args ];
	return true;
}

function wp_next_scheduled( string $hook, array $args = [] ): int|false {
	global $wp_test_state;
	foreach ( $wp_test_state['scheduled'] as $event ) {
		if ( $event['hook'] === $hook && $event['args'] === $args ) {
			return (int) $event['ts'];
		}
	}
	return false;
}

function wp_clear_scheduled_hook( string $hook, array $args = [] ): int|false {
	global $wp_test_state;
	$wp_test_state['cleared_hooks'][] = $hook;
	$before = count( $wp_test_state['scheduled'] );
	$wp_test_state['scheduled'] = array_values( array_filter(
		$wp_test_state['scheduled'],
		static fn( array $e ) => $e['hook'] !== $hook
	) );
	return $before - count( $wp_test_state['scheduled'] );
}

function get_users( array $args = [] ): array {
	global $wp_test_state;
	$ids = $wp_test_state['users'];
	$offset = (int) ( $args['offset'] ?? 0 );
	$number = (int) ( $args['number'] ?? -1 );
	return array_slice( $ids, $offset, $number > 0 ? $number : null );
}

function get_option( string $key, mixed $default = false ): mixed {
	global $wp_test_state;
	return $wp_test_state['options'][ $key ] ?? $default;
}

function human_time_diff( int $from, ?int $to = null ): string {
	$to    = $to ?? time();
	$diff  = abs( $to - $from );
	if ( $diff < HOUR_IN_SECONDS )  return floor( $diff / MINUTE_IN_SECONDS ) . ' mins';
	if ( $diff < DAY_IN_SECONDS )   return floor( $diff / HOUR_IN_SECONDS )   . ' hours';
	if ( $diff < WEEK_IN_SECONDS )  return floor( $diff / DAY_IN_SECONDS )    . ' days';
	if ( $diff < MONTH_IN_SECONDS ) return floor( $diff / WEEK_IN_SECONDS )   . ' weeks';
	if ( $diff < YEAR_IN_SECONDS )  return floor( $diff / MONTH_IN_SECONDS )  . ' months';
	return floor( $diff / YEAR_IN_SECONDS ) . ' years';
}

function wp_date( string $format, ?int $timestamp = null ): string {
	return gmdate( $format, $timestamp ?? time() );
}

function esc_attr( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function esc_html__( string $text, string $domain = 'default' ): string {
	return esc_html( $text );
}

// --- Stub WP classes --------------------------------------------------------

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public function __construct(
			public int $ID,
			public string $user_login = '',
		) {}
	}
}

if ( ! class_exists( 'WP_User_Query' ) ) {
	class WP_User_Query {
		private array $vars = [];
		public function get( string $key ): mixed { return $this->vars[ $key ] ?? null; }
		public function set( string $key, mixed $value ): void { $this->vars[ $key ] = $value; }
	}
}

/**
 * Stub of the User Switching plugin's class. ActivityRecorder calls
 * `\user_switching::get_old_user()` to detect a switched session. We return a
 * WP_User when `wp_test_state['is_switched']` is true, false otherwise.
 */
if ( ! class_exists( 'user_switching' ) ) {
	// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
	final class user_switching {
		public static function get_old_user(): WP_User|false {
			global $wp_test_state;
			return ( $wp_test_state['is_switched'] ?? false ) ? new WP_User( 99, 'old_user' ) : false;
		}
	}
}

// --- Helpers exposed to tests -----------------------------------------------

function wp_test_set_filter( string $hook, callable $fn ): void {
	global $wp_test_state;
	$wp_test_state['filters'][ $hook ] = $fn;
}

function wp_test_set_logged_in( bool $value ): void {
	global $wp_test_state;
	$wp_test_state['is_logged_in'] = $value;
}

function wp_test_set_current_user_id( int $id ): void {
	global $wp_test_state;
	$wp_test_state['current_user_id'] = $id;
}

function wp_test_set_doing_cron( bool $value ): void {
	global $wp_test_state;
	$wp_test_state['doing_cron'] = $value;
}

function wp_test_set_users( array $ids ): void {
	global $wp_test_state;
	$wp_test_state['users'] = $ids;
}

function wp_test_set_user_switched( bool $value ): void {
	global $wp_test_state;
	$wp_test_state['is_switched'] = $value;
}

function wp_test_get_user_meta_raw( int $user_id, string $key ): mixed {
	global $wp_test_state;
	return $wp_test_state['user_meta'][ $user_id ][ $key ] ?? null;
}

function wp_test_scheduled(): array {
	global $wp_test_state;
	return $wp_test_state['scheduled'];
}

function wp_test_cleared_hooks(): array {
	global $wp_test_state;
	return $wp_test_state['cleared_hooks'];
}

function wp_test_hooks( string $hook ): array {
	global $wp_test_state;
	$out = [];
	foreach ( $wp_test_state['hooks'][ $hook ] ?? [] as $priority => $callbacks ) {
		foreach ( $callbacks as $cb ) $out[] = [ 'priority' => $priority ] + $cb;
	}
	return $out;
}
