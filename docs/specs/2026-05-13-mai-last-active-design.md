# Mai Last Active — Design Spec

- **Date:** 2026-05-13
- **Owner:** Mike Hemberger
- **Status:** Approved
- **Repo (planned):** `https://github.com/maithemewp/mai-last-active`
- **Composer name:** `bizbudding/mai-last-active`
- **Initial version:** `0.1.0`

## 1. Why

WordPress has no built-in "last login" or "last active" record per user. On Mai sites that gap is currently papered over with WooCommerce's `wc_last_active` user meta (set by `wc_update_user_last_active()`) — surfaced via Admin Columns Pro as a column labeled "Last Login," which is inaccurate (it's last activity, not login) and only works where WC is installed. WC Memberships exposes its own column whose internal key is `last_login` but whose label is "Last activity" — also reading `wc_last_active`.

We want a small, dependency-light, public plugin that:

- Stores a real per-user activity timestamp under our own meta key, so behavior is identical across every Mai site whether WC is present or not.
- Surfaces the value as a sortable, hideable column on `users.php`.
- Has a tight public API (two filters), no settings UI, no admin pages.

The plugin is **activity** (not just login) — we hook both `wp_login` (so a login always records) and `shutdown` (so users who stay logged in but keep visiting still get updated, throttled). This was a deliberate decision after considering login-only.

## 2. Decisions log

Decisions agreed during brainstorming, in order:

| # | Decision | Reasoning |
|---|---|---|
| 1 | Plugin name: **Mai Last Active** (slug `mai-last-active`) | Single meta covering both login and ongoing activity; "last active" is the honest label. |
| 2 | Meta key: `mai_last_active` (Unix timestamp, int) | Single source of truth. No prefix-underscore — we want it discoverable via REST/`get_user_meta` consumers. |
| 3 | Update events: `wp_login` (always) + `shutdown` (throttled) | `wp_login` covers form/cookie/2FA sign-in. `shutdown` covers extended sessions without re-login. |
| 4 | Throttle: **4 hours**, filterable | Keeps DB writes to ≤6/user/day. Tunable via `mai_last_active_throttle`. |
| 5 | Hook the throttled write at `shutdown`, not `init` | Write happens after the response is sent → zero TTFB impact. PHP-FPM `fastcgi_finish_request` runs first, but `shutdown` still has a live DB connection. |
| 6 | **Skip user-switching** via `\user_switching::get_old_user()` | The User Switching plugin sets `wp_set_current_user()` directly without firing `wp_login`, so only the `shutdown` path needs guarding. |
| 7 | Skip cron and WP-CLI in `shutdown` recorder | Cron and CLI requests are not "users active on the site." |
| 8 | Monotonic writes only — never decrement the timestamp | Backfill, re-runs, and any future writers must use the shared `touch()` writer that checks `new > existing`. |
| 9 | Activation backfill from `session_tokens` user meta | WP stores active session records server-side with a `login` timestamp; we seed `mai_last_active` with the MAX(`login`) per user. |
| 10 | Backfill source: **`session_tokens` only** | Honest semantics. WC/2FA fallbacks were considered and explicitly rejected — they mix activity with login on day one. |
| 11 | Backfill runs via chained `wp_schedule_single_event` batches of 500 | Activation never times out, no recurring cron registered, chain self-terminates when the user list is exhausted. |
| 12 | Deactivation clears any pending backfill batches | Belt-and-suspenders against orphaned cron entries. |
| 13 | Uninstall deletes all `mai_last_active` user meta | Clean uninstall via `delete_metadata( 'user', 0, …, '', true )`. |
| 14 | Service-oriented architecture, PHP 8.2+, PSR-4 namespaces | Matches Springwire plugins (`Springwire\Publish\…`); not the old Mai singleton style. |
| 15 | Column label "Last Active," position after Email | Honest label matching the meta semantics. |
| 16 | Column format: `human_time_diff()` text + absolute date on hover via `<abbr title>` | Quick glance + precise hover. |
| 17 | Sortable column using compound `meta_query` so unset users still appear | Default WP `meta_key` sort acts like an INNER JOIN and drops users without the meta. |
| 18 | No filter dropdown for "Inactive for ≥ N days" in v0.1 | Scope creep; easy to add later. |
| 19 | Plugin updates via `yahnis-elsts/plugin-update-checker` against the GitHub repo, with optional `MAI_GITHUB_API_TOKEN` | Same pattern as Springwire and other Mai plugins. |
| 20 | Icons sourced from mai-engine via `mai_get_url()`, gated by `function_exists` | Mai-engine ships shared icons; no-op (no icons) on sites without mai-engine. Acceptable. |
| 21 | Public API surface: **two filters**, one CLI command | `mai_last_active_throttle`, `mai_last_active_should_record`, `wp mai-last-active backfill`. No settings page, no admin UI. |

## 3. Architecture

