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

    /**
     * @param array{
     *     preset?: string,
     *     interval?: int|string,
     *     byday?: list<string>,
     *     monthly_day?: int|string,
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
     * @return array{preset: string, interval: int, byday: list<string>, monthly_day: int}
     */
    public function toForm(?string $rrule): array
    {
        $default = [
            'preset' => 'none',
            'interval' => 1,
            'byday' => [],
            'monthly_day' => 1,
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

        return match ($freq) {
            'DAILY' => ['preset' => 'daily'] + $default,
            'WEEKLY' => [
                'preset' => 'weekly',
                'interval' => $interval,
                'byday' => $this->parseByDay($parts['BYDAY'] ?? ''),
                'monthly_day' => 1,
            ],
            'MONTHLY' => [
                'preset' => 'monthly',
                'interval' => 1,
                'byday' => [],
                'monthly_day' => isset($parts['BYMONTHDAY'])
                    ? max(1, min(28, (int) $parts['BYMONTHDAY']))
                    : 1,
            ],
            'YEARLY' => [
                'preset' => 'yearly',
                'interval' => 1,
                'byday' => [],
                'monthly_day' => 1,
            ],
            default => ['preset' => 'custom'] + $default,
        };
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
     * @param array{monthly_day?: int|string, interval?: int|string} $form
     */
    private function buildMonthly(array $form, \DateTimeImmutable $startsAtLocal): string
    {
        $day = isset($form['monthly_day'])
            ? (int) $form['monthly_day']
            : (int) $startsAtLocal->format('j');
        $day = max(1, min(28, $day));

        $interval = isset($form['interval']) ? max(1, (int) $form['interval']) : 1;

        $rrule = 'FREQ=MONTHLY';
        if ($interval > 1) {
            $rrule .= ";INTERVAL={$interval}";
        }
        $rrule .= ";BYMONTHDAY={$day}";
        return $rrule;
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
