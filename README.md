# D4H WordPress Plugins

WordPress plugins that integrate with the **D4H Team Manager API** for emergency management and incident reporting.

---

## Plugins Overview

| Plugin | Purpose |
|--------|---------|
| **D4H Core** | Shared API credentials, team/context, API logs, sync history. Parent admin menu. |
| **D4H Calendar** | Fetches events and exercises, stores them, displays in a public FullCalendar |
| **D4H Incidents** | Fetches incidents, shows statistics, charts, exports to Excel/CSV and PNG |

When **D4H Core** is active, a dedicated **D4H** admin menu appears with submenus: Settings, Calendar, Incidents. Credentials, API logs, and sync history are stored centrally in Core. Calendar and Incidents read from Core.

Without Core, Calendar and Incidents add their pages under **Settings**. API credentials must be configured in D4H Core (Core is required for Incidents).

### Recommended: Use D4H Core

1. Copy `d4h-core`, `d4h-calendar`, and `d4h-incidents` into `wp-content/plugins/`.
2. Activate **D4H Core** first, then Calendar and Incidents.
3. Go to **D4H → Settings**, enter API token, context (team or organisation), and context ID.
4. Use **D4H → Calendar** and **D4H → Incidents** for plugin-specific options.

---

## D4H Core

Stores **shared API credentials** (token, context, context ID), **tags** (id → name), **API logs**, and **sync history**. Provides the top-level **D4H** admin menu. Calendar and Incidents use credentials; Incidents uses Core tags for filtering and display.

### Quick start

1. Copy `d4h-core` into `wp-content/plugins/` and activate.
2. Go to **D4H → Settings**, enter your D4H API token, context (team or organisation), and context ID.
3. Click **Update tags** to fetch and store tag names from the API (required for D4H Incidents tag filter and graphs).
4. Activate Calendar and/or Incidents; they will appear under the D4H menu.

### Project structure

| File | Role |
|------|------|
| `d4h-core.php` | Plugin bootstrap |
| `includes/config.php` | Config (no secrets) |
| `includes/functions.php` | `d4h_core_get_token()`, `d4h_core_get_context()`, `d4h_core_get_context_id()`, `d4h_core_get_tags_map()`, `d4h_core_get_github_token()` |
| `includes/class-d4h-core-admin.php` | D4H menu, Settings page (credentials, GitHub token, sync history, API logs) |
| `includes/class-d4h-core-logger.php` | API logs and sync history |
| `uninstall.php` | Cleans up on uninstall |

---

## D4H Calendar

Fetches **events** and **exercises** from the D4H Team Manager API, stores them in a custom table, and displays them in a **public frontend calendar** (FullCalendar).

### Quick start

1. Copy `d4h-calendar` into `wp-content/plugins/` and activate.
2. With Core: go to **D4H → Calendar**. Without Core: go to **Settings → D4H Calendar**.
3. Configure API credentials in D4H Core, then choose sync interval and set calendar options in D4H Calendar.
4. Add shortcode `[d4h_calendar]` to any page or post.

### Features

- **Sync**: Automatic sync via cron (configurable interval) and manual “Retrieve Calendar data” on the admin page (events, exercises, and tags).
- **Clean data**: Cron job every 12 hours deletes all events/exercises and re-fetches from D4H (removes duplicates). Admin "Clean data" button runs this manually.
- **Admin page**: Update calendar, Clean data, Delete old data (retention), sync interval, calendar content height (200–2000 px), event colors (by type or tag), custom CSS, update history. API credentials and GitHub token are in D4H Core.
- **Plugin updates**: Self-update from GitHub via **Plugins → Updates** when `update_github_repo` is set.
- **Calendar**: FullCalendar with month/week/day views, event details modal.

### Configuration

`d4h-calendar/includes/config.php`:

- API base URL, cron interval, retention days
- `update_github_repo`: GitHub repo for self-update
- `calendar_locale`, `calendar_content_height`, etc.

