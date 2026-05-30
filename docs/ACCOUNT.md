# Account lifecycle — v0.5.0

This doc covers the v0.5.0 surfaces: profile editing, password change, password reset, email verification, household lifecycle (regenerate / leave / transfer / delete), session revocation, and the rate-limit + timing-floor + URL-builder primitives that hold them together.

Scope is the **what + why**. For ROUTE-level reference see [ROUTES.md](ROUTES.md); for schema see [SCHEMA.md](SCHEMA.md).

---

## Profile (display_name only)

**Edit:** `/me/profile` — single field, 1-120 chars, required.

**Email change is deliberately out of scope for v0.5.** Email is the canonical FK identifier across the schema (users.email is UNIQUE, login looks up by it, every emailed link addresses by it). Changing it touches every flow that has ever sent an email — a worthwhile feature for v0.6+, but too much surface for v0.5.

**Register-time warning:** [register.twig](../templates/auth/register.twig) carries a `<strong>` "Double-check your email — you can't change this later" banner above the email input to keep users from typo'd accounts.

---

## Password change

**Endpoint:** `POST /me/password`, gated on `Session::has('user_id')`.

**Inputs:** `current_password`, `new_password`, `new_password_confirm`.

**Validation:**
- New is 12–128 chars (matches register).
- New matches confirm.
- New differs from current (`hash_equals` to avoid timing leak).
- `PasswordHasher::verify($current, $user['password_hash'])` is **always** called, regardless of subsequent validation outcomes — closes a timing oracle for password-length / current-password probing (M1).

**Success path:**
1. `$now = gmdate('Y-m-d H:i:s')` is pinned **once** in the handler (round-4 BL-2).
2. `MishkaUserRepository::updatePassword($uid, $newHash, $now)` writes the new hash AND stamps `user_password_changes.password_changed_at` to the same `$now` in a single transaction.
3. `Session::regenerate()` (post-credential-change session rotation defends against fixation).
4. `Session::set('auth_time', $now)` — using the SAME pinned `$now` so the SessionRevocationGuard's `auth_time < password_changed_at` predicate is false (no self-revoke).
5. `Csrf::regenerate()` rotates the CSRF token (round-4 H-7).
6. Flash success + 303 → `/me/profile`.

### Why pin `$now`?

If we called `gmdate()` twice in step 2 and step 4, microsecond drift between the two calls could cause `auth_time < password_changed_at` to be true, and the very next request would self-revoke the user. The single shared `$now` makes them identical strings; the comparison is false; the user stays in.

---

## Password reset

**Endpoints:**
- `GET /password-reset` — email-input form.
- `POST /password-reset` — always-200 + 1.5s timing floor + email send if hit.
- `GET /password-reset/{64-hex}` — render reset form if token valid.
- `POST /password-reset/{64-hex}` — atomic single-use redeem.

### Threat model + defences

| Threat | Fix |
|---|---|
| **B1 — Host-header injection in the email link.** `Host: evil.com` against the anonymous POST would otherwise mint `https://evil.com/password-reset/<token>` and email it to the victim. | `App\Mail\UrlBuilder` reads ONLY `$_ENV['APP_URL']`. Boot validates that env var (`public/index.php`). No code path reads `$request->header('host')` for emailed URLs. |
| **B3 — SQLite/PG timestamp drift.** `NOW() + INTERVAL '1 hour'` doesn't translate to SQLite. | All TTL math in PHP via `gmdate('Y-m-d H:i:s', time() + 3600)`. Both write and compare. |
| **B4 — Timing enumeration.** Hit path runs ~100–500ms (issue + render + SMTP); miss path used to be ~5ms. | Always-200 + identical body for hit/miss + 1.5s `usleep(max(0, 1_500_000 - elapsed))` floor + dummy argon2id verify on miss path (round-4 H-4 defence in depth). |
| **B6 — Single-use race.** Two concurrent POSTs of the same reset URL could both pass the lookup. | `redeemAtomically()` runs `UPDATE … SET used_at = :now WHERE id AND used_at IS NULL AND expires_at > :now` and gates the password write on `rowCount === 1`. |
| **H-4 — SMTP timeout exposes hit-path.** A 5s SMTP timeout would blow past the 1.5s floor, making the hit path observably slower than miss. | DSN `?timeout=5` caps the worst case; dummy argon2id verify on miss adds ~300ms baseline; floor of 1500ms covers normal jitter. Accepted residual: under simultaneous Postmark outage + heavy attacker timing analysis, the miss path is faster — but the user-level signal is "I'm being attacked," not "this email exists." |
| **H-5 — Referer leak.** Token-bearing URL could leak via `Referer` if the user clicks an external link from the reset page. | Both GET routes set `Referrer-Policy: no-referrer`. |
| **H4 — Brute-force enumeration.** | App-layer rate limit: 5/10min/IP via `email_send_attempts`. |

