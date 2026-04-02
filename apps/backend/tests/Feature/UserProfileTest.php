<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_profile(): void
    {
        $user = User::factory()->create([
            'bio' => 'Test bio',
            'avatar_url' => 'https://example.com/avatar.jpg',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/user');

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => $user->name,
                'email' => $user->email,
                'bio' => 'Test bio',
                'avatar_url' => 'https://example.com/avatar.jpg',
            ]);
    }

    public function test_user_can_update_bio(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->putJson('/api/user/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'bio' => 'My new bio',
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'bio' => 'My new bio',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'bio' => 'My new bio',
        ]);
    }

    public function test_user_can_update_avatar_url(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->putJson('/api/user/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => 'https://example.com/new-avatar.png',
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'avatar_url' => 'https://example.com/new-avatar.png',
            ]);
    }

    public function test_bio_cannot_exceed_500_characters(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->putJson('/api/user/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'bio' => str_repeat('a', 501),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['bio']);
    }

    public function test_avatar_url_must_be_valid_url(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->putJson('/api/user/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => 'not-a-url',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['avatar_url']);
    }

    public function test_user_can_clear_bio_and_avatar(): void
    {
        $user = User::factory()->create([
            'bio' => 'Old bio',
            'avatar_url' => 'https://example.com/old.jpg',
        ]);

        $response = $this->actingAs($user)
            ->putJson('/api/user/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'bio' => null,
                'avatar_url' => null,
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'bio' => null,
                'avatar_url' => null,
            ]);
    }

    public function test_profile_update_via_patch_includes_new_fields(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->patchJson('/api/user', [
                'bio' => 'Patch bio',
                'avatar_url' => 'https://example.com/patch.jpg',
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'bio' => 'Patch bio',
                'avatar_url' => 'https://example.com/patch.jpg',
            ]);
    }

    public function test_email_change_resets_verification(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $newEmail = 'newemail'.time().'@example.com';

        $response = $this->actingAs($user)
            ->putJson('/api/user/profile', [
                'name' => $user->name,
                'email' => $newEmail,
            ]);

        $response->assertStatus(200);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_profile_update_requires_authentication(): void
    {
        $response = $this->putJson('/api/user/profile', [
            'name' => 'Test',
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(401);
    }
}
