<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateFeatureFlagsRequest;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;

final class SettingsController extends Controller
{
    /**
     * Show the current global feature flags.
     */
    public function showFeatures(): JsonResponse
    {
        return response()->json([
            'data' => AppSetting::globalFeatureFlags(),
        ]);
    }

    /**
     * Update one or more global feature flags (partial update).
     */
    public function updateFeatures(UpdateFeatureFlagsRequest $request): JsonResponse
    {
        $incoming = $request->featureFlags();

        if (empty($incoming)) {
            return response()->json([
                'data' => AppSetting::globalFeatureFlags(),
            ]);
        }

        $current = AppSetting::globalFeatureFlags();
        $merged = array_merge($current, $incoming);

        AppSetting::set('feature_flags', $merged);

        return response()->json([
            'data' => $merged,
        ]);
    }
}