### Locked behaviour

- **No auto-login on success.** Flash success + 303 → `/login?reset=ok`. Security best-practice (forces the user to re-auth on the new password).
- **Invalidate-other-pending on success.** The successful reset also nukes any other pending reset tokens for the user, killing parallel inbox links.
- **CSRF rotation post-reset** (M4).

---

## Email verification

**Soft banner only** — no app features are gated on it. The single-copy banner in [layout.twig](../templates/layout.twig) reads "Please verify your email — [Resend]" regardless of whether the SMTP send succeeded (decision U-3). The `sent_at` column in `email_verification_tokens` distinguishes for ops, never for users.

**Endpoints:**
- `GET /verify-email/{64-hex}` — atomic single-use redeem (B6); `markEmailVerified`; if the redeeming browser holds the session, stamp `Session::set('email_verified_at', now)` so the banner disappears without a re-login (H5). `Referrer-Policy: no-referrer`.
- `POST /me/verify-email/resend` — session-gated; rate-limited 3/10min/user; invalidates pending + reissues + emails.

### Register hook

`AuthController::handleRegister` runs after `$users->create()`:
1. `verifyTokens->issue($id)` — invalidates any older pending in the same txn + writes the new row.
2. `$mailer->sendVerification($email, $urlBuilder->absoluteUrl("/verify-email/{$rawToken}"), $displayName)` — returns `false` on SMTP fail; never throws.
3. On `true`: `$verifyTokens->markSent($row['id'])`. On `false`: silent (the ops log already noted it; the user-facing banner is identical either way).
4. `establishSession(...)` with `emailVerifiedAt: null`.

Registration completes regardless of SMTP outcome.

---

## Household lifecycle

All four endpoints sit on `HouseholdController`.

| Endpoint | Gate | Behaviour |
|---|---|---|
| `POST /household/regenerate-code` | owner | Rotates the join code via `regenerateJoinCode()`. Old code stops working immediately. |
| `POST /household/leave` | member | Owners get 422 with "transfer or delete first" copy. Non-owners call `removeMember`, drop session active-household keys, redirect to another membership (if any) or `/household/setup`. |
| `POST /household/transfer` | owner | Validates target is a current non-owner member; calls `transferOwnership` (atomic with `SELECT … FOR UPDATE` on PG, BL-3); updates session role to `member`; `Csrf::regenerate()` (M4). |
| `POST /household/delete` | owner | Typed-name confirmation; calls `delete()` (FK CASCADE wipes chores, events, members, ledger, …); clears active-household session keys; `Csrf::regenerate()`. |

### Owner-transfer atomicity (B5 + BL-3)

The non-unique partial index `idx_household_members_role` doesn't enforce single-owner. Race-safe transfer needs all of:
1. **Lock the two rows up-front** with `SELECT user_id, role FROM household_members WHERE household_id = :h AND user_id IN (:old, :new) FOR UPDATE` (PG-only; SQLite rejects the keyword so the suffix is empty on SQLite — single-writer makes the lock implicit there).
2. **Re-verify roles inside the lock** — defends against a concurrent rename/transfer.
3. **Promote first, demote second**, both with `WHERE role = …` guards. Two-owner intermediate state is degenerate-OK (transient inside the txn); zero-owner would be degenerate-bad.
4. **Rollback if either UPDATE affects 0 rows** — closes the BL-3 race window.

