# Changelog

## 0.1.0 (2026-05-13)

Initial release.

- Records `mai_last_active` user meta on `wp_login` and on throttled `shutdown` (default 4-hour minimum between writes per user).
- Adds a sortable "Last Active" column to the Users admin screen, hideable via Screen Options.
- Activation hook seeds existing users from their `session_tokens` user meta via chained `wp_schedule_single_event` batches.
- WP-CLI command: `wp mai-last-active backfill [--dry-run]`.
- Filters: `mai_last_active_throttle`, `mai_last_active_should_record`.
- Skips WP-Cron, WP-CLI, and User Switching contexts. Monotonic writes — the timestamp never decreases.
