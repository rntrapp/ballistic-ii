<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Item;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Forecasts a user's task-completion velocity using an Exponential Moving
 * Average (EMA) over weekly effort-point totals, and flags burnout risk
 * when upcoming committed effort exceeds sustainable capacity.
 *
 * All aggregation is performed at the database layer (SQL SUM grouped by
 * ISO week) — raw item rows are never hydrated into PHP. Numeric work uses
 * BCMath fixed-point arithmetic to avoid IEEE-754 floating-point drift that
 * could spuriously cross the burnout threshold.
 */
final readonly class VelocityForecastingService
{
    /** Number of decimal places carried through every BCMath operation. */
    private const SCALE = 6;

    /** Upper bound of the Fibonacci effort scale. */
    public const MAX_EFFORT = 8;

    /**
     * Generate the distinct Fibonacci numbers ≤ $max using the recurrence
     * Fₙ = Fₙ₋₁ + Fₙ₋₂. Seed is (1, 2) to skip the duplicate leading 1.
     *
     * @return list<int>
     */
    public static function fibonacciScale(int $max = self::MAX_EFFORT): array
    {
        $seq = [];
        [$a, $b] = [1, 2];
        while ($a <= $max) {
            $seq[] = $a;
            [$a, $b] = [$b, $a + $b];
        }

        return $seq;
    }

    /**
     * Produce a full forecast snapshot for the given user.
     *
     * @param  int  $lookbackWeeks  How many historical weeks to feed into the EMA.
     * @param  string  $alpha  Smoothing factor α ∈ (0, 1] as a decimal string.
     *                         Higher α weights recent weeks more heavily.
     * @return array{
     *     weekly_velocity: string,
     *     upcoming_effort: int,
     *     burnout_risk: bool,
     *     success_probability: string,
     *     weekly_history: list<array{week_start: string, effort: int}>,
     *     lookback_weeks: int,
     *     alpha: string,
     * }
     */
    public function forecast(
        User $user,
        int $lookbackWeeks = 8,
        string $alpha = '0.3',
    ): array {
        $now = CarbonImmutable::now();

        $history = $this->weeklyEffortHistory($user, $now, $lookbackWeeks);
        $velocity = $this->exponentialMovingAverage(
            array_column($history, 'effort'),
            $alpha,
        );
        $upcoming = $this->upcomingEffort($user, $now);
        $burnout = $this->isBurnoutRisk($velocity, $upcoming);
        $probability = $this->successProbability($velocity, $upcoming);

        return [
            'weekly_velocity' => $velocity,
            'upcoming_effort' => $upcoming,
            'burnout_risk' => $burnout,
            'success_probability' => $probability,
            'weekly_history' => $history,
            'lookback_weeks' => $lookbackWeeks,
            'alpha' => $alpha,
        ];
    }

    /**
     * Aggregate completed effort per ISO week for the last N weeks.
     *
     * Returns a dense series (one entry per week, zero-filled) ordered
     * oldest→newest so the EMA can walk it directly. The aggregation is
     * a single GROUP BY query; PHP only performs zero-filling, which is
     * O(lookbackWeeks) and independent of item count.
     *
     * @return list<array{week_start: string, effort: int}>
     */
    public function weeklyEffortHistory(
        User $user,
        CarbonImmutable $now,
        int $lookbackWeeks,
    ): array {
        if ($lookbackWeeks < 1) {
            return [];
        }

        // Window: from the start of the week N-1 weeks ago through to the
        // end of the current week. Carbon's startOfWeek() uses the locale
        // default (Monday under ISO-8601).
        $windowStart = $now->startOfWeek()->subWeeks($lookbackWeeks - 1);
        $windowEnd = $now->endOfWeek();

        // Single aggregate query: SUM(effort_score) grouped by completion
        // date truncated to day. We bucket into weeks in PHP because
        // date-truncation syntax differs across PostgreSQL/SQLite and the
        // result set is bounded by lookbackWeeks × 7 rows (≤ 56 for the
        // default 8-week window) — never the full item history.
        $dailyTotals = Item::query()
            ->where('user_id', $user->id)
            ->where('status', 'done')
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$windowStart, $windowEnd])
            ->select(
                DB::raw('DATE(completed_at) as completed_on'),
                DB::raw('SUM(effort_score) as total_effort'),
            )
            ->groupBy('completed_on')
            ->pluck('total_effort', 'completed_on');

        // Build a dense weekly series, zero-filling gaps.
        $series = [];
        $cursor = $windowStart;

        for ($i = 0; $i < $lookbackWeeks; $i++) {
            $weekStart = $cursor;
            $weekEnd = $cursor->endOfWeek();
            $sum = 0;

            // Sum the (at most 7) daily buckets that fall inside this week.
            foreach ($dailyTotals as $day => $effort) {
                if ($day >= $weekStart->toDateString() && $day <= $weekEnd->toDateString()) {
                    $sum += (int) $effort;
                }
            }

            $series[] = [
                'week_start' => $weekStart->toDateString(),
                'effort' => $sum,
            ];

            $cursor = $cursor->addWeek();
        }

        return $series;
    }

    /**
     * Compute the Exponential Moving Average over a series of integer
     * observations using the canonical recurrence:
     *
     *     EMA_t = α · X_t + (1 − α) · EMA_{t-1}
     *
     * The series is expected to be ordered oldest→newest. The seed EMA_0
     * is the first observation. All arithmetic uses BCMath at fixed
     * scale to eliminate floating-point drift.
     *
     * @param  list<int>  $series  Weekly effort totals, oldest→newest.
     * @param  string  $alpha  Smoothing factor as a decimal string, (0, 1].
     * @return string The EMA as a decimal string at {@see self::SCALE} precision.
     *
     * @throws \InvalidArgumentException when α is outside (0, 1].
     */
    public function exponentialMovingAverage(array $series, string $alpha): string
    {
        // Validate α using BCMath comparisons to respect arbitrary precision.
        if (bccomp($alpha, '0', self::SCALE) <= 0
            || bccomp($alpha, '1', self::SCALE) > 0
        ) {
            throw new \InvalidArgumentException(
                "EMA smoothing factor alpha must be in (0, 1]; got {$alpha}"
            );
        }

        if ($series === []) {
            return $this->fixed('0');
        }

        $oneMinusAlpha = bcsub('1', $alpha, self::SCALE);

        // Seed with the first observation.
        $ema = $this->fixed((string) $series[0]);

        $count = count($series);
        for ($t = 1; $t < $count; $t++) {
            $xT = (string) $series[$t];
            // EMA_t = α·X_t + (1-α)·EMA_{t-1}
            $ema = bcadd(
                bcmul($alpha, $xT, self::SCALE),
                bcmul($oneMinusAlpha, $ema, self::SCALE),
                self::SCALE,
            );
        }

        return $ema;
    }

    /**
     * Sum the effort scores of all open items due within the next 7 days.
     *
     * The window is exactly 7 calendar days: [today, today+6], both ends
     * inclusive. Uses a single SQL SUM; returns a native int because effort
     * scores are small bounded integers and the sum cannot overflow
     * PHP_INT_MAX for any realistic workload.
     */
    public function upcomingEffort(User $user, CarbonImmutable $now): int
    {
        $sum = Item::query()
            ->where('user_id', $user->id)
            ->whereNotIn('status', ['done', 'wontdo'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '>=', $now->toDateString())
            ->whereDate('due_date', '<=', $now->addDays(6)->toDateString())
            ->sum('effort_score');

        return (int) $sum;
    }

    /**
     * Determine whether the upcoming workload exceeds sustainable capacity.
     *
     * Capacity is the user's EMA-smoothed weekly velocity. The comparison
     * uses BCMath to avoid a spurious flag from floating-point noise near
     * the threshold.
     */
    public function isBurnoutRisk(string $velocity, int $upcomingEffort): bool
    {
        return bccomp((string) $upcomingEffort, $velocity, self::SCALE) > 0;
    }

    /**
     * Estimate the probability of successfully completing the upcoming
     * workload on time.
     *
     * Model: a clamped capacity-to-load ratio. When load ≤ capacity the
     * probability is 1. As load grows beyond capacity the probability
     * decays proportionally, floored at 0. When the user has zero
     * historical velocity but non-zero load, the probability is 0.
     *
     * @return string Probability in [0, 1] at {@see self::SCALE} precision.
     */
    public function successProbability(string $velocity, int $upcomingEffort): string
    {
        if ($upcomingEffort === 0) {
            return $this->fixed('1');
        }

        if (bccomp($velocity, '0', self::SCALE) <= 0) {
            return $this->fixed('0');
        }

        $ratio = bcdiv($velocity, (string) $upcomingEffort, self::SCALE);

        // Clamp to [0, 1].
        if (bccomp($ratio, '1', self::SCALE) >= 0) {
            return $this->fixed('1');
        }
        if (bccomp($ratio, '0', self::SCALE) <= 0) {
            return $this->fixed('0');
        }

        return $ratio;
    }

    /**
     * Normalise a numeric string to {@see self::SCALE} decimal places.
     */
    private function fixed(string $value): string
    {
        return bcadd($value, '0', self::SCALE);
    }
}