### Delete cascade

`DELETE FROM households WHERE id = :id` is enough. Every child table FKs `household_id ON DELETE CASCADE`:
- `household_members`, `events`, `event_exceptions`, `chores`, `chore_schedules`, `chore_schedule_pauses`, `chore_schedule_participants`, `chore_points_ledger`, `ical_feed_tokens` (with `scope_household_id ON DELETE CASCADE`).

Typed-name confirmation prevents back-button-mishap deletions.

---

## Session revocation (H1 + round-4 BL-1)

**Middleware:** `App\Auth\SessionRevocationGuard`, piped between Session and Csrf.

**Storage:** `user_password_changes (user_id PK, password_changed_at)` — one row per user, upserted on every password change.

**Predicate matrix (BL-1):**

| `auth_time` | `password_changed_at` | Verdict |
|---|---|---|
| absent | absent | **PASS** — legacy session, no pw-change recorded (permutation a) |
| absent | set | **REVOKE** — legacy session, but pw-changed since v0.5 deploy (permutation b) |
| set | absent | **PASS** — modern session, no pw-change yet (permutation c) |
| set | set | **COMPARE** — revoke iff `auth_time < password_changed_at` (permutation d) |

**User decision U-1** (release-day legacy grandfather): permutation (a) → PASS. The release-day population is just family; a stolen pre-v0.5 cookie stays valid until that user changes their password (at which point permutation b kicks the session). Acceptable tradeoff vs. forcing every existing user to log back in on deploy.

`establishSession()` writes `auth_time = gmdate('Y-m-d H:i:s')` + rotates the CSRF token on every login + register, so modern sessions always satisfy permutation (c) at minimum.

**Revoke side-effects:** `Session::destroy()`, clear the cookie like `AuthController::handleLogout`, redirect 302 → `/login?reason=password_changed`.

---

## App-layer rate limit (H4)

`email_send_attempts (kind, ip_address NULL, user_id NULL, attempted_at)` records every issuance attempt. Two independent buckets:

| `kind` | Bucket | Limit | Key |
|---|---|---|---|
| `password_reset_request` | IP | 5 / 10min | anonymous endpoint |
| `verify_resend` | user_id | 3 / 10min | session-gated endpoint |

The CHECK constraint on `kind` (PG-only smoke test in `AccountLifecyclePgSmokeTest`) ensures only known buckets land in the table.

The rate-limiter operates BEFORE the issue/send work runs — the over-limit branch still returns 200 (no enumeration via status), but quietly skips both the SMTP call and the dummy verify, so the timing floor still kicks in and the response is indistinguishable from a normal "I just sent it" page.

---

## Test architecture

`tests/AppTestCase.php` (the bulk of integration tests) skips Session + Csrf for usability — the test sets `$_SESSION` directly and the form-POST doesn't juggle a token.

`tests/MiddlewareIntegrationTestCase.php` (round-4 H-6) is the small set of tests that need the full pipe:
- `tests/Auth/SessionRevocationGuardTest.php` — all 4 permutations + anonymous.
- `tests/Auth/AccountControllerPwChangeNoSelfRevokeTest.php` — BL-2 regression.
- `tests/Csrf/AnonymousResetCsrfTest.php` — B2 anonymous-CSRF coverage gap.

`tests/Smoke/*PgSmoke*.php` — engine-specific behaviour (CHECK constraints, FK CASCADE on real PG, FOR UPDATE syntax acceptance). Skips on SQLite.

The 1.5s timing floor itself is covered by `tests/Auth/PasswordResetTimingTest.php` (~3s runtime, 2 tests). The rest of the password-reset tests use a 50ms floor (set in AppTestCase) to keep the suite fast.
