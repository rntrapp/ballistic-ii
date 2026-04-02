<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class FeatureFlagController extends Controller
{
    /**
     * Display the feature flags management page.
     */
    public function index(): InertiaResponse
    {
        return Inertia::render('admin/feature-flags/index', [
            'flags' => AppSetting::globalFeatureFlags(),
        ]);
    }

    /**
     * Update global feature flags.
     */
    public function update(Request $request): RedirectResponse
    {
        $knownFlags = array_keys(AppSetting::globalFeatureFlags());

        $rules = [];
        foreach ($knownFlags as $flag) {
            $rules[$flag] = ['sometimes', 'boolean'];
        }

        $validated = $request->validate($rules);

        if (! empty($validated)) {
            $current = AppSetting::globalFeatureFlags();
            $merged = array_merge($current, $validated);
            AppSetting::set('feature_flags', $merged);
        }

        return redirect()->route('admin.feature-flags.index')
            ->with('success', 'Feature flags updated successfully.');
    }
}
