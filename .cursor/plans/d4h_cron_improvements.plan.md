---
name: D4H Calendar Cron Improvements
overview: Improve the cron system in the D4H Calendar plugin with overlap protection, error handling, smarter rescheduling, and optional admin controls.
todos: []
isProject: false
---

# D4H Calendar – Cron Improvements Plan

## Overview

Improve the cron system in the D4H Calendar plugin with overlap protection, error handling, smarter rescheduling, and optional admin controls.

---

## 1. Prevent Overlapping Syncs (high priority)

**Goal:** Ensure a new sync doesn't start while one is already running.

**Changes:**

- In `class-d4h-cron.php` `run_sync()`:
  - Add transient lock (`d4h_calendar_sync_lock`) before running sync
  - Set lock with TTL (e.g. 15 minutes) in case of crash
  - Delete lock in `finally` block after sync completes
- Add config key `cron_lock_ttl_sec` (optional) in `config.php`

---

## 2. Error Handling and Admin Visibility (high priority)

**Goal:** Make sync failures visible and debuggable.

**Changes:**

- Add option `d4h_calendar_last_sync_error` to store last error message
- Add option `d4h_calendar_last_sync_status` (e.g. `success` | `error`)
- In `Cron::run_sync()`: wrap `run_full_sync()` in try/catch, store `WP_Error` message on failure
- In admin page (`class-d4h-admin.php`): display "Last sync status" and error message if present
- Optionally log to `error_log()` when WP_DEBUG is on

---

## 3. Reschedule When Config Changes (medium priority)

**Goal:** If an admin changes `cron_interval_sec` or `cron_schedule_name` in config, the schedule should update without needing plugin reactivation.

**Changes:**

- In `Cron::schedule()` (or on `plugins_loaded`): check `wp_get_schedule()` for the current hook; if the schedule name/interval differs from config, unschedule and reschedule
- Consider doing this check once per load or on activation to avoid performance impact

---

## 4. Document WP Cron Behavior for Low-Traffic Sites (medium priority)

**Goal:** Clarify that sync timing depends on site visits and how to improve it.

**Changes:**

- Add a "Cron behavior" section to `README.md` / `README.mdc` explaining:
  - WP cron only fires when someone visits the site; timing is approximate
  - For stricter scheduling: use `DISABLE_WP_CRON` and a real system cron (e.g. `*/15 * * * * wp cron event run`)
  - Link to relevant WP docs if helpful

---

## 5. Config-Driven Uninstall (low priority)

**Goal:** Avoid hardcoding `cron_hook` in `uninstall.php` so it stays in sync with config.

**Changes:**

- In `uninstall.php`: require `config.php` and use `d4h_calendar_get_config()['cron_hook']` when calling `wp_clear_scheduled_hook`
- Ensure config file can be loaded during uninstall without side effects

---

## 6. Admin UI for Sync Interval (optional / low priority)

**Goal:** Let admins change sync interval without editing PHP.

**Changes:**

- Add option `d4h_calendar_cron_interval_sec` (or use config with override)
- Add dropdown on Settings → D4H Calendar: "Sync interval" (1h, 2h, 6h, 12h, 24h)
- On save: update option and call `Cron::unschedule()` + reschedule with new interval
- Fall back to `config['cron_interval_sec']` if option not set

---

## 7. Use Cron::unschedule() in Uninstall (low priority)

**Goal:** Reuse the existing `Cron::unschedule()` method for consistency.

**Changes:**

- In `uninstall.php`: require `config.php` and `class-d4h-cron.php`, then call `D4H_Calendar\Cron::unschedule( d4h_calendar_get_config() )`
- Remove direct `wp_clear_scheduled_hook` call
- Ensure classes can be loaded during uninstall (no missing dependencies)

---

## Suggested Implementation Order

1. **#1** – Overlap prevention (quick win)
2. **#2** – Error handling and admin display
3. **#3** – Reschedule on config change
4. **#4** – Documentation
5. **#5** + **#7** – Uninstall improvements (can be done together)
6. **#6** – Admin interval UI (optional enhancement)

