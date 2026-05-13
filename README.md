# Mai Last Active

Records each WordPress user's last-active timestamp and surfaces it as a sortable, hideable column on the Users screen.

WordPress has no built-in "last login" or "last active" record per user. Existing solutions either piggyback on WooCommerce's `wc_last_active` (which is "last activity," not login, and only works with WC installed) or require a heavy plugin like Admin Columns Pro. This plugin fills the gap with a single user meta key and a single column.

## What it does

- On `wp_login`, writes the current Unix timestamp to user meta `mai_last_active`.
- On `shutdown`, while a user is logged in, writes the timestamp again — but throttled (default: at most once every 4 hours per user). This catches users who stay logged in for long stretches and keep visiting.
- Adds a sortable "Last Active" column on the Users admin screen, hideable via Screen Options.
- On activation, seeds existing users from their `session_tokens` meta so the column has data on day one for anyone with a live session.

## What it does NOT do

- Does not fire on WP-Cron requests, WP-CLI requests, or while User Switching is active. Only real user activity counts.
- Does not lower the timestamp. Writes are monotonic — `mai_last_active` only ever increases.
- Does not provide a settings page, filter dropdown, or REST endpoint. The public API is two filters (see below).

## Install

### Via Composer

```bash
composer require bizbudding/mai-last-active
```

Then activate the plugin in WordPress.

### Via GitHub release zip

Download the latest release from [Releases](https://github.com/maithemewp/mai-last-active/releases), upload via Plugins → Add New → Upload Plugin, activate.

## Filters

| Filter | Default | Purpose |
|---|---|---|
| `mai_last_active_throttle` | `4 * HOUR_IN_SECONDS` | Minimum seconds between writes per user on `shutdown`. Applied once at boot. |
| `mai_last_active_should_record` | `true` | Per-request kill switch. Return `false` to skip the `shutdown` recorder for the current request (useful for high-traffic REST/SPA endpoints). |

Example — bump the throttle to once per day:

```php
add_filter( 'mai_last_active_throttle', static fn() => DAY_IN_SECONDS );
```

Example — never record activity on REST requests:

```php
add_filter( 'mai_last_active_should_record', static function ( $should ) {
	return $should && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST );
} );
```

## WP-CLI

```bash
wp mai-last-active backfill              # seed mai_last_active from session_tokens, synchronously
wp mai-last-active backfill --dry-run    # report would-be writes without changing anything
```

Use when WP-Cron is disabled (`DISABLE_WP_CRON`) or when you want immediate completion after activation instead of waiting on the chained cron batches.

## FAQ

**Does this count user switching?**
No. When you're switched to another user via the [User Switching](https://wordpress.org/plugins/user-switching/) plugin, the switched-to user's timestamp is NOT updated. Switching detection uses `\user_switching::get_old_user()`.

**Does REST traffic count as activity?**
Yes. REST requests reach `shutdown` like any other entry point, so an authenticated REST request updates the user's timestamp (subject to the throttle).

**Does Application Password traffic count?**
Yes — same reason. If you'd rather it not, gate it with the `mai_last_active_should_record` filter (see the REST example above).

**Multisite?**
User meta is global in WordPress multisite. The value reflects last activity on **any** site in the network, not per-site. Acceptable for most use cases; not configurable in this version.

**How is this different from WooCommerce's `wc_last_active`?**
`wc_last_active` is set by WC core on any logged-in front-end request at daily precision (midnight UTC), and only when WC is installed. `mai_last_active` is set on real login events plus throttled activity, at the precision you configure, on any WordPress site.

## License

GPL-2.0-or-later.
