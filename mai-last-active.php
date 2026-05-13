<?php
/**
 * Plugin Name:       Mai Last Active
 * Plugin URI:        https://bizbudding.com/
 * Description:       Records each user's last-active timestamp and exposes it as a sortable, hideable column on the Users screen.
 * Version:           0.1.0
 * Requires at least: 6.3
 * Requires PHP:      8.2
 * Author:            BizBudding
 * Author URI:        https://bizbudding.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mai-last-active
 *
 * @package Mai\LastActive
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'MAI_LAST_ACTIVE_VERSION', '0.1.0' );
define( 'MAI_LAST_ACTIVE_FILE',    __FILE__ );
define( 'MAI_LAST_ACTIVE_DIR',     plugin_dir_path( __FILE__ ) );
define( 'MAI_LAST_ACTIVE_URL',     plugin_dir_url( __FILE__ ) );

if ( ! file_exists( MAI_LAST_ACTIVE_DIR . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p><strong>Mai Last Active:</strong> %s</p></div>',
				esc_html__( 'Composer dependencies are missing. Run `composer install` in the plugin directory.', 'mai-last-active' )
			);
		}
	);
	return;
}

require_once MAI_LAST_ACTIVE_DIR . 'vendor/autoload.php';

// Registered AFTER the autoloader so the class callbacks resolve when WP fires the hooks.
register_activation_hook(   __FILE__, [ \Mai\LastActive\Backfill::class, 'schedule_initial_run' ] );
register_deactivation_hook( __FILE__, [ \Mai\LastActive\Backfill::class, 'clear_scheduled' ] );

\Mai\LastActive\Plugin::instance()->boot();
