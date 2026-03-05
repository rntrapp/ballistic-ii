<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VelocityForecastingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/velocity
 *
 * Returns the authenticated user's weekly velocity EMA, the total effort due
 * in the next seven days, the calculated probability of meeting those
 * deadlines, and a burnout-risk flag.
 */
final class VelocityController extends Controller
{
    public function __invoke(Request $request, VelocityForecastingService $service): JsonResponse
    {
        $validated = $request->validate([
            'alpha' => ['sometimes', 'numeric', 'gt:0', 'lte:1'],
        ]);

        $alpha = isset($validated['alpha'])
            ? (string) $validated['alpha']
            : VelocityForecastingService::DEFAULT_ALPHA;

        $forecast = $service->forecast(
            (string) $request->user()->id,
            CarbonImmutable::now(),
            $alpha,
        );

        return response()->json(['data' => $forecast]);
    }
}
