<?php
/**
 * Mai Last Active — Uninstall
 *
 * Runs when the plugin is deleted (not on deactivation). Composer
 * autoload is NOT available in this context, so strings are hard-coded.
 *
 * @package Mai\LastActive
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Drop the meta everywhere with a single delete-all query.
delete_metadata( 'user', 0, 'mai_last_active', '', true );

// Belt-and-suspenders: clear any in-flight backfill cron events.
wp_clear_scheduled_hook( 'mai_last_active_backfill_batch' );
