<?php

declare(strict_types=1);

namespace App\Calendar;

/**
 * Result envelope returned by EventService::updateSeries.
 *
 * Four shapes the controller routes on:
 *   - 'ok'                        — series was updated cleanly. Redirect.
 *   - 'requires_cascade_confirm'  — clean time-shift with overrides; render
 *                                   the cascade-confirm dialog with `affected`
 *                                   so the user sees what will shift
 *   - 'requires_drop_confirm'     — structural change with overrides; render
 *                                   the drop-confirm dialog with `affected`
 *                                   so the user sees what will be deleted
 *   - 'stale_data'                — `expectedExceptionCount` no longer matches;
 *                                   someone added/removed an exception while
 *                                   the dialog was open. Re-render with fresh
 *                                   count + stale-data warning.
 *
 * `affected` is a pretty-formatted list of exception summaries (date + before
 * /after description). The dialogs render it as a `<ul>`; the controller and
 * service don't shape HTML.
 */
final class UpdateResult
{
    /**
     * @param 'ok'|'requires_cascade_confirm'|'requires_drop_confirm'|'stale_data' $status
     * @param list<array{date: string, summary: string}> $affected
     */
    public function __construct(
        public readonly string $status,
        public readonly int $exceptionCount = 0,
        public readonly array $affected = [],
    ) {}
}
