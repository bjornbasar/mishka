# Mishka — Roadmap

> Priority-sequenced view of what ships next. Update this file as releases land or priorities shift.
> Last reviewed: 2026-07-15 (after v0.8.4 shipped — **Tracker train complete**).

## Current release

**v0.8.4** — Tracker Phase 5: offline logging + PWA shortcuts. Shipped 2026-07-15. See `DOCS.md` decision #74 + `docs/TRACKER.md` §13. All 5 Tracker phases (v0.8.0-v0.8.4) now shipped.

## Priority queue

| # | Version | Scope | Effort | Blocker? |
|---|---|---|---|---|
| 1 | **v1.0+** | Non-root container user (DOCS #64 v1.0+ candidate); pairs with any "mishka outside the family" pivot. Also revisit `chmod 733` on `/var/lib/mishka/sessions` per v0.7.6 tripwire (mode-733 breaks for `www-data` because it can't stat existing session files) | 1-2 days | Not blocking anything |

**Tracker train total (v0.8.0–v0.8.3, no bonus): 21-31 dev days.** Roughly 5-7 weeks of focused solo work.

**Not on the current queue** (parked, needs a real trigger to promote):
- Tracker one-way import from Kuma recipes (`docs/TRACKER-PLAN.md` §9 "later")
- Additional badge tiers or streak variants
- Any of the "v1.0+" candidates that aren't the non-root user pivot

## Tracker (v0.8.x) — locked decisions

Fold-back from `docs/TRACKER-PLAN.md` §11 Open Decisions. A fresh session picking up v0.8.0 should honour these without re-asking:

| Decision | Locked value | Why |
|---|---|---|
| Module / brand name | **Internal namespace: `Tracker`. UI label: `Health`.** | mishka's convention is plain functional English for module namespaces (Chores/Calendar/Mail/etc.) — Tracker fits. UI label `Health` leaves room to grow later (sleep, mood, etc.) under the same nav slot without renaming code. |
| Leaderboard weighting (v0.8.3) | **Start with pure MET-minutes. Evaluate for 2-3 weeks. Add a consistency multiplier ONLY if the raw number feels wrong.** | Ship the simplest currency. Real-world signal beats a-priori weighting. Fair across bodyweights out of the box. |
| Seed data licensing (v0.8.0) | **Ship derived per-serving kcal + `source` attribution only.** No raw datasets checked into git. | Safe under PhilFCT + FOODfiles + USDA terms. Lightweight repo. Every seeded dish carries `foods.source IN ('philfct','nzfcd','usda','custom')` so provenance is queryable. |
| Household bowl calibration | **Fixed household default grams per serving; editable per dish via the library UI.** No per-user override table. | Handoff doc §3.2's "consistency beats lab accuracy". Users edit `food_servings.grams` once when their bowl differs from seed default. Zero extra schema. |
| Version slot | **v0.7.6 + v0.7.7 housekeeping stays inside v0.7 line. Tracker starts fresh at v0.8.0.** | Matches handoff doc's assumption. Housekeeping is ~1.5 days combined — fast enough to close before the tracker train launches on a clean platform. |

## How to work from this document

1. Before starting a release, re-read the row here + the referenced DOCS.md decision(s) it closes.
2. For tracker releases (v0.8.x), also read `docs/TRACKER-PLAN.md` §7 (data model) + §8 (module layout) + §9 (release-train breakdown) + `docs/CHORES.md` (the canonical reuse target) before drafting the plan.
3. Update this file after each release lands: strike-through the completed row, promote whatever was "not on the current queue" if priorities shifted.
4. Blockers between rows are gates, not soft suggestions — v0.8.1 waits on v0.8.0 because it consumes the `foods` model; v0.8.3 waits on v0.8.1+v0.8.2 because the leaderboard needs the MET-minute source.

## Ledger of past releases (most recent first)

- **v0.8.4** — Tracker Phase 5: offline logging + PWA shortcuts (DOCS #74, 2026-07-15)
- **v0.8.3** — Tracker Phase 4: household effort leaderboard + effort/consistency badges + streaks (DOCS #73, 2026-07-14)
- **v0.8.2** — Tracker Phase 3: `tracker_profiles` + Mifflin-St Jeor BMR + Today energy-balance widget (DOCS #72, 2026-07-13)
- **v0.8.1** — Tracker Phase 2: exercise catalog + logging (duration + strength) + weight_log + kcal (DOCS #71, 2026-07-13)
- **v0.8.0** — Tracker Phase 1: dish library + serving-first food logging + 41-dish seed + live search (DOCS #70, 2026-07-13)
- **v0.7.7** — family "stay logged in" (30-day session gc + 30-day cookie Max-Age) (DOCS #69, 2026-07-12)
- **v0.7.6** — multi-stage docker image + `default_socket_timeout=5` ini pin (DOCS #68, 2026-07-12)
- **v0.7.5** — real email delivery via Workspace SMTP relay (DOCS #67, 2026-07-11)
- **v0.7.4** — client-side double-submit prevention hotfix (DOCS #66)
- **v0.7.3** — persistent PHP session storage hotfix (DOCS #65)
- **v0.7.2** — self-contained docker image (DOCS #64)
- **v0.7.1** — auto-migrate + `schema_versions` audit (DOCS #63)
- **v0.7.0** — per-device session revoke UI (`/me/sessions`)

Older releases: see `DOCS.md` decision log + README `## Status` stanzas.
