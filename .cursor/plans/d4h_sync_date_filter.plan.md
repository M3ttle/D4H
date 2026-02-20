---
name: D4H Sync Date Filter
overview: Filter cron sync to fetch only the last 90 days and all future events/exercises, reducing API payload and improving performance.
todos: []
isProject: false
---

# D4H Calendar – Sync Date Filter Plan

## Overview

Change the cron sync to fetch only relevant data: **last 90 days** and **all future events/exercises**. This reduces API payload, network transfer, and processing time for teams with large historical datasets.

---

## 1. Add Date Window to API Fetch (high priority)

**Goal:** Pass `starts_after` (and optionally `ends_before` if needed) to `get_events()` and `get_exercises()`.

**Current behaviour:** Sync calls `get_events()` and `get_exercises()` with no date args → API returns everything.

**Desired behaviour:** Fetch only activities that start on or after `(now - 90 days)`. No upper bound (all future events).

**Changes:**

- In `class-d4h-sync.php` `run_full_sync()`:
  - Compute `$starts_after` = 90 days ago (use `retention_days` from config, default 90)
  - Pass `array( 'starts_after' => $starts_after )` as third arg to `get_events()` and `get_exercises()`
- **API format:** Verify D4H API docs for `starts_after` / `ends_before` expected format (ISO 8601, Unix timestamp, etc.). Common formats: `Y-m-d`, `Y-m-d\TH:i:s\Z`, or Unix ms. `API_Client::fetch_paginated()` passes args directly to `http_build_query`; ensure the format is acceptable.

---

## 2. Delete Out-of-Range Data from Local DB (high priority)

**Goal:** After upserting fetched data, remove local activities older than the fetch window.

**Why:** `Repository::replace_activities()` only upserts; it does not delete. If we stop fetching old data, existing old rows would remain in the DB forever. We must delete them to keep the local DB aligned with the fetch window.

**Changes:**

- In `class-d4h-sync.php` `run_full_sync()`, after `replace_activities()` succeeds:
  - Call `$this->repository->delete_older_than( $this->config['retention_days'] ?? 90 )`
- Reuse existing `Repository::delete_older_than()` – no new method needed.

---

## 3. Config (optional)

**Goal:** Make the fetch lookback configurable if desired.

**Options:**

- **A (recommended):** Use existing `retention_days` (90) for both fetch lookback and delete threshold. Keeps one source of truth.
- **B:** Add `sync_fetch_lookback_days` in `config.php` if fetch window should differ from retention (e.g. fetch 90 days but delete only 180+ days). Usually unnecessary.

**Decision:** Use `retention_days` for both. No config change required unless we later want separate values.

---

## 4. Manual Sync (admin "Update now") (medium priority)

**Goal:** Admin "Update now" should use the same date filter as cron.

**Changes:** None if both paths call `Sync::run_full_sync()`. Confirm that admin AJAX sync uses the same `run_full_sync()` – it does. So no extra changes.

---

## 5. Testing Considerations

- **First sync after change:** Old data (older than 90 days) will be deleted. Calendar will no longer show events from before the lookback window.
- **Verify API params:** Test that D4H API accepts `starts_after` and returns filtered results. If param names differ (e.g. `startDate`, `from`), adjust in `API_Client` or document.
- **Boundary:** Event starting exactly 90 days ago should be included (use `>=` semantics: `starts_after` = start of day 90 days ago).

---

## 6. Documentation

**README / README.mdc:**

- Under "Main behaviour" or "Cron behaviour", add: "Sync fetches only the last 90 days and all future events/exercises; older local data is deleted to match."
- Ensure `retention_days` is mentioned as controlling both fetch lookback and manual delete threshold.

---

## Suggested Implementation Order

1. **Verify D4H API** – Check docs for `starts_after` (and `ends_before`) parameter names and date format.
2. **#1** – Add date args to `get_events()` and `get_exercises()` in `Sync::run_full_sync()`.
3. **#2** – Call `delete_older_than()` after `replace_activities()` in `run_full_sync()`.
4. **#5** – Test with real API.
5. **#6** – Update README.
