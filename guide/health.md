# Health Tracker

A food, exercise and weight log for your own body — private to you within
the household, with one deliberate exception (see Leaderboard below). This
feature doesn't have an official nickname yet, so the guide just calls it
the Health Tracker, matching its `/health` route.

## Your profile

Before the tracker can show you anything meaningful, set your profile:
sex, birth year, and height. These feed a BMR (basal metabolic rate)
calculation, combined with a base activity level you also set here.

## Today

The daily view groups your food log by meal (breakfast, lunch, dinner,
snack) with per-meal and whole-day calorie totals, lists your exercise log
entries, and shows an energy-balance widget: net = intake − expenditure,
where expenditure is your BMR plus your activity-level adjustment plus
logged exercise. Until you've completed your profile and logged at least
one weight entry, you'll see a prompt to do that first instead of the full
picture.

## Logging food and exercise

Food and exercise each have a household-shared catalog you (or anyone in
your household) can add custom entries to, plus your own personal log —
search the catalog, log an entry for the day, or delete a logged entry
later.

## Weight

A simple log of weight entries over time, with delete.

## Leaderboard

Ranks the household by **effort only** — weekly MET-minutes from logged
exercise duration, plus a strength-session count, streaks and badges.
This is a deliberate, enforced boundary: your food intake, weight, and
energy balance never appear here or anywhere another household member can
see them. Only effort is shared; everything else about your health data
stays private to you.

## Routes

| Route |
|---|
| `/health` |
| `/health/profile` |
| `/health/weight` |
| `/health/weight/{id}/delete` |
| `/health/foods` |
| `/health/foods/new` |
| `/health/foods/{id}` |
| `/health/foods/{id}/delete` |
| `/health/exercises` |
| `/health/exercises/new` |
| `/health/exercises/{id}` |
| `/health/exercises/{id}/delete` |
| `/health/log/food` |
| `/health/log/food/search` |
| `/health/log/food/{id}/delete` |
| `/health/log/exercise` |
| `/health/log/exercise/search` |
| `/health/log/exercise/{id}/delete` |
| `/health/leaderboard` |
