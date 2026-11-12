# Chores

Any household member can create, edit, delete, mark done, or reopen any
chore — there's no per-chore ownership lock, just shared trust. A chore
carries a point value (0–1000, 5 by default) that feeds the leaderboard
below.

## One-off chores

Title, points, an optional due date, and an optional assignee. It shows up
under **To do** for whoever it's assigned to (or unassigned, for anyone to
pick up), ordered by due date. Marking it **Done** credits the points to
whoever did it; **Reopen** un-credits them.

## Recurring schedules

A schedule generates real chore occurrences on a repeating basis (daily,
weekly, monthly — by day-of-month or by "2nd Tuesday" style, yearly, or
every N of any of those). Two assignment modes:

- **Rotate** — Mishka cycles through chosen household members, one per
  occurrence.
- **Fixed** — always lands on one specific person.

**Pause** a schedule when it's temporarily not needed (holiday, etc.) —
existing occurrences stay, no new ones generate until you **Resume**.
Resuming rewinds the clock to "now," so a long pause doesn't dump a
backlog of occurrences all at once.

## Points, badges and streaks

Completing a chore credits its points to the doer. Two tallies show on the
leaderboard: points for the current week (resets Monday) and all-time
points. Badges are earned automatically the moment their criterion is
met (first chore, chore-count milestones, points milestones, a four-week
completion streak) — there's no separate "claim" step. The household
roster on the Badges page shows everyone's badge count, since this is
shared, unlike the privacy-protected health data in the
[Health Tracker](health.md).

## Routes

| Route |
|---|
| `/chores` |
| `/chores/new` |
| `/chores/{id}` |
| `/chores/{id}/delete` |
| `/chores/{id}/done` |
| `/chores/{id}/reopen` |
| `/chores/schedules/new` |
| `/chores/schedules` |
| `/chores/schedules/{id}` |
| `/chores/schedules/{id}/delete` |
| `/chores/schedules/{id}/pause` |
| `/chores/schedules/{id}/resume` |
| `/badges` |
