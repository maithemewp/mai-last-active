<?php
declare(strict_types=1);

namespace Mai\LastActive\Tests;

use Mai\LastActive\Plugin;
use Mai\LastActive\UsersColumn;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( UsersColumn::class )]
final class UsersColumnTest extends TestCase {

	protected function setUp(): void {
		wp_test_reset();
	}

	public function test_register_adds_filters(): void {
		( new UsersColumn() )->register();

		$this->assertCount( 1, wp_test_hooks( 'manage_users_columns' ) );
		$this->assertCount( 1, wp_test_hooks( 'manage_users_custom_column' ) );
		$this->assertCount( 1, wp_test_hooks( 'manage_users_sortable_columns' ) );
		$this->assertCount( 1, wp_test_hooks( 'pre_get_users' ) );
	}

	public function test_add_inserts_column_after_email(): void {
		$columns = [
			'cb'       => '',
			'username' => 'Username',
			'name'     => 'Name',
			'email'    => 'Email',
			'role'     => 'Role',
			'posts'    => 'Posts',
		];
		$result = ( new UsersColumn() )->add( $columns );
		$keys   = array_keys( $result );

		$emailPos    = array_search( 'email', $keys, true );
		$ourPos      = array_search( UsersColumn::COLUMN_ID, $keys, true );

		$this->assertSame( $emailPos + 1, $ourPos, 'Column should immediately follow email.' );
	}

	public function test_add_appends_when_email_missing(): void {
		$columns = [
			'cb'       => '',
			'username' => 'Username',
			'posts'    => 'Posts',
		];
		$result = ( new UsersColumn() )->add( $columns );
		$keys   = array_keys( $result );

		$this->assertSame( UsersColumn::COLUMN_ID, end( $keys ) );
	}

	public function test_render_returns_mdash_when_meta_empty(): void {
		$output = ( new UsersColumn() )->render( '', UsersColumn::COLUMN_ID, 5 );
		$this->assertSame( '&mdash;', $output );
	}

	public function test_render_returns_input_unchanged_for_other_columns(): void {
		$output = ( new UsersColumn() )->render( 'ORIGINAL', 'some_other_column', 5 );
		$this->assertSame( 'ORIGINAL', $output );
	}

	public function test_render_emits_abbr_with_escaped_attributes(): void {
		update_user_meta( 5, Plugin::META_KEY, time() - 3600 );
		$output = ( new UsersColumn() )->render( '', UsersColumn::COLUMN_ID, 5 );
		$this->assertStringStartsWith( '<abbr title="', $output );
		$this->assertStringContainsString( 'ago</abbr>', $output );
	}

	public function test_sortable_registers_slug(): void {
		$sortable = ( new UsersColumn() )->sortable( [] );
		$this->assertArrayHasKey( UsersColumn::COLUMN_ID, $sortable );
		$this->assertSame( UsersColumn::COLUMN_ID, $sortable[ UsersColumn::COLUMN_ID ] );
	}

	public function test_order_skips_unrelated_orderby(): void {
		$query = new \WP_User_Query();
		$query->set( 'orderby', 'email' );
		( new UsersColumn() )->order( $query );
		$this->assertNull( $query->get( 'meta_query' ) );
	}

	public function test_order_sets_compound_meta_query(): void {
		$query = new \WP_User_Query();
		$query->set( 'orderby', UsersColumn::COLUMN_ID );
		( new UsersColumn() )->order( $query );

		$mq = $query->get( 'meta_query' );
		$this->assertIsArray( $mq );
		$this->assertSame( 'OR', $mq['relation'] );
		$this->assertSame( Plugin::META_KEY, $mq['has']['key'] );
		$this->assertSame( 'EXISTS', $mq['has']['compare'] );
		$this->assertSame( 'NOT EXISTS', $mq['missing']['compare'] );

		// orderby gets rewritten to the named clause.
		$this->assertSame( 'has', $query->get( 'orderby' ) );
	}
}
