<?php
declare(strict_types=1);

namespace Mai\LastActive;

final class UsersColumn {
	public const COLUMN_ID = 'mai_last_active';

	public function register(): void {
		add_filter( 'manage_users_columns',          $this->add(...) );
		add_filter( 'manage_users_custom_column',    $this->render(...), 10, 3 );
		add_filter( 'manage_users_sortable_columns', $this->sortable(...) );
		add_action( 'pre_get_users',                 $this->order(...) );
	}

	public function add( array $columns ): array {
		// Insert after email; fall back to append.
		$position = array_search( 'email', array_keys( $columns ), true );
		if ( $position === false ) {
			$columns[ self::COLUMN_ID ] = __( 'Last Active', 'mai-last-active' );
			return $columns;
		}
		return array_slice( $columns, 0, $position + 1, true )
			+ [ self::COLUMN_ID => __( 'Last Active', 'mai-last-active' ) ]
			+ array_slice( $columns, $position + 1, null, true );
	}

	public function render( string $output, string $column, int $user_id ): string {
		if ( $column !== self::COLUMN_ID ) return $output;

		$timestamp = (int) get_user_meta( $user_id, Plugin::META_KEY, true );
		if ( ! $timestamp ) return '&mdash;';

		$relative = sprintf(
			/* translators: %s: human-readable time difference, e.g. "2 hours" */
			__( '%s ago', 'mai-last-active' ),
			human_time_diff( $timestamp )
		);
		$absolute = wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$timestamp
		);

		return sprintf(
			'<abbr title="%s">%s</abbr>',
			esc_attr( $absolute ),
			esc_html( $relative )
		);
	}

	public function sortable( array $columns ): array {
		$columns[ self::COLUMN_ID ] = self::COLUMN_ID;
		return $columns;
	}

	public function order( \WP_User_Query $query ): void {
		if ( $query->get( 'orderby' ) !== self::COLUMN_ID ) return;

		// Compound meta_query so users without the meta still appear in the result set.
		$query->set( 'meta_query', [
			'relation' => 'OR',
			'has' => [
				'key'     => Plugin::META_KEY,
				'compare' => 'EXISTS',
				'type'    => 'NUMERIC',
			],
			'missing' => [
				'key'     => Plugin::META_KEY,
				'compare' => 'NOT EXISTS',
			],
		] );
		$query->set( 'orderby', 'has' );
	}
}
