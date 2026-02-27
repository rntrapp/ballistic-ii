<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

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
            'feature_flags' => ['nullable', 'array'],
            'feature_flags.dates' => ['sometimes', 'boolean'],
            'feature_flags.delegation' => ['sometimes', 'boolean'],
            'feature_flags.cognitive_phase' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'required', 'confirmed', Password::defaults()],
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
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
}
