<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class UserController extends Controller
{
    /**
     * Display the authenticated user's profile.
     */
    public function show(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();
        $user->load('favourites');

        return new UserResource($user);
    }

    /**
     * Update the authenticated user's profile.
     */
    public function update(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
            'phone' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'bio' => ['nullable', 'string', 'max:500'],
            'avatar_url' => ['nullable', 'string', 'url', 'max:500'],
            'feature_flags' => ['nullable', 'array'],
            'feature_flags.dates' => ['sometimes', 'boolean'],
            'feature_flags.delegation' => ['sometimes', 'boolean'],
            'feature_flags.ai_assistant' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'required', 'confirmed', Password::defaults()],
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        if (array_key_exists('feature_flags', $validated) && is_array($validated['feature_flags'])) {
            $allowedFlagKeys = ['dates', 'delegation', 'ai_assistant'];
            $incomingFlags = array_intersect_key($validated['feature_flags'], array_flip($allowedFlagKeys));

            $globalFlags = AppSetting::globalFeatureFlags();
            $errors = [];
            foreach ($incomingFlags as $flag => $value) {
                if ($value === true && ($globalFlags[$flag] ?? true) === false) {
                    $errors["feature_flags.{$flag}"] = ["The {$flag} feature is not currently available."];
                }
            }

            if (! empty($errors)) {
                throw ValidationException::withMessages($errors);
            }

            $validated['feature_flags'] = array_merge(
                $user->feature_flags ?? [],
                $incomingFlags
            );
        }

        DB::transaction(function () use ($user, $validated): void {
            $user->update($validated);

            if (isset($validated['email']) && $validated['email'] !== $user->getOriginal('email')) {
                $user->email_verified_at = null;
                $user->save();
            }
        });

        return new UserResource($user);
    }

    /**
     * Update the authenticated user's profile fields only.
     *
     * This is a focused endpoint for profile editing (name, email, phone, bio, avatar_url).
     */
    public function updateProfile(UpdateProfileRequest $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();
        $originalEmail = $user->email;

        DB::transaction(function () use ($user, $validated, $originalEmail): void {
            $user->update($validated);

            if (isset($validated['email']) && $validated['email'] !== $originalEmail) {
                $user->email_verified_at = null;
                $user->save();
            }
        });

        return new UserResource($user);
    }
}
