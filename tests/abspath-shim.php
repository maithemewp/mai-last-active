<?php
/**
 * Prepended (via `php -d auto_prepend_file=...` in the `composer test` script)
 * so it runs *before* the composer-installed PHPUnit requires
 * `vendor/autoload.php`. Some bundled libraries guard themselves with
 * `defined( 'ABSPATH' ) || exit;`. In a no-WordPress test run that `exit` would
 * kill PHPUnit before it prints anything, so we define a harmless ABSPATH up
 * front. In a real WordPress request this file is never used — WordPress
 * defines ABSPATH itself, well before the autoloader.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