```
mai-last-active/
├── mai-last-active.php          # plugin header, constants, autoload guard, activation/deactivation
├── uninstall.php                # deletes mai_last_active user meta everywhere
├── composer.json                # bizbudding/mai-last-active, php>=8.2, PSR-4
├── composer.lock                # committed
├── phpunit.xml.dist
├── README.md
├── CHANGES.md
├── .github/workflows/test.yml   # phpunit on push/PR
├── src/
│   ├── Plugin.php               # final, ::instance()->boot(), wires services + PUC updater
│   ├── ActivityRecorder.php     # wp_login + shutdown, throttle, user-switching guard
│   ├── UsersColumn.php          # column + sort + screen-options integration
│   └── Backfill.php             # activation hook + chained single-event cron + WP-CLI
└── tests/
    ├── abspath-shim.php
    ├── bootstrap.php
    ├── ActivityRecorderTest.php
    ├── UsersColumnTest.php
    └── BackfillTest.php
```

Namespace `Mai\LastActive\`. PSR-4 autoload via Composer. PHP 8.2+ with `declare(strict_types=1)`.

### Component responsibilities

- **`Plugin`** — singleton entry point. Boots the update checker, instantiates services with their dependencies, registers them. Owns the `META_KEY` and `TEXTDOMAIN` constants. Does not contain business logic.
- **`ActivityRecorder`** — hooks `wp_login` and `shutdown`. Decides when to write. Owns the monotonic `touch()` writer.
- **`UsersColumn`** — registers the admin column, renders its cell, makes it sortable, and rewrites `WP_User_Query` ordering when needed.
- **`Backfill`** — knows how to seed one user from `session_tokens`, knows how to run a batch with chained rescheduling, knows how to clear its own scheduled events, exposes the WP-CLI command.

Each service has a `register()` method that adds its hooks. `Plugin::boot()` calls each.

## 4. Main plugin file

```php
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
    add_action( 'admin_notices', static function (): void {
        printf(
            '<div class="notice notice-error"><p><strong>Mai Last Active:</strong> %s</p></div>',
            esc_html__( 'Composer dependencies are missing. Run `composer install` in the plugin directory.', 'mai-last-active' )
        );
    } );
    return;
}

require_once MAI_LAST_ACTIVE_DIR . 'vendor/autoload.php';

// Registered AFTER the autoloader so the class callbacks resolve when WP fires the hooks.
register_activation_hook(   __FILE__, [ \Mai\LastActive\Backfill::class, 'schedule_initial_run' ] );
register_deactivation_hook( __FILE__, [ \Mai\LastActive\Backfill::class, 'clear_scheduled' ] );

\Mai\LastActive\Plugin::instance()->boot();
```

## 5. `src/Plugin.php`

```php
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
```

## 6. `src/ActivityRecorder.php`

```php
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
```

**Notes**

- The throttle is injected as a constructor parameter; the filter is applied once in `Plugin::boot()`, so it doesn't run on every request.
- `static $ran` prevents double-writes if `shutdown` somehow fires twice (it shouldn't, but cheap insurance).
- `wp_doing_cron()` skips WP-Cron contexts. `WP_CLI` constant skips CLI auth-as-user contexts.
- User Switching detection only matters for the `shutdown` path; switching uses `wp_set_current_user()` directly and does not fire `wp_login`.
- `touch()` is `public static` — the monotonic invariant lives in exactly one place. `Backfill::seed_user()` calls it, so we never duplicate the "don't lower the timestamp" rule.

## 7. `src/UsersColumn.php`

```php
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
```

**Screen Options** is free: registering through `manage_users_columns` automatically gives WP core the per-user hide/show checkbox under Screen Options, and the hidden state persists via WP's `manageusers-columnshidden` user meta.

## 8. `src/Backfill.php`

```php
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
        $total   = (int) ( new \WP_User_Query( [ 'count_total' => true, 'number' => 1 ] ) )->get_total();
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
```

## 9. `uninstall.php`

```php
<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Drop the meta everywhere with a single delete-all query.
delete_metadata( 'user', 0, 'mai_last_active', '', true );

