<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Item;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Forecasts a user's task-completion velocity using an Exponential Moving
 * Average (EMA) of weekly effort points, then compares that capacity against
 * the effort required for upcoming due items to derive a burnout risk signal
 * and a probability of meeting all deadlines.
 *
 * All arithmetic is performed with BCMath at a fixed scale to eliminate
 * binary floating-point drift; the EMA intermediate values can therefore be
 * asserted to an exact string in unit tests.
 *
 * All aggregations are performed in the database (SUM + date bucket) rather
 * than hydrating entire Item collections into PHP memory, so the service
 * remains O(weeks) regardless of how many items a user has completed.
 */
final readonly class VelocityForecastingService
{
    /**
     * Number of decimal places for BCMath intermediate calculations.
     * Chosen high enough that the rounded public output (4 dp) is stable
     * across every ordering of operands.
     */
    private const BCSCALE = 12;

    /**
     * Decimal places returned to API consumers.
     */
    private const OUTPUT_SCALE = 4;

    /**
     * Forecast horizon in days for the upcoming-effort window and the
     * matching velocity capacity comparison.
     */
    private const HORIZON_DAYS = 7;

    /**
     * Maximum number of historical weeks the EMA considers. Beyond this the
     * weight of an observation falls below rounding significance for any
     * sensible alpha.
     */
    private const MAX_LOOKBACK_WEEKS = 26;

    /**
     * Default smoothing factor alpha. With N = MAX_LOOKBACK_WEEKS this is
     * the conventional 2/(N+1) value, but the caller may override for tests
     * or user preference.
     */
    public const DEFAULT_ALPHA = '0.2';

    /**
     * Build the complete forecast payload for a user.
     *
     * @return array{
     *     weekly_velocity_ema: string,
     *     upcoming_effort: int,
     *     success_probability: string,
     *     burnout_risk: bool,
     *     alpha: string,
     *     history_weeks: int,
     *     weekly_history: list<array{week_start: string, effort: int}>
     * }
     */
    public function forecast(
        string $userId,
        ?CarbonImmutable $now = null,
        string $alpha = self::DEFAULT_ALPHA,
    ): array {
        $now = $now ?? CarbonImmutable::now();
        $alpha = $this->normaliseAlpha($alpha);

        $weeklyHistory = $this->aggregateWeeklyEffort($userId, $now);
        $historyPoints = array_column($weeklyHistory, 'effort');

        $emaVelocity = $this->exponentialMovingAverage($historyPoints, $alpha);
        $upcomingEffort = $this->sumUpcomingEffort($userId, $now);
        $probability = $this->calculateSuccessProbability($emaVelocity, $upcomingEffort);
        $burnout = $this->isBurnoutRisk($emaVelocity, $upcomingEffort);

        return [
            'weekly_velocity_ema' => $this->round($emaVelocity),
            'upcoming_effort' => $upcomingEffort,
            'success_probability' => $this->round($probability),
            'burnout_risk' => $burnout,
            'alpha' => $this->round($alpha),
            'history_weeks' => count($weeklyHistory),
            'weekly_history' => $weeklyHistory,
        ];
    }

    /**
     * Compute EMA over an ordered series of integer effort-point observations
     * (oldest first). Returns a BCMath string at BCSCALE precision.
     *
     * EMA_t = alpha * X_t + (1 - alpha) * EMA_{t-1}
     *
     * Seeds with the first observation so EMA_0 = X_0, which is the textbook
     * initialisation that avoids an artificial bias toward zero.
     *
     * @param  list<int>  $series  Oldest observation first.
     */
    public function exponentialMovingAverage(array $series, string $alpha): string
    {
        if ($series === []) {
            return $this->zero();
        }

        $alpha = $this->normaliseAlpha($alpha);
        $oneMinusAlpha = bcsub('1', $alpha, self::BCSCALE);

        // Seed EMA_0 = X_0
        $ema = bcadd((string) $series[0], '0', self::BCSCALE);

        $count = count($series);
        for ($i = 1; $i < $count; $i++) {
            $term1 = bcmul($alpha, (string) $series[$i], self::BCSCALE);
            $term2 = bcmul($oneMinusAlpha, $ema, self::BCSCALE);
            $ema = bcadd($term1, $term2, self::BCSCALE);
        }

        return $ema;
    }

    /**
     * Probability of meeting all upcoming deadlines.
     *
     * Model: upcoming_effort / capacity yields a load factor. Anything at or
     * below 1.0 is treated as certain success; above 1.0 the probability
     * decays linearly, clamped to [0, 1]. This is intentionally simple and
     * fully deterministic so UI can reproduce the value optimistically.
     *
     * When capacity is zero but load exists, probability is zero; when both
     * are zero (nothing to do, no history) the probability is 1.0.
     */
    public function calculateSuccessProbability(string $capacity, int $upcomingEffort): string
    {
        $effortStr = (string) $upcomingEffort;

        if ($upcomingEffort <= 0) {
            return bcadd('1', '0', self::BCSCALE);
        }

        if (bccomp($capacity, '0', self::BCSCALE) <= 0) {
            return $this->zero();
        }

        $load = bcdiv($effortStr, $capacity, self::BCSCALE);

        if (bccomp($load, '1', self::BCSCALE) <= 0) {
            return bcadd('1', '0', self::BCSCALE);
        }

        // Linear decay: p = max(0, 2 - load). Load of 2.0 yields 0.
        $p = bcsub('2', $load, self::BCSCALE);

        return bccomp($p, '0', self::BCSCALE) < 0
            ? $this->zero()
            : $p;
    }

    /**
     * Burnout is flagged whenever the upcoming effort strictly exceeds the
     * user's EMA capacity. Equality is treated as safe (you are exactly on
     * pace) to avoid a drift-induced false positive around the boundary.
     */
    public function isBurnoutRisk(string $capacity, int $upcomingEffort): bool
    {
        return bccomp((string) $upcomingEffort, $capacity, self::BCSCALE) > 0;
    }

    /**
     * Aggregate completed effort into ISO-week buckets entirely in SQL.
     *
     * Uses strftime('%Y-%W', ...) on SQLite and DATE_TRUNC / YEARWEEK on
     * PostgreSQL / MySQL via a driver-aware expression. Only a single
     * lightweight row per week is returned; raw items never leave the DB.
     *
     * Weeks with zero completions are synthesised client-side as explicit
     * zeros so the EMA correctly decays during idle periods.
     *
     * @return list<array{week_start: string, effort: int}> Oldest week first.
     */
    public function aggregateWeeklyEffort(string $userId, CarbonImmutable $now): array
    {
        $endExclusive = $now->startOfWeek(CarbonImmutable::MONDAY);
        $start = $endExclusive->subWeeks(self::MAX_LOOKBACK_WEEKS);

        $driver = DB::connection()->getDriverName();
        $bucket = $this->weekBucketExpression($driver);

        /** @var array<string, int> $raw */
        $raw = Item::query()
            ->where('user_id', $userId)
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $start)
            ->where('completed_at', '<', $endExclusive)
            ->whereNull('deleted_at')
            ->groupByRaw($bucket)
            ->orderByRaw($bucket)
            ->selectRaw("{$bucket} AS bucket, COALESCE(SUM(effort_score), 0) AS total")
            ->pluck('total', 'bucket')
            ->map(static fn ($v) => (int) $v)
            ->all();

        // Determine the oldest observed bucket so we don't emit a long run of
        // leading zeros for new users — the EMA is seeded from their first
        // active week. If the user has no history, return an empty series.
        if ($raw === []) {
            return [];
        }

        $observedStarts = array_keys($raw);
        sort($observedStarts);
        $firstObserved = CarbonImmutable::parse($observedStarts[0]);

        $history = [];
        $cursor = $firstObserved;
        while ($cursor->lt($endExclusive)) {
            $key = $cursor->toDateString();
            $history[] = [
                'week_start' => $key,
                'effort' => $raw[$key] ?? 0,
            ];
            $cursor = $cursor->addWeek();
        }

        return $history;
    }

    /**
     * Sum of effort_score for open items due within the half-open interval
     * [today, today + HORIZON_DAYS). With HORIZON_DAYS = 7 this is exactly
     * 7 calendar days: today through today+6 inclusive, excluding today+7.
     * Runs entirely as a single aggregated SQL query.
     */
    public function sumUpcomingEffort(string $userId, CarbonImmutable $now): int
    {
        $windowEnd = $now->copy()->addDays(self::HORIZON_DAYS)->startOfDay();

        return (int) Item::query()
            ->where('user_id', $userId)
            ->whereNotNull('due_date')
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['done', 'wontdo'])
            ->where('due_date', '>=', $now->startOfDay())
            ->where('due_date', '<', $windowEnd)
            ->sum('effort_score');
    }

    /**
     * Driver-specific SQL snippet that collapses completed_at to the Monday
     * of its ISO week as a YYYY-MM-DD string so bucket keys are identical
     * across engines.
     */
    private function weekBucketExpression(string $driver): string
    {
        return match ($driver) {
            // SQLite: subtract dayofweek (Mon=1..Sun=0) offset to reach Monday.
            // strftime('%w') returns 0=Sunday..6=Saturday; mapping to
            // Monday-start means subtracting ((dow + 6) % 7) days.
            'sqlite' => "date(completed_at, '-' || ((strftime('%w', completed_at) + 6) % 7) || ' days')",
            'pgsql' => "to_char(date_trunc('week', completed_at), 'YYYY-MM-DD')",
            // MySQL / MariaDB: WEEKDAY() returns 0=Mon..6=Sun
            default => "DATE_FORMAT(DATE_SUB(completed_at, INTERVAL WEEKDAY(completed_at) DAY), '%Y-%m-%d')",
        };
    }

    /**
     * Clamp the caller-provided alpha to the open interval (0, 1] and
     * normalise to BCSCALE precision.
     *
     * Input is canonicalised to fixed-point decimal first because Laravel's
     * 'numeric' validator accepts scientific notation ("2e-1", "1E-2") but
     * BCMath throws ValueError on anything outside [+-]?[0-9]*\.?[0-9]+.
     * Since alpha ∈ (0, 1], IEEE 754 double precision (≈15 sigfigs) is
     * lossless at 12-dp — no BCMath-vs-float precision trade-off here.
     */
    private function normaliseAlpha(string $alpha): string
    {
        // %F is locale-insensitive fixed-point; avoids "0,2" under e.g. de_DE.
        $canonical = sprintf('%.'.self::BCSCALE.'F', (float) $alpha);

        $normalised = bcadd($canonical, '0', self::BCSCALE);

        if (bccomp($normalised, '0', self::BCSCALE) <= 0) {
            return bcadd(self::DEFAULT_ALPHA, '0', self::BCSCALE);
        }

        if (bccomp($normalised, '1', self::BCSCALE) > 0) {
            return bcadd('1', '0', self::BCSCALE);
        }

        return $normalised;
    }

    /**
     * Round a BCMath string to the public output precision using half-up
     * rounding so repeated calls always produce identical strings.
     */
    private function round(string $value): string
    {
        if (bccomp($value, '0', self::BCSCALE) >= 0) {
            $half = '0.'.str_repeat('0', self::OUTPUT_SCALE).'5';
            $adjusted = bcadd($value, $half, self::BCSCALE);
        } else {
            $half = '-0.'.str_repeat('0', self::OUTPUT_SCALE).'5';
            $adjusted = bcadd($value, $half, self::BCSCALE);
        }

        return bcadd($adjusted, '0', self::OUTPUT_SCALE);
    }

    private function zero(): string
    {
        return bcadd('0', '0', self::BCSCALE);
    }
}
