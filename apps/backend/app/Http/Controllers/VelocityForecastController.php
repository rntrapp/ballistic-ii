<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\VelocityForecastingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VelocityForecastController extends Controller
{
    /**
     * GET /api/velocity/forecast
     *
     * Returns the authenticated user's velocity EMA, upcoming required effort,
     * upper-bound capacity, probability of success, and burnout-risk flag.
     */
    public function __invoke(Request $request, VelocityForecastingService $service): JsonResponse
    {
        $forecast = $service->forecast($request->user());

        return response()->json($forecast->toArray());
    }
}