### Project structure

| File | Role |
|------|------|
| `d4h-calendar.php` | Plugin bootstrap |
| `includes/config.php` | Config (no secrets) |
| `includes/class-d4h-api-client.php` | D4H API client (events, exercises) |
| `includes/class-d4h-sync.php` | Sync orchestration |
| `includes/class-d4h-rest.php` | REST activities endpoint |
| `includes/class-d4h-shortcode.php` | Shortcode and FullCalendar |
| `includes/class-d4h-admin.php` | Settings page |
| `uninstall.php` | Cleans up on uninstall |

---

## D4H Incidents

Fetches **incidents** from the D4H Team Manager API and displays **statistics**, **charts**, and **exports** (Excel/CSV, PNG).

### Quick start

1. Copy `d4h-incidents` into `wp-content/plugins/` and activate.
2. With Core: go to **D4H → Incidents**. Without Core: go to **Settings → D4H Incidents**.
3. Configure API credentials in D4H Core. Select a time period (7/30/90/365 days or custom range), click **Fetch data**, then view statistics and charts.
4. Export to Excel (CSV) or download chart images (PNG).

### Features

- **Time range selector**: 7 days, 30 days, 90 days, 1 year presets or custom date range.
- **Statistics**: Total incidents, total participants (sum of countAttendance), total duration, plus **incidents per tag** (a box for each tag showing incident count). Tags are loaded via **D4H Core → Settings → Update tags**.
- **Incidents table**: Name, description (HTML stripped), tags, date, duration, participants (countAttendance). Sortable columns (including Tags). Pagination with per-page selector (10/25/50/100/200). Filter by tags (reuses same tag list as D4H Calendar); includes "No tag" for incidents without tags. **Unchecking tags does not change the current data**; select tags and press **Fetch data** again to filter.
- **Charts**:  
  - Incidents and participants by period: two side-by-side charts (incidents on the left, participants on the right) with period selector (weekly/monthly/yearly).  
  - **Incidents per tag by period**: incidents per tag over time (weekly/monthly/yearly), uses the same period selector.  
  - Incidents per member: number of incidents each member attended, with selector for how many to show (30/50/100/200/500/All), optional "Show names" (first two names).
- **Exports**: Excel (CSV) with all incidents; CSV report filtered by selected tags; PNG for each chart.
- **API**: Uses `v3/{context}/{id}/incidents` with `after` and `before` query params. Same credentials as Calendar.

### Configuration

`d4h-incidents/includes/config.php`:

- API base URL
- `default_range_days`: default time range (365)
- `attendance_path`: optional override path for attendance, e.g. `/v3/{context}/{context_id}/activities/{id}/attendance` (if D4H uses a different endpoint)
- `update_github_repo`: GitHub repo for self-update

### Project structure

| File | Role |
|------|------|
| `d4h-incidents.php` | Plugin bootstrap |
| `includes/config.php` | Config (no secrets) |
| `includes/class-d4h-incidents-api-client.php` | D4H API client (incidents, attendance) |
| `includes/class-d4h-incidents-admin.php` | Admin page, fetch, charts, export |
| `includes/class-d4h-incidents-plugin-updater.php` | Self-update from GitHub |
| `admin/admin.js` | Fetch, Chart.js, export handlers |
| `assets/admin.css` | Admin styles |
| `uninstall.php` | Cleans up on uninstall |

---

## Shared

- **D4H Core**: When active, provides API credentials, API logs, sync history, and the D4H admin menu.
- **API**: All plugins use the D4H Team Manager API v3 (HTTPS, Bearer token).
- **Credentials**: Stored in Core (or per-plugin if Core is not active).
- **Updates**: Core, Calendar, and Incidents support self-update from GitHub (M3ttle/D4H). GitHub API token for higher rate limits is configured in D4H → Settings.
- **Security**: API credentials in options; nonces and capability checks on admin/AJAX.

---

## Author

Nonni

## License

GPL v2 or later
