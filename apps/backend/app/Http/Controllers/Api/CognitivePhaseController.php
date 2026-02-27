<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChronobiologyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class CognitivePhaseController extends Controller
{
    /**
     * Display the authenticated user's current cognitive phase analysis.
     */
    public function show(Request $request, ChronobiologyService $service): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $cacheKey = "cognitive_phase:{$user->id}";

        $data = Cache::get($cacheKey);

        if ($data === null) {
            $data = $service->analyse((string) $user->id);

            Cache::put($cacheKey, $data, now()->addMinutes(5));
        }

        return response()->json(['data' => $data]);
    }
}
