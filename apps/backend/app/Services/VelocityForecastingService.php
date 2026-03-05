<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Item;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Predicts whether a user can realistically complete their scheduled workload
 * by comparing upcoming effort against an Exponential Moving Average of past
 * throughput. See CHANGELOG for the full mathematical strategy document.
 */
final readonly class VelocityForecastingService
{
    /** Number of trailing weeks fed into the EMA. */
    public const int LOOKBACK_WEEKS = 12;

    /** EMA smoothing window N; α = 2 / (N + 1) = 0.4. */
    public const int EMA_PERIOD = 4;

    /** Days in the forward window used to measure upcoming load. */
    public const int HORIZON_DAYS = 7;

    /**
     * Fixed-point scale factor. All intermediate EMA/variance arithmetic is
     * carried out on integers scaled by this factor so IEEE-754 drift cannot
     * push a marginal load (e.g. velocity 10 vs load 10) over the burnout
     * threshold. 10 000 gives four decimal places — far more than effort
     * integers warrant — while staying well inside 64-bit int range.
     */
    private const int PRECISION = 10_000;

    /** Valid Fibonacci effort scores. */
    public const array FIBONACCI = [1, 2, 3, 5, 8];

    /**
     * Produce a complete forecast for the given user at the given moment.
     *
     * @return array{
     *     velocity: float,
     *     std_dev: float,
     *     capacity_upper: float,
     *     upcoming_load: int,
     *     burnout_risk: bool,
     *     probability_of_success: float,
     *     weekly_history: list<int>,
     *     sample_weeks: int,
     * }
     */
    public function forecast(User $user, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $history = $this->aggregateWeeklyEffort($user, $now);
        $load = $this->upcomingLoad($user, $now);

        return $this->computeMetrics($history, $load);
    }

    /**
     * Aggregate completed effort into 7-day buckets counted backwards from
     * $now. Returns a dense, chronologically-ordered list: index 0 is the
     * oldest week, index LOOKBACK_WEEKS-1 is the most recent.
     *
     * All heavy lifting is done in a single GROUP BY at the database layer;
     * PHP only touches the ≤ LOOKBACK_WEEKS rows returned. Weeks with no
     * completions are materialised as explicit zeros so the EMA correctly
     * penalises idle periods.
     *
     * @return list<int>
     */
    public function aggregateWeeklyEffort(User $user, CarbonImmutable $now): array
    {
        $windowStart = $now->subDays(self::LOOKBACK_WEEKS * 7);
        $bucketExpr = $this->bucketExpression($now);

        /** @var array<int, int> $sparse weeks_ago => effort */
        $sparse = Item::query()
            ->where('user_id', $user->id)
            ->where('status', 'done')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>', $windowStart)
            ->where('completed_at', '<=', $now)
            ->groupByRaw($bucketExpr)
            ->selectRaw("{$bucketExpr} AS weeks_ago, COALESCE(SUM(effort_score), 0) AS effort")
            ->pluck('effort', 'weeks_ago')
            ->map(static fn ($v): int => (int) $v)
            ->all();

        // Densify + reverse so index 0 = oldest week (EMA walks forward in time).
        $dense = [];
        for ($w = self::LOOKBACK_WEEKS - 1; $w >= 0; $w--) {
            $dense[] = $sparse[$w] ?? 0;
        }

        return $dense;
    }

    /**
     * Portable SQL expression that maps completed_at → an integer bucket
     * "weeks ago" relative to $now. Production runs on PostgreSQL; the test
     * suite runs on SQLite. Both branches floor to whole weeks via integer
     * arithmetic so bucket boundaries are identical.
     */
    private function bucketExpression(CarbonImmutable $now): string
    {
        $anchor = $now->toDateTimeString();
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'sqlite' => "CAST((julianday('{$anchor}') - julianday(completed_at)) / 7 AS INTEGER)",
            default => "FLOOR(EXTRACT(EPOCH FROM (TIMESTAMP '{$anchor}' - completed_at)) / 604800)::INTEGER",
        };
    }

    /**
     * Total effort of open items falling due within the next HORIZON_DAYS.
     * Single aggregate query — no hydration, no PHP iteration.
     */
    public function upcomingLoad(User $user, CarbonImmutable $now): int
    {
        return (int) Item::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['todo', 'doing'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '>=', $now->toDateString())
            ->whereDate('due_date', '<=', $now->addDays(self::HORIZON_DAYS)->toDateString())
            ->sum('effort_score');
    }

    /**
     * Pure, side-effect-free maths. Accepts pre-aggregated weekly totals so it
     * can be exercised in isolation by unit tests without a database.
     *
     * @param  list<int>  $weeklyEffort  Oldest-first weekly effort sums.
     * @return array{
     *     velocity: float,
     *     std_dev: float,
     *     capacity_upper: float,
     *     upcoming_load: int,
     *     burnout_risk: bool,
     *     probability_of_success: float,
     *     weekly_history: list<int>,
     *     sample_weeks: int,
     * }
     */
    public function computeMetrics(array $weeklyEffort, int $upcomingLoad): array
    {
        $sampleWeeks = count($weeklyEffort);

        // Cold start: no history → no basis for a forecast.
        if ($sampleWeeks === 0) {
            return $this->emptyForecast($upcomingLoad);
        }

        [$emaScaled, $varianceScaled] = $this->emaWithVariance($weeklyEffort);

        $velocity = $emaScaled / self::PRECISION;
        $stdDev = sqrt($varianceScaled) / self::PRECISION;
        $capacityUpper = $velocity + $stdDev;

        // Burnout comparison performed on scaled integers to dodge float drift.
        // load > μ + σ  ⇔  load×P×P > ema×P + σ_scaled×P  (σ_scaled already ×P²)
        $loadScaledSq = $upcomingLoad * self::PRECISION * self::PRECISION;
        $thresholdScaledSq = $emaScaled * self::PRECISION + (int) round(sqrt($varianceScaled) * self::PRECISION);
        $burnout = $loadScaledSq > $thresholdScaledSq;

        return [
            'velocity' => round($velocity, 4),
            'std_dev' => round($stdDev, 4),
            'capacity_upper' => round($capacityUpper, 4),
            'upcoming_load' => $upcomingLoad,
            'burnout_risk' => $burnout,
            'probability_of_success' => $this->probabilityOfSuccess($upcomingLoad, $velocity, $stdDev),
            'weekly_history' => $weeklyEffort,
            'sample_weeks' => $sampleWeeks,
        ];
    }

    /**
     * Single-pass recursive EMA in fixed-point integer arithmetic.
     *
     *   EMA_t = α·X_t + (1-α)·EMA_{t-1},   α = 2/(N+1)
     *
     * Working in scaled integers means 10+10+10+10 → exactly 10.0000, never
     * 9.9999…. Variance is tracked alongside as the EMA of squared residuals,
     * giving an exponentially-weighted σ that matches the velocity's recency
     * bias.
     *
     * @param  non-empty-list<int>  $series
     * @return array{0: int, 1: int} [ema × PRECISION, variance × PRECISION²]
     */
    private function emaWithVariance(array $series): array
    {
        $alphaNum = 2;
        $alphaDen = self::EMA_PERIOD + 1;

        // Seed with first observation — standard EMA initialisation.
        $ema = $series[0] * self::PRECISION;
        $variance = 0;

        $count = count($series);
        for ($t = 1; $t < $count; $t++) {
            $x = $series[$t] * self::PRECISION;
            $residual = $x - $ema;

            // var_t = α·residual² + (1-α)·var_{t-1}
            // residual is already ×PRECISION so residual² is ×PRECISION² — the
            // unit we want variance to live in.
            $variance = intdiv(
                $alphaNum * $residual * $residual + ($alphaDen - $alphaNum) * $variance,
                $alphaDen
            );

            // ema_t = α·x + (1-α)·ema_{t-1}, all terms ×PRECISION
            $ema = intdiv(
                $alphaNum * $x + ($alphaDen - $alphaNum) * $ema,
                $alphaDen
            );
        }

        return [$ema, $variance];
    }

    /**
     * P(weekly throughput ≥ load) under a normal approximation.
     *
     * When σ = 0 (perfectly steady output) the distribution collapses to a
     * point mass: success is certain if load ≤ μ, impossible otherwise.
     */
    private function probabilityOfSuccess(int $load, float $mean, float $stdDev): float
    {
        if ($stdDev <= 0.0) {
            return $load <= $mean ? 1.0 : 0.0;
        }

        $z = ($load - $mean) / $stdDev;

        return round(1.0 - $this->normalCdf($z), 4);
    }

    /**
     * Standard normal CDF via Abramowitz & Stegun 26.2.17 (Zelen & Severo).
     * Max absolute error < 7.5e-8 — more than adequate for UI percentages.
     */
    private function normalCdf(float $z): float
    {
        $p = 0.2316419;
        $b = [0.319381530, -0.356563782, 1.781477937, -1.821255978, 1.330274429];

        $absZ = abs($z);
        $t = 1.0 / (1.0 + $p * $absZ);
        $phi = (1.0 / sqrt(2.0 * M_PI)) * exp(-0.5 * $absZ * $absZ);

        $poly = $t * ($b[0] + $t * ($b[1] + $t * ($b[2] + $t * ($b[3] + $t * $b[4]))));
        $upper = $phi * $poly;

        return $z >= 0.0 ? 1.0 - $upper : $upper;
    }

    /**
     * @return array{
     *     velocity: float,
     *     std_dev: float,
     *     capacity_upper: float,
     *     upcoming_load: int,
     *     burnout_risk: bool,
     *     probability_of_success: float,
     *     weekly_history: list<int>,
     *     sample_weeks: int,
     * }
     */
    private function emptyForecast(int $upcomingLoad): array
    {
        return [
            'velocity' => 0.0,
            'std_dev' => 0.0,
            'capacity_upper' => 0.0,
            'upcoming_load' => $upcomingLoad,
            'burnout_risk' => $upcomingLoad > 0,
            'probability_of_success' => $upcomingLoad > 0 ? 0.0 : 1.0,
            'weekly_history' => [],
            'sample_weeks' => 0,
        ];
    }
}
