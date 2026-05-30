<?php

declare(strict_types=1);

namespace App\Calendar;

/**
 * Preset → RRULE string translator for the event form (v0.3.1).
 *
 * The form exposes five presets — none / daily / weekly / monthly / yearly —
 * plus a couple of dependent fields (BYDAY checkboxes for weekly,
 * monthly_day for monthly, optional INTERVAL). Cuts UNTIL/COUNT entirely;
 * v0.3.1 rules run forever like phone calendars.
 *
 * Monthly day is clamped to 1–28 to stay valid across all months. If the
 * user wants "last day of month" they'll wait for the v0.5+ "Custom..."
 * builder; for v0.3.1 the simpler rule wins.
 *
 * Yearly emits a bare `FREQ=YEARLY`. simshaun/recurr derives the month +
 * day from DTSTART, so editing the start date naturally moves the yearly
 * recurrence with it — the "birthday" intent users expect.
 *
 * toForm() round-trips the supported shapes; anything else returns
 * `['preset' => 'custom']` so the edit form can still render gracefully
 * (the user just sees a "Custom recurrence" indicator and edits via the
 * dropdown without losing the RRULE).
 */
final class RruleTranslator
{
    private const VALID_BYDAY = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];
    private const MAP_DOW_TO_BYDAY = [
        1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU',
    ];
    /** RFC 5545 positional prefixes for monthly BYDAY: 1st-4th + last. */
    private const VALID_MONTHLY_DOW_POSITIONS = [1, 2, 3, 4, -1];

    /**
     * @param array{
     *     preset?: string,
     *     interval?: int|string,
     *     byday?: list<string>,
     *     monthly_day?: int|string,
     *     monthly_mode?: string,
     *     monthly_dow_position?: int|string,
     *     monthly_dow_day?: string,
     * } $form
     */
    public function fromForm(array $form, \DateTimeImmutable $startsAtLocal): ?string
    {
        $preset = (string) ($form['preset'] ?? 'none');

        return match ($preset) {
            'none' => null,
            'daily' => 'FREQ=DAILY',
            'weekly' => $this->buildWeekly($form, $startsAtLocal),
            'monthly' => $this->buildMonthly($form, $startsAtLocal),
            'yearly' => 'FREQ=YEARLY',
            default => null,
        };
    }

    /**
     * @return array{preset: string, interval: int, byday: list<string>,
     *               monthly_day: int, monthly_mode: string,
     *               monthly_dow_position: int, monthly_dow_day: string}
     */
    public function toForm(?string $rrule): array
    {
        $default = [
            'preset' => 'none',
            'interval' => 1,
            'byday' => [],
            'monthly_day' => 1,
            // Default monthly sub-mode is 'day' (BYMONTHDAY=N); 'dow' is the
            // positional day-of-week alternative (BYDAY=1FR etc.). Defaults
            // satisfy the template even when the active preset isn't monthly.
            'monthly_mode' => 'day',
            'monthly_dow_position' => 1,
            'monthly_dow_day' => 'MO',
        ];

        if ($rrule === null || trim($rrule) === '') {
            return $default;
        }

        $parts = $this->parseRrule($rrule);
        $supportedKeys = ['FREQ', 'INTERVAL', 'BYDAY', 'BYMONTHDAY'];
        foreach (array_keys($parts) as $key) {
            if (!in_array($key, $supportedKeys, true)) {
                $default['preset'] = 'custom';
                return $default;
            }
        }

        $freq = $parts['FREQ'] ?? '';
        $interval = isset($parts['INTERVAL']) ? max(1, (int) $parts['INTERVAL']) : 1;

        if ($freq === 'MONTHLY') {
            return $this->monthlyToForm($parts, $interval, $default);
        }

        return match ($freq) {
            'DAILY' => ['preset' => 'daily'] + $default,
            'WEEKLY' => [
                'preset' => 'weekly',
                'interval' => $interval,
                'byday' => $this->parseByDay($parts['BYDAY'] ?? ''),
            ] + $default,
            'YEARLY' => ['preset' => 'yearly'] + $default,
            default => ['preset' => 'custom'] + $default,
        };
    }

    /**
     * Decide which monthly sub-shape this RRULE represents:
     *   - BYDAY=NDD (positional day-of-week)  → monthly_mode = 'dow'
     *   - BYMONTHDAY=N                         → monthly_mode = 'day' (default)
     *   - Both keys present                    → preset = 'custom' (ambiguous)
     *   - Neither                              → monthly_mode = 'day' (default)
     *
     * @param array<string, string> $parts
     * @param array<string, mixed>  $default
     * @return array{preset: string, interval: int, byday: list<string>,
     *               monthly_day: int, monthly_mode: string,
     *               monthly_dow_position: int, monthly_dow_day: string}
     */
    private function monthlyToForm(array $parts, int $interval, array $default): array
    {
        $hasByDay = isset($parts['BYDAY']) && $parts['BYDAY'] !== '';
        $hasByMonthDay = isset($parts['BYMONTHDAY']) && $parts['BYMONTHDAY'] !== '';

        if ($hasByDay && $hasByMonthDay) {
            // Ambiguous combo (e.g., "every 1st Friday on the 13th") — not a
            // shape this form can represent cleanly; fall back to custom.
            return ['preset' => 'custom'] + $default;
        }

        if ($hasByDay) {
            $pos = $this->parsePositionalByDay($parts['BYDAY']);
            if ($pos === null) {
                // BYDAY present but not a single positional code (e.g.,
                // BYDAY=MO,WE in monthly — weekday recurrence, not supported
                // by either monthly sub-mode). Fall back to custom.
                return ['preset' => 'custom'] + $default;
            }
            return [
                'preset' => 'monthly',
                'interval' => $interval,
                'byday' => [],
                'monthly_day' => 1,
                'monthly_mode' => 'dow',
                'monthly_dow_position' => $pos['position'],
                'monthly_dow_day' => $pos['day'],
            ];
        }

        // Plain `FREQ=MONTHLY[;BYMONTHDAY=N]` — the existing day-of-month mode.
        return [
            'preset' => 'monthly',
            'interval' => $interval,
            'byday' => [],
            'monthly_day' => $hasByMonthDay
                ? max(1, min(28, (int) $parts['BYMONTHDAY']))
                : 1,
            'monthly_mode' => 'day',
            'monthly_dow_position' => 1,
            'monthly_dow_day' => 'MO',
        ];
    }

    /**
     * Parse a single positional BYDAY value (`1FR`, `-1MO`, `4TH`, …) into
     * its numeric prefix and the day code. Returns null if the input isn't
     * a single positional code we accept (e.g., plain `FR`, multi-day list,
     * out-of-range position like `5MO`).
     *
     * @return array{position: int, day: string}|null
     */
    private function parsePositionalByDay(string $byday): ?array
    {
        $trimmed = strtoupper(trim($byday));
        if (str_contains($trimmed, ',')) {
            return null;   // multi-day list — not a single positional code
        }
        if (!preg_match('/^(-?\d+)(MO|TU|WE|TH|FR|SA|SU)$/', $trimmed, $m)) {
            return null;
        }
        $position = (int) $m[1];
        if (!in_array($position, self::VALID_MONTHLY_DOW_POSITIONS, true)) {
            // Out-of-range (e.g., `5FR`, `-2MO`) → custom-shape fallback.
            return null;
        }
        return ['position' => $position, 'day' => $m[2]];
    }

    /**
     * @param array{byday?: list<string>, interval?: int|string} $form
     */
    private function buildWeekly(array $form, \DateTimeImmutable $startsAtLocal): string
    {
        $byday = $this->normaliseByDay($form['byday'] ?? []);
        if ($byday === []) {
            $byday = [self::MAP_DOW_TO_BYDAY[(int) $startsAtLocal->format('N')]];
        }

        $interval = isset($form['interval']) ? max(1, (int) $form['interval']) : 1;

        $rrule = 'FREQ=WEEKLY';
        if ($interval > 1) {
            $rrule .= ";INTERVAL={$interval}";
        }
        $rrule .= ';BYDAY=' . implode(',', $byday);
        return $rrule;
    }

    /**
     * @param array{
     *     monthly_day?: int|string,
     *     interval?: int|string,
     *     monthly_mode?: string,
     *     monthly_dow_position?: int|string,
     *     monthly_dow_day?: string,
     * } $form
     */
    private function buildMonthly(array $form, \DateTimeImmutable $startsAtLocal): string
    {
        $interval = isset($form['interval']) ? max(1, (int) $form['interval']) : 1;
        $rrule = 'FREQ=MONTHLY';
        if ($interval > 1) {
            $rrule .= ";INTERVAL={$interval}";
        }

        $mode = (string) ($form['monthly_mode'] ?? 'day');
        if ($mode === 'dow') {
            // Positional day-of-week: e.g., "1st Friday" → BYDAY=1FR;
            // "Last Sunday" → BYDAY=-1SU. Validation falls back to a
            // safe default (1st of the same DOW as the anchor) if the
            // form supplied garbage.
            $position = (int) ($form['monthly_dow_position'] ?? 1);
            if (!in_array($position, self::VALID_MONTHLY_DOW_POSITIONS, true)) {
                $position = 1;
            }
            $day = strtoupper((string) ($form['monthly_dow_day'] ?? ''));
            if (!in_array($day, self::VALID_BYDAY, true)) {
                $day = self::MAP_DOW_TO_BYDAY[(int) $startsAtLocal->format('N')];
            }
            return $rrule . ";BYDAY={$position}{$day}";
        }

        // Default 'day' mode: BYMONTHDAY=N (clamped 1-28).
        $day = isset($form['monthly_day'])
            ? (int) $form['monthly_day']
            : (int) $startsAtLocal->format('j');
        $day = max(1, min(28, $day));
        return $rrule . ";BYMONTHDAY={$day}";
    }

    /**
     * @param list<string> $input
     * @return list<string>
     */
    private function normaliseByDay(array $input): array
    {
        $out = [];
        foreach ($input as $code) {
            $code = strtoupper(trim($code));
            if (in_array($code, self::VALID_BYDAY, true) && !in_array($code, $out, true)) {
                $out[] = $code;
            }
        }
        // Keep canonical day-of-week order (Mon → Sun) for stable output
        $rankByDay = array_flip(self::VALID_BYDAY);
        usort($out, fn(string $a, string $b): int => $rankByDay[$a] <=> $rankByDay[$b]);
        return $out;
    }

    /** @return list<string> */
    private function parseByDay(string $byday): array
    {
        if ($byday === '') {
            return [];
        }
        return $this->normaliseByDay(explode(',', $byday));
    }

    /**
     * @return array<string, string>
     */
    private function parseRrule(string $rrule): array
    {
        $parts = [];
        foreach (explode(';', $rrule) as $pair) {
            if (str_contains($pair, '=')) {
                [$k, $v] = explode('=', $pair, 2);
                $parts[strtoupper(trim($k))] = trim($v);
            }
        }
        return $parts;
    }
}
