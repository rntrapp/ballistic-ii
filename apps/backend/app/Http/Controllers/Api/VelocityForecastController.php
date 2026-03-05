<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\VelocityForecastingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VelocityForecastController extends Controller
{
    /**
     * GET /api/velocity/forecast
     *
     * Returns the authenticated user's current velocity forecast. The
     * service layer performs all aggregation and statistics; this
     * controller is a thin pass-through.
     */
    public function __invoke(Request $request, VelocityForecastingService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($service->forecast($user));
    }
}
