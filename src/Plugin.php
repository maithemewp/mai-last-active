<?php
declare(strict_types=1);

namespace Mai\LastActive;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

final class Plugin {
	public const META_KEY   = 'mai_last_active';
	public const TEXTDOMAIN = 'mai-last-active';

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		$this->boot_updater();

		( new ActivityRecorder(
			throttle: (int) apply_filters( 'mai_last_active_throttle', 4 * HOUR_IN_SECONDS )
		) )->register();

		( new UsersColumn() )->register();
		( new Backfill() )->register();
	}

	public static function version(): string {
		return defined( 'MAI_LAST_ACTIVE_VERSION' ) ? (string) MAI_LAST_ACTIVE_VERSION : '0.0.0';
	}

	/**
	 * Wire up plugin-update-checker against the GitHub repo. The repo is
	 * public, but we support MAI_GITHUB_API_TOKEN so this plugin updates the
	 * same way the rest of the BizBudding/Mai plugins do on managed sites.
	 *
	 * @see https://github.com/YahnisElsts/plugin-update-checker
	 */
	private function boot_updater(): void {
		if ( ! class_exists( PucFactory::class ) ) {
			return;
		}

		$updater = PucFactory::buildUpdateChecker(
			'https://github.com/maithemewp/mai-last-active/',
			defined( 'MAI_LAST_ACTIVE_FILE' ) ? (string) MAI_LAST_ACTIVE_FILE : __FILE__,
			'mai-last-active'
		);

		$updater->setBranch( 'main' );

		// Maybe set github api token.
		if ( defined( 'MAI_GITHUB_API_TOKEN' ) ) {
			$updater->setAuthentication( MAI_GITHUB_API_TOKEN );
		}

		// Add icons for Dashboard > Updates screen.
		// Icons live in mai-engine; no-op when mai-engine isn't active.
		if ( function_exists( 'mai_get_url' ) ) {
			$updater->addResultFilter(
				function ( $info ) {
					$info->icons = [
						'1x' => mai_get_url() . 'assets/img/icon-128x128.png',
						'2x' => mai_get_url() . 'assets/img/icon-256x256.png',
					];

					return $info;
				}
			);
		}
	}
}
