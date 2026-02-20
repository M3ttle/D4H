# D4H Calendar WordPress Plugin

## Purpose

WordPress plugin that fetches **events** and **exercises** from the D4H Team Manager API, stores them in a custom table, and displays them in a **public frontend calendar** (FullCalendar). Sync is configurable via **cron** (e.g. every 2 hours) and an **admin page** (manual “Update now”, “Last updated”, “Delete data older than 90 days”). API credentials are set in the admin; no secrets in config or repo.

## Main behaviour

- **Config**: Single config file (`d4h-calendar/includes/config.php`) for API base URL, cron interval, retention days, table/option names, shortcode, REST namespace, etc. API token and optional context/contextId are saved from the admin form to options.
- **Admin**: Under **Settings → D4H Calendar**: API credentials form, “Last updated”, “Update now” (AJAX), “Delete data older than 90 days” (AJAX). Cron runs sync on a configurable interval.
- **Frontend**: Shortcode `[d4h_calendar]` renders a FullCalendar fed by a REST endpoint that reads from the local activities table. Uses Icelandic locale (`is`), header toolbar (month/week/day views), clickable days (switches to day view), event details modal on event click, and configurable height. See `config.php` for `calendar_locale`, `calendar_content_height`, etc.

## How to run / use

1. **Install**: Copy the `d4h-calendar` folder into `wp-content/plugins/`. Activate the plugin in **Plugins**.
2. **Configure**: Go to **Settings → D4H Calendar**, enter your D4H API token (and optionally context/contextId), save. Adjust other behaviour via `d4h-calendar/includes/config.php` if needed (cron interval, retention days, `calendar_locale`, `calendar_content_height`, etc.).
3. **Calendar**: Add shortcode `[d4h_calendar]` to any page or post to show the calendar.

## Project structure

- `d4h-calendar/d4h-calendar.php` – Plugin header and bootstrap load.
- `d4h-calendar/includes/config.php` – Single config array (no secrets).
- `d4h-calendar/includes/class-d4h-loader.php` – Wires config, Database, Repository, Cron, REST, Shortcode, Admin on `plugins_loaded`.
- `d4h-calendar/includes/class-d4h-database.php` – Table schema (dbDelta) and table name from config.
- `d4h-calendar/includes/class-d4h-api-client.php` – HTTP client for D4H API (whoami, get_events, get_exercises) with pagination.
- `d4h-calendar/includes/class-d4h-repository.php` – Storage: replace_activities, get_activities, delete_older_than.
- `d4h-calendar/includes/class-d4h-sync.php` – Sync orchestration: run_full_sync (fetch from API, store, update last_updated).
- `d4h-calendar/includes/class-d4h-cron.php` – Cron: custom interval (2h), schedule event on activation, run sync on hook.
- `d4h-calendar/includes/class-d4h-rest.php` – REST endpoint `GET /wp-json/d4h-calendar/v1/activities?start=...&end=...` for FullCalendar event source.
- `d4h-calendar/includes/class-d4h-shortcode.php` – Shortcode `[d4h_calendar]`; enqueues FullCalendar (CDN) and init script.
- `d4h-calendar/includes/class-d4h-admin.php` – **Settings → D4H Calendar**: API credentials form, “Update now” (AJAX), “Last updated”, “Delete data older than 90 days” (AJAX).
- `d4h-calendar/admin/admin.js` – Admin script for AJAX Update now and Delete buttons.
- `d4h-calendar/assets/calendar.js` – Frontend script that initializes FullCalendar with REST URL.
- `d4h-calendar/uninstall.php` – Clears scheduled cron on uninstall.

Security: nonces and capability checks on AJAX, REST date validation and range limit, escaped output, cron cleared on uninstall.
