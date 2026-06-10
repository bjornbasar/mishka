<?php

declare(strict_types=1);

/**
 * Presentation map for the v0.4.3 badge codes returned by App\Chores\Achievements.
 * Registered as a Twig global (`badge_meta`) alongside `brand` so the leaderboard
 * macro can render `{{ badge_meta[code].emoji }}` / title without Achievements
 * caring about emoji.
 *
 * Keep in sync with BadgeAwarder constants (canonical post-v0.6.13).
 * Achievements::badges() is vestigial.
 */
return [
    'first_chore'       => ['emoji' => '🌱', 'title' => 'First chore — completed your first chore'],
    'ten_chores'        => ['emoji' => '⭐', 'title' => 'Getting started — completed 10 chores'],
    'fifty_chores'      => ['emoji' => '🏅', 'title' => 'Hard worker — completed 50 chores'],
    'centurion'         => ['emoji' => '💯', 'title' => 'Centurion — earned 100 points'],
    'five_hundred'      => ['emoji' => '🏆', 'title' => '500 Club — earned 500 points'],
    'four_week_streak'  => ['emoji' => '🔥', 'title' => 'On fire — 4-week streak'],
    'seven_day_streak'  => ['emoji' => '🗓️', 'title' => 'Week strong — 7-day streak'],
    'thirty_day_streak' => ['emoji' => '📅', 'title' => 'Habit formed — 30-day streak'],
];
