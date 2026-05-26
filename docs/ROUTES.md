# Mishka — Routes

## Public (anonymous)

| Method | Path | Behaviour |
|---|---|---|
| GET  | / | Anonymous: pitch + Register/Sign-in CTAs. Logged in: see below. |
| GET  | /register | Show form (redirect / if logged in) |
| POST | /register | Validate, create user, auto-login, redirect /household/setup |
| GET  | /login | Show form (redirect / if logged in) |
| POST | /login | Timing-safe verify; set session; restore active_household from `user_preferences.last_household_id`; redirect / |

## Authenticated

| Method | Path | Behaviour |
|---|---|---|
| GET  | / | Logged in w/ household: home with household name + Manage link. Logged in w/o household: redirect /household/setup |
| POST | /logout | Destroy session, clear cookie, redirect /login |

## Households (v0.2)

All require an active session. Most require household membership (via `HouseholdAuthorizer::requireMember`); some additionally require ownership (`requireOwner`).

| Method | Path | Behaviour |
|---|---|---|
| GET  | /household/setup | Create/Join form. Redirect /household if user already has an active household. |
| POST | /household/setup | `action=create` OR `action=join`; sets session + writes `user_preferences.last_household_id`; redirect / |
| GET  | /household | Settings page; owner sees invite code + rename form + kick buttons |
| POST | /household/rename | Owner-only (`requireOwner`) |
| POST | /household/members/{userId}/remove | Owner-only; blocks owner-self and removing the sole owner |
| POST | /household/switch | Switch active household (`requireMember` on target); persists in `user_preferences` |

## Calendar (v0.3.0)

All gated on `Session::has('user_id')` + `Session::has('active_household_id')` + `HouseholdAuthorizer::requireMember`. POST handlers whitelist-extract form input to `[title, description, location, starts_at_local, ends_at_local, all_day]`.

| Method | Path | Behaviour |
|---|---|---|
| GET  | /calendar | Month grid. Optional `?ym=YYYY-MM` (defaults to current month in household tz). |
| GET  | /calendar/agenda | Agenda list grouped by event start. `?ym=YYYY-MM` (same default). |
| GET  | /calendar/events/new | Create form; defaults to tomorrow 9am in household tz. |
| POST | /calendar/events | Create; 303 to `/calendar?ym=…`. 422 + form re-render on validation failure. |
| GET  | /calendar/events/{id} | Edit form (shared template with create). 404 if event not in active household. |
| POST | /calendar/events/{id} | Update with `_expected_updated_at` optimistic-concurrency. 409 + stale-data partial on mismatch. |
| POST | /calendar/events/{id}/delete | Delete; 303 to /calendar |

## Calendar single-occurrence (v0.3.1)

Same gates as the calendar routes above. Slug regex: `^\d{4}-\d{2}-\d{2}T\d{2}-\d{2}$`.

| Method | Path | Behaviour |
|---|---|---|
| GET  | /calendar/events/{id}/occurrences/{key}/edit | Pre-fills from existing override OR series defaults. Cancellation state shown as a banner. 404 on malformed slug / non-occurrence / non-recurring series. |
| POST | /calendar/events/{id}/occurrences/{key} | Save override. Wipes any prior exception for this occurrence (cancellation OR earlier override) before `addOverride` runs, so the UNIQUE constraint can't trip. |
| POST | /calendar/events/{id}/occurrences/{key}/cancel | Insert a cancellation row (idempotent via UNIQUE). |
| POST | /calendar/events/{id}/occurrences/{key}/restore | Drop the exception (two-step DELETE if it was an override). |

## Series-edit confirm dialogs (v0.3.1)

POST `/calendar/events/{id}` either applies the change directly or — when there are existing exceptions and the edit is a clean time-shift or structural — renders one of:
- `_cascade_confirm.twig` — clean time-shift; confirming sets `_cascade_confirmed=1` and resubmits to the same route
- `_drop_confirm.twig` — structural change; confirming sets `_drop_confirmed=1` and resubmits

Both confirm forms also carry `_expected_updated_at` + `_expected_exception_count` so the second submit re-runs the optimistic-concurrency checks against fresh state.

## iCal feed (v0.3.2)

| Method | Path | Notes |
|---|---|---|
| GET | /me/calendar/feed | Settings page; lists active tokens (created_at + last_used_at). Session-gated. |
| POST | /me/calendar/feed/generate | Cap-aware generate; renders the post-generate page with the raw URL shown ONCE. `Referrer-Policy: no-referrer` header. |
| POST | /me/calendar/feed/tokens/{id}/revoke | Owner-gated; 303 → /me/calendar/feed. Stranger-revoke throws RuntimeException at repo layer. |
| GET | /ical/{token}.ics | **UNAUTHENTICATED.** Token IS the auth. 200 `text/calendar` on hit, 404 on invalid / revoked. `Referrer-Policy: no-referrer` + `Cache-Control: private, max-age=300` headers. |

The public feed route shape-checks the token against `/^[0-9a-f]{64}$/` before hashing — cheap bot filter, no DB lookup on malformed input.
