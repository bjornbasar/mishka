# Mishka — Calendar design

The household calendar feature. Built as a release train: v0.3.0 events + month grid → v0.3.1 recurrence + single-occurrence editing → v0.3.2 iCal feed.

This doc grows monotonically per release. v0.3.0 sections are the only ones populated here today; v0.3.1 and v0.3.2 add their sections when those releases land.

## v0.3.0 — events + month grid + agenda

### Time model: local + timezone

Events store wall-clock time + IANA timezone, **not** UTC. The rationale is DST: "9am every Tuesday in NZ" stored as UTC drifts by an hour twice a year at NZDT/NZST transitions. Storing the local time and the zone separately means recurrence expansion (v0.3.1) happens in the event's timezone, so the wall-clock value stays stable.

In v0.3 every event in a household uses the household's timezone — the column ships per-event but the form has no input field. v0.4+ unlocks per-event tz editing alongside per-tz range partitioning in the query path.

### Month grid: HTML `<table>`, not CSS Grid

`MonthGridBuilder` (PHP class) produces a 6×7 data structure consumed by `templates/calendar/month.twig`. Always 6 rows so the layout is stable across months (some span 5 weeks, some 6 — padding to 6 prevents layout jitter when navigating).

CSS Grid was considered and rejected: it requires `role="grid"` + `role="gridcell"` + arrow-key JavaScript for accessibility. A `<table>` gets `<th>` semantics and screen-reader day-of-week announcements for free, which a read-only calendar needs.

### Multi-day events: slot assignment in PHP

Multi-day events span multiple cells in the month grid. The connected-pill illusion (continuous-looking bar across days) requires every pill in the span to sit on the same horizontal stripe — i.e., the same slot index across cells.

`MonthGridBuilder::assignSlots()` is a greedy first-fit assignment per occurrence, ordered `start ASC, duration DESC`. Longer events claim lower slots first; shorter events fit around them. Slot index → CSS class (`.multi-day-start` / `.mid` / `.end` / `.single`).

Decided in PHP, not Twig: the algorithm is order-dependent and stateful across cells. Twig templates can't see "what other events appear on the cells either side of this one", so cell-by-cell role assignment needs a pre-pass. The pre-pass is a real algorithm, unit-tested.

### Overflow: "+N more" links to agenda day-detail

Cells with >2 events render 2 real pills + a `+N more` overflow pill. Clicking it links to `/calendar/agenda?ym=YYYY-MM&day=DD` for the day-scoped agenda view. No modal, no popover — just a navigation that fits the no-JS constraint.

### Optimistic concurrency on edit

Two-tab edit races are a real failure mode (Bjorn updates an event on his phone while the same event is open in a desktop tab). The plan's approach:

- Edit form renders `<input type="hidden" name="_expected_updated_at" value="…">`
- POST handler reads current `updated_at` and compares to expected; mismatch → throw `ConcurrentUpdateException`
- Controller catches the exception and renders `templates/calendar/_stale_data.twig` with HTTP 409 + a "View current event" link

No auto-retry; no diff/merge UI. The user opens the form afresh and decides whether their intended change still makes sense. v0.4+ may add a richer conflict-resolution UI if real-world data shows it's needed.

### Input whitelist (defence-in-depth)

`CalendarController::readWhitelistedInput` extracts only `[title, description, location, starts_at_local, ends_at_local, all_day]` from the POST body. `household_id`, `created_by`, `timezone`, `series_event_id`, `rrule` (in v0.3.0), and system columns (id, created_at, updated_at) are NEVER honoured from form input. They're sourced from the session, the household row, or generated server-side.

Regression test: posting `series_event_id=99999` + `timezone=America/New_York` lands a row with the actual household tz and `series_event_id = NULL`.

### Minute precision

Events round to whole minutes on insert/update (`EventRepository::truncateSeconds`). The browser's `<input type="datetime-local">` emits `Y-m-d\TH:i` (no seconds anyway), but defensive truncation matters because v0.3.1's occurrence URL slug (`YYYY-MM-DDTHH-MM`) doesn't carry seconds — uniqueness only holds if every event row is minute-precise.

### Defensive `series_event_id IS NULL` filter

`EventRepository::findInRangeForHousehold` filters `WHERE series_event_id IS NULL`. In v0.3.0 there are no override events (rrule isn't wired), but the filter ships now because v0.3.1's overrides would otherwise double-render through this method: they'd match `starts_at_local in range` AND get substituted from the series expansion path. The plan's round-3 review flagged this as a load-bearing filter.

## v0.3.1 — recurrence + single-occurrence editing

*(not yet implemented)*

Plan-locked design:
- `event_exceptions` table tracking cancellations (`override_event_id IS NULL`) and overrides (`override_event_id` → standalone Event row, with `series_event_id` back-ref to the series)
- `simshaun/recurr ^6.0` expands RRULE in the event's timezone
- Single-occurrence URL slug `YYYY-MM-DDTHH-MM` (regex-validated before parsing)
- RRULE preset UX: dropdown of `none / daily / weekly / monthly / yearly` + INTERVAL; no UNTIL, no COUNT (rules run forever like phone calendars); no custom RRULE
- Cascade-on-series-edit policy: clean time-shifts cascade override `original_starts_at` by the same delta; structural rrule changes drop overrides (with explicit two-step DELETE because `event_exceptions → events` CASCADE points the wrong way for the override-event cleanup)

## v0.3.2 — iCal feed

*(not yet implemented)*

Plan-locked design:
- `ical_feed_tokens` table; per-user signed URL; SHA-256 hashed; cap at 3 active tokens (auto-revoke oldest on the 4th `generate`)
- `sabre/vobject ^4.5` for serialisation (eluceo/ical 2.x can't emit `RECURRENCE-ID`; sabre/vobject does, natively, plus parses iCal — future-proof for v0.5+ "subscribe to external calendar")
- All-households-merged feed by default (`scope_household_id NULL`); v0.4+ adds per-household feeds via the column
- `Referrer-Policy: no-referrer` on the feed + `<meta name="referrer" content="no-referrer">` on the post-generate page; Caddy log-path redaction documented in INFRASTRUCTURE.md
- VTIMEZONE block embedded per unique event timezone via `\Sabre\VObject\TimeZoneUtil::getVTimeZone()` so Apple Calendar / Google Calendar / Outlook render the local time correctly
