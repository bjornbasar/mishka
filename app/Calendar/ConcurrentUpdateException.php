<?php

declare(strict_types=1);

namespace App\Calendar;

/**
 * Thrown by EventRepository::update when the row's current updated_at no
 * longer matches the value the form was rendered with — i.e., someone else
 * (or another tab) edited the same event between form render and submit.
 *
 * The controller catches this and re-renders with a stale-data partial that
 * includes a "View current event" link. The user re-opens the edit form
 * with fresh state and decides whether their change still makes sense.
 *
 * No auto-retry, no diff-merge UI — those land in v0.4+ if real conflicts
 * become a pattern.
 */
final class ConcurrentUpdateException extends \RuntimeException
{
    public function __construct(
        string $message = 'Concurrent update detected — refresh and retry',
    ) {
        parent::__construct($message);
    }
}