// Belt-and-suspenders: clear any in-flight backfill cron events.
wp_clear_scheduled_hook( 'mai_last_active_backfill_batch' );
```

## 10. `composer.json`

```json
{
    "name": "bizbudding/mai-last-active",
    "description": "Last-active tracking for WordPress users, with a sortable admin column.",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.2",
        "yahnis-elsts/plugin-update-checker": "^5.4"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "autoload": {
        "psr-4": { "Mai\\LastActive\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "Mai\\LastActive\\Tests\\": "tests/" }
    },
    "scripts": {
        "test": "@php -d auto_prepend_file=tests/abspath-shim.php vendor/bin/phpunit"
    },
    "config": {
        "sort-packages": true,
        "optimize-autoloader": true,
        "allow-plugins": {}
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

## 11. Public API surface

### Constants
- `MAI_LAST_ACTIVE_VERSION`, `MAI_LAST_ACTIVE_FILE`, `MAI_LAST_ACTIVE_DIR`, `MAI_LAST_ACTIVE_URL`
- `Plugin::META_KEY = 'mai_last_active'` (also surfaced as `UsersColumn::COLUMN_ID`)

### Filters
| Filter | Default | Purpose |
|---|---|---|
| `mai_last_active_throttle` | `4 * HOUR_IN_SECONDS` | Minimum seconds between writes per user on `shutdown`. Applied once at boot. |
| `mai_last_active_should_record` | `true` | Per-request kill switch (e.g., skip on SPA REST endpoints). Evaluated in the `shutdown` recorder. |

### Actions hooked
- `wp_login` (priority 10) — record login.
- `shutdown` — throttled record.
- `manage_users_columns`, `manage_users_custom_column`, `manage_users_sortable_columns`, `pre_get_users` — column.
- `mai_last_active_backfill_batch` — internal batch runner.

### CLI
```
wp mai-last-active backfill              # synchronous full backfill
wp mai-last-active backfill --dry-run    # report only
```

### Meta keys (data model)
- `mai_last_active` (user meta, int unix timestamp) — the single source of truth this plugin owns.
- Reads (does not write): `session_tokens` (during backfill only).

## 12. Lifecycle

| Event | What happens |
|---|---|
| Activation | `register_activation_hook` calls `Backfill::schedule_initial_run()` → schedules one cron event at `offset = 0` 5 seconds out. Returns immediately. |
| First cron fire | Processes users 0–499. If 500 users returned, schedules next event at offset 500. Otherwise stops. |
| Subsequent fires | Same pattern at successive offsets until a batch returns < 500. Chain self-terminates. |
| Each `wp_login` | `ActivityRecorder::on_login()` → `touch()`. |
| Each `shutdown` | `ActivityRecorder::on_shutdown()` → bail or `touch()`. |
| Deactivation | `Backfill::clear_scheduled()` clears any pending `mai_last_active_backfill_batch` events. Meta is preserved. |
| Reactivation | Re-schedules from offset 0. Monotonic guard prevents overwriting fresh `wp_login` writes that happened in the meantime. |
| Uninstall | `uninstall.php` deletes all `mai_last_active` user meta in one query and clears any leftover cron. |

## 13. Non-goals (v0.1)

- No admin settings page.
- No filter dropdown for "Inactive ≥ N days" on the Users screen.
- No login count, IP, or user-agent capture.
- No multisite "last active per site" tracking — user meta is global, so the value reflects last activity on any site in the network. Acceptable.
- No REST field exposure (consumers can read the meta directly).
- No bulk action ("Email inactive users", etc.).
- No `mai-logger` dependency. Plugin surface is too small to justify.
- No application-password-specific tracking. App-password REST requests reach `shutdown` and update activity naturally; that's the right behavior.

## 14. Testing strategy

PHPUnit 10, no WP test suite required — we test pure logic via stubbed WP functions in `tests/bootstrap.php`, with `tests/abspath-shim.php` defining `ABSPATH` for any code that guards on it (mirroring the Springwire pattern).

### `tests/ActivityRecorderTest.php`
- `on_login` always calls `touch` with `time()`.
- `on_shutdown` bails on: logged-out, cron, CLI, switched user, `mai_last_active_should_record` returns false, within throttle.
- `on_shutdown` past throttle calls `touch`.
- `on_shutdown` static `$ran` flag prevents double-execution.
- `touch` is monotonic (no-op when `new <= current`).

### `tests/UsersColumnTest.php`
- `add()` inserts the column after `email`, or appends when `email` is absent.
- `render()` returns `&mdash;` when meta empty.
- `render()` returns `<abbr title="…">N ago</abbr>` when meta set; escaping verified.
- `sortable()` registers the slug.
- `order()` mutates the `WP_User_Query` only when `orderby` matches; sets the compound `meta_query`.

### `tests/BackfillTest.php`
- `compute_seed()` picks `MAX(session_tokens[*].login)`.
- `compute_seed()` handles: non-array meta, empty array, malformed entries, missing `login` key.
- `seed_user()` delegates to `ActivityRecorder::touch()` (monotonic guard is inherited).
- `would_seed()` returns the seed timestamp when a write would happen, `null` when guard blocks or no tokens.
- `run_batch()` schedules next event only when the batch is full.
- `run_batch()` does not schedule when batch is partial.
- `schedule_initial_run()` is idempotent.
- `clear_scheduled()` unschedules pending events.

## 15. Releases

- Branch: `main`.
- Versioning: SemVer; first tag `v0.1.0`.
- GitHub Release per tag; plugin-update-checker reads release assets.
- `CHANGES.md` mirrors release notes.
- `.github/workflows/test.yml` runs `composer install` + `composer test` on push and PR across PHP 8.2 / 8.3 / 8.4.

## 16. Open items (deliberately deferred)

- Optional v0.2 enhancements (not commitments):
  - "Inactive for ≥ N days" filter above the Users table.
  - REST field exposure for the meta.
  - Bulk action to email/export inactive users.
  - Optional IP / user-agent capture under a feature flag.
- Multisite-specific "per-site last active" if anyone needs it.
