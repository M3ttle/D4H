# D4H Calendar WordPress Plugin

WordPress plugin that fetches **events** and **exercises** from the D4H Team Manager API, stores them in a custom table, and displays them in a **public frontend calendar** (FullCalendar).

---

## Quick start

1. Copy `d4h-calendar` into `wp-content/plugins/` and activate in **Plugins**.
2. Go to **Settings → D4H Calendar**, enter your D4H API token (and optional context/contextId), choose sync interval (1h, 2h, 6h, 12h, 24h), then save.
3. Add shortcode `[d4h_calendar]` to any page or post.

---

## Features

- **Sync**: Automatic sync via cron (configurable interval) and manual “Update now” on the admin page.
- **Admin page**: API credentials, sync interval, last updated time, last sync status, and “Delete data older than 90 days” (configurable retention).
- **Calendar**: FullCalendar with month/week/day views, Icelandic locale, event details modal. Height and locale configurable via `config.php`.
- **Security**: API credentials stored in options (not in config or repo); nonces and capability checks on AJAX; REST date validation and range limits; escaped output.

---

## Configuration

All behaviour is controlled from `d4h-calendar/includes/config.php`:

- API base URL, cron interval, retention days
- Table/option names, shortcode, REST namespace
- `calendar_locale`, `calendar_content_height`, `cron_lock_ttl_sec`, etc.

The API token and optional context/contextId are saved from the admin form (never in config or repo).

---

## Project structure

| File | Role |
|------|------|
| `d4h-calendar.php` | Plugin bootstrap |
| `includes/config.php` | Single config array (no secrets) |
| `includes/class-d4h-loader.php` | Wires components on `plugins_loaded` |
| `includes/class-d4h-database.php` | Table schema (dbDelta) |
| `includes/class-d4h-api-client.php` | D4H API client (events, exercises, pagination) |
| `includes/class-d4h-repository.php` | Storage: replace, get, delete older than |
| `includes/class-d4h-sync.php` | Sync: fetch from API, store, update last_updated |
| `includes/class-d4h-cron.php` | Cron: custom interval, overlap lock, reschedule on change |
| `includes/class-d4h-rest.php` | REST `GET /wp-json/d4h-calendar/v1/activities` |
| `includes/class-d4h-shortcode.php` | Shortcode `[d4h_calendar]` and FullCalendar init |
| `includes/class-d4h-admin.php` | Settings page and AJAX actions |
| `admin/admin.js` | Admin AJAX (Update now, Delete older) |
| `assets/calendar.js` | Frontend FullCalendar init |
| `uninstall.php` | Clears scheduled cron via `Cron::unschedule()` |

---

## Cron behaviour

- **WP Cron**: Sync runs on WordPress pseudo-cron. Events run when someone visits the site; timing is approximate.
- **Overlap protection**: A transient lock prevents multiple syncs running at once. TTL configurable via `cron_lock_ttl_sec` in `config.php` (default 15 min).
- **Reschedule on change**: When `cron_interval_sec`, `cron_schedule_name`, or the admin sync interval changes, the schedule updates on the next page load; no reactivation needed.

### More reliable scheduling (low-traffic sites)

1. Add `define( 'DISABLE_WP_CRON', true );` to `wp-config.php`
2. Add a system cron job:  
   `*/15 * * * * cd /path/to/wordpress && wp cron event run --due-now`

See [WordPress Cron](https://developer.wordpress.org/plugins/cron/) for details.
