<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\VelocityForecastingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class VelocityController extends Controller
{
    /**
     * Return the authenticated user's velocity forecast:
     * EMA-smoothed weekly velocity, committed effort for the next 7 days,
     * burnout-risk flag, and estimated probability of success.
     *
     * Query params:
     *   - lookback_weeks (int, 1–52, default 8)
     *   - alpha          (numeric, 0 < α ≤ 1, default 0.3)
     */
    public function __invoke(
        Request $request,
        VelocityForecastingService $forecaster,
    ): JsonResponse {
        $validated = $request->validate([
            'lookback_weeks' => ['sometimes', 'integer', 'min:1', 'max:52'],
            // The regex restricts alpha to plain decimal strings (e.g. "0.3", "1", ".5").
            // The `numeric` rule alone also accepts scientific notation like "3e-1",
            // which is valid PHP-numeric but rejected by BCMath with a ValueError.
            'alpha' => ['sometimes', 'numeric', 'regex:/^\d*\.?\d+$/', 'gt:0', 'lte:1'],
        ], [
            'alpha.regex' => 'The alpha must be a plain decimal string (e.g. 0.3). Scientific notation is not accepted.',
        ]);

        /** @var User $user */
        $user = Auth::user();

        $forecast = $forecaster->forecast(
            $user,
            (int) ($validated['lookback_weeks'] ?? 8),
            (string) ($validated['alpha'] ?? '0.3'),
        );

        return response()->json($forecast);
    }
}
