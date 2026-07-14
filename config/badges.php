<?php

declare(strict_types=1);

/**
 * Presentation map for badge codes across Chores + Tracker (v0.8.3).
 *
 * Registered as a Twig global (`badge_meta`) alongside `brand` so the
 * leaderboards + card walls can render `{{ badge_meta[code].emoji }}`
 * / title without the awarders caring about emoji.
 *
 * Keep in sync with:
 *   - BadgeAwarder constants (Chores — canonical post-v0.6.13).
 *   - TrackerBadgeAwarder constants (Tracker — canonical v0.8.3+).
 *
 * Achievements::badges() is vestigial.
 */
return [
    // Chores (v0.4.3 / v0.6.13 / v0.6.14).
    'first_chore'       => ['emoji' => '🌱', 'title' => 'First chore — completed your first chore'],
    'ten_chores'        => ['emoji' => '⭐', 'title' => 'Getting started — completed 10 chores'],
    'fifty_chores'      => ['emoji' => '🏅', 'title' => 'Hard worker — completed 50 chores'],
    'centurion'         => ['emoji' => '💯', 'title' => 'Centurion — earned 100 points'],
    'five_hundred'      => ['emoji' => '🏆', 'title' => '500 Club — earned 500 points'],
    'four_week_streak'  => ['emoji' => '🔥', 'title' => 'On fire — 4-week streak'],
    'seven_day_streak'  => ['emoji' => '🗓️', 'title' => 'Week strong — 7-day streak'],
    'thirty_day_streak' => ['emoji' => '📅', 'title' => 'Habit formed — 30-day streak'],
    // Tracker (v0.8.3 — effort/consistency, DOCS #73).
    'first_workout'              => ['emoji' => '🏃', 'title' => 'First workout — logged your first exercise'],
    'ten_workouts'               => ['emoji' => '💪', 'title' => 'Ten workouts — logged 10 exercises'],
    'fifty_workouts'             => ['emoji' => '🥇', 'title' => 'Fifty workouts — logged 50 exercises'],
    'five_hundred_met_minutes'   => ['emoji' => '⚡', 'title' => '500 MET-minutes — lifetime effort'],
    'five_thousand_met_minutes'  => ['emoji' => '💥', 'title' => '5,000 MET-minutes — lifetime effort'],
    'active_week'                => ['emoji' => '✅', 'title' => 'Active week — hit 150 MET-min in one week'],
    'four_week_effort_streak'    => ['emoji' => '🔥', 'title' => '4-week effort streak — 150+ MET-min each week'],
    'seven_day_activity_streak'  => ['emoji' => '📆', 'title' => '7-day activity streak — a workout each day'],
    'thirty_day_activity_streak' => ['emoji' => '📈', 'title' => '30-day activity streak — a workout each day'],
];
