<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Item;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Forecasts a user's capacity to complete upcoming work using an Exponential
 * Moving Average of historical weekly throughput and a normal-distribution
 * probability model.
 *
 * Heavy aggregation is pushed to SQL; PHP only iterates over a fixed, bounded
 * window of pre-aggregated weekly buckets (LOOKBACK_WEEKS rows, not N items).
 */
final readonly class VelocityForecastingService
{
    public const int LOOKBACK_WEEKS = 12;

    public const int FORECAST_HORIZON_DAYS = 7;

    /**
     * Precision used when comparing upcoming effort against capacity.
     * Rounds away sub-0.0001 float drift accumulated across the EMA recurrence
     * so that 25.0 vs 24.9999999998 does not trigger a false burnout alert.
     */
    private const int DRIFT_PRECISION = 4;

    /**
     * Produce a complete forecast for the given user.
     */
    public function forecast(User $user): VelocityForecast
    {
        $series = $this->aggregateWeeklyVelocity($user);
        $upcoming = $this->sumUpcomingEffort($user);

        return $this->buildForecast($series, $upcoming);
    }

    /**
     * Pure-math forecast from a pre-computed weekly series. Exposed so the EMA,
     * variance, and probability logic can be unit-tested without a database.
     *
     * @param  list<int>  $weeklySeries  Effort completed per week, oldest → newest
     */
    public function buildForecast(array $weeklySeries, int $upcomingEffort): VelocityForecast
    {
        $weeksAnalysed = count($weeklySeries);
        $alpha = 2.0 / (self::LOOKBACK_WEEKS + 1);

        $ema = $this->calculateEma($weeklySeries, $alpha);
        $stdDev = $this->calculateStdDev($weeklySeries, $ema);
        $capacity = $ema + $stdDev;

        // Drift guard: round before comparison so accumulated float error in the
        // EMA recurrence cannot flip the inequality at the boundary.
        $burnout = $upcomingEffort > round($capacity, self::DRIFT_PRECISION);

        $probability = $upcomingEffort === 0
            ? 1.0
            : $this->probabilityOfSuccess((float) $upcomingEffort, $ema, $stdDev);

        return new VelocityForecast(
            velocityEma: $ema,
            velocityStdDev: $stdDev,
            upcomingEffort: $upcomingEffort,
            capacityUpperBound: $capacity,
            probabilityOfSuccess: $probability,
            burnoutRisk: $burnout,
            weeksAnalysed: $weeksAnalysed,
            weeklySeries: $weeklySeries,
        );
    }

    /**
     * EMAₜ = α · Xₜ + (1 − α) · EMAₜ₋₁, seeded with EMA₀ = X₀.
     *
     * @param  list<int|float>  $series  Oldest → newest
     */
    public function calculateEma(array $series, float $alpha): float
    {
        if ($series === []) {
            return 0.0;
        }

        $ema = (float) $series[0];
        $beta = 1.0 - $alpha;

        for ($t = 1, $n = count($series); $t < $n; $t++) {
            $ema = $alpha * (float) $series[$t] + $beta * $ema;
        }

        return $ema;
    }

    /**
     * Sample standard deviation of the series about the given centre (EMA).
     * Using EMA rather than SMA as the centre means σ measures deviation from
     * the *trend*, making the capacity bound responsive to recent pace shifts.
     *
     * @param  list<int|float>  $series
     */
    public function calculateStdDev(array $series, float $centre): float
    {
        $n = count($series);

        if ($n < 2) {
            return 0.0;
        }

        $sumSq = 0.0;
        foreach ($series as $x) {
            $d = (float) $x - $centre;
            $sumSq += $d * $d;
        }

        return sqrt($sumSq / ($n - 1));
    }

    /**
     * P(V ≥ required) where V ~ N(mean, stdDev²).
     *
     * Degenerates to a hard 0/1 threshold when stdDev = 0 (no uncertainty).
     */
    public function probabilityOfSuccess(float $required, float $mean, float $stdDev): float
    {
        if ($stdDev <= 0.0) {
            return $required <= round($mean, self::DRIFT_PRECISION) ? 1.0 : 0.0;
        }

        $z = ($mean - $required) / $stdDev;

        return $this->normalCdf($z);
    }

    /**
     * Standard normal CDF Φ(x) via Abramowitz & Stegun formula 26.2.17.
     * Maximum absolute error < 7.5 × 10⁻⁸ — ample precision for a burnout
     * heuristic and implemented in pure PHP with no external dependencies.
     */
    public function normalCdf(float $x): float
    {
        $absX = abs($x);
        $t = 1.0 / (1.0 + 0.2316419 * $absX);

        $poly = $t * (0.319381530
            + $t * (-0.356563782
            + $t * (1.781477937
            + $t * (-1.821255978
            + $t * 1.330274429))));

        // φ(x) = (1/√2π) · e^(−x²/2)
        $phi = 0.3989422804014327 * exp(-0.5 * $absX * $absX);
        $upperTail = $phi * $poly;

        return $x >= 0.0 ? 1.0 - $upperTail : $upperTail;
    }

    /**
     * Aggregate the user's historical completed effort into weekly buckets.
     *
     * SQL reduces N items → ≤ (LOOKBACK_WEEKS × 7) daily rows in one round-trip
     * via GROUP BY DATE(completed_at). A bounded O(84) loop then buckets days
     * into weeks — PHP never iterates over individual items.
     *
     * Excludes the in-progress current week to avoid biasing EMA low with a
     * partial observation.
     *
     * @return list<int> Effort per week, oldest → newest
     */
    private function aggregateWeeklyVelocity(User $user): array
    {
        $currentWeekStart = CarbonImmutable::now()->startOfWeek();
        $lookbackStart = $currentWeekStart->subWeeks(self::LOOKBACK_WEEKS);

        // DATE() is portable: PostgreSQL and SQLite both cast timestamp → date.
        $dailyTotals = Item::query()
            ->where('user_id', $user->id)
            ->where('status', 'done')
            ->where('completed_at', '>=', $lookbackStart)
            ->where('completed_at', '<', $currentWeekStart)
            ->groupBy('day')
            ->orderBy('day')
            ->get([
                DB::raw('DATE(completed_at) AS day'),
                DB::raw('SUM(effort_score) AS effort'),
            ]);

        $buckets = array_fill(0, self::LOOKBACK_WEEKS, 0);

        foreach ($dailyTotals as $row) {
            $weekIndex = $lookbackStart->diffInWeeks(CarbonImmutable::parse($row->day));
            $weekIndex = (int) floor($weekIndex);

            if ($weekIndex >= 0 && $weekIndex < self::LOOKBACK_WEEKS) {
                $buckets[$weekIndex] += (int) $row->effort;
            }
        }

        return $buckets;
    }

    /**
     * Sum of effort for open items due within the forecast horizon.
     * Single aggregate query — returns one integer.
     *
     * Window is [today, today + (HORIZON − 1)] inclusive — exactly HORIZON
     * calendar dates with today counted as day 1. Using `addDays(HORIZON)`
     * with `<=` would capture HORIZON + 1 dates (fencepost).
     */
    private function sumUpcomingEffort(User $user): int
    {
        $today = CarbonImmutable::now()->startOfDay();
        $lastDay = $today->addDays(self::FORECAST_HORIZON_DAYS - 1);

        return (int) Item::query()
            ->where('user_id', $user->id)
            ->whereNotIn('status', ['done', 'wontdo'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '>=', $today)
            ->whereDate('due_date', '<=', $lastDay)
            ->sum('effort_score');
    }
}
