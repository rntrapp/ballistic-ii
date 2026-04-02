<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Auth\TokenAbility;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class GlobalFeatureFlagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_read_global_feature_flags(): void
    {
        $admin = User::factory()->admin()->create();

        AppSetting::set('feature_flags', [
            'dates' => true,
            'delegation' => false,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/settings/features');

        $response->assertOk()
            ->assertJsonPath('data.dates', true)
            ->assertJsonPath('data.delegation', false);
    }

    public function test_non_admin_cannot_read_global_feature_flags(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/admin/settings/features');

        $response->assertForbidden();
    }

    public function test_admin_can_update_global_feature_flags(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->putJson('/api/admin/settings/features', [
            'dates' => false,
            'delegation' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.dates', false)
            ->assertJsonPath('data.delegation', false);

        Cache::flush();
        $flags = AppSetting::globalFeatureFlags();
        $this->assertFalse($flags['dates']);
        $this->assertFalse($flags['delegation']);
    }

    public function test_partial_update_preserves_other_flags(): void
    {
        $admin = User::factory()->admin()->create();

        AppSetting::set('feature_flags', [
            'dates' => true,
            'delegation' => true,
        ]);

        $response = $this->actingAs($admin)->putJson('/api/admin/settings/features', [
            'delegation' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.dates', true)
            ->assertJsonPath('data.delegation', false);
    }

    public function test_non_admin_cannot_update_global_feature_flags(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson('/api/admin/settings/features', [
            'dates' => false,
        ]);

        $response->assertForbidden();
    }

    public function test_arbitrary_keys_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->putJson('/api/admin/settings/features', [
            'dates' => false,
            'evil_flag' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.dates', false);

        Cache::flush();
        $flags = AppSetting::globalFeatureFlags();
        $this->assertArrayNotHasKey('evil_flag', $flags);
    }

    public function test_user_cannot_enable_globally_disabled_flag(): void
    {
        $user = User::factory()->create([
            'feature_flags' => [
                'dates' => false,
                'delegation' => false,
            ],
        ]);
        $token = $user->createToken('api-client', [TokenAbility::Api->value])->plainTextToken;

        AppSetting::set('feature_flags', [
            'dates' => false,
            'delegation' => true,
        ]);

        $response = $this->withToken($token)->patchJson('/api/user', [
            'feature_flags' => ['dates' => true],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['feature_flags.dates']);
    }

    public function test_user_can_disable_own_flag_regardless_of_global_state(): void
    {
        $user = User::factory()->create([
            'feature_flags' => [
                'dates' => true,
                'delegation' => true,
            ],
        ]);
        $token = $user->createToken('api-client', [TokenAbility::Api->value])->plainTextToken;

        AppSetting::set('feature_flags', [
            'dates' => false,
            'delegation' => true,
        ]);

        $response = $this->withToken($token)->patchJson('/api/user', [
            'feature_flags' => ['dates' => false],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.feature_flags.dates', false);
    }

    public function test_user_response_includes_available_feature_flags(): void
    {
        $user = User::factory()->create([
            'feature_flags' => [
                'dates' => true,
                'delegation' => false,
            ],
        ]);
        $token = $user->createToken('api-client', [TokenAbility::Api->value])->plainTextToken;

        AppSetting::set('feature_flags', [
            'dates' => true,
            'delegation' => false,
        ]);

        $response = $this->withToken($token)->getJson('/api/user');

        $response->assertOk()
            ->assertJsonPath('data.feature_flags.dates', true)
            ->assertJsonPath('data.feature_flags.delegation', false)
            ->assertJsonPath('data.available_feature_flags.dates', true)
            ->assertJsonPath('data.available_feature_flags.delegation', false);
    }

    public function test_defaults_all_true_when_no_setting_row_exists(): void
    {
        $flags = AppSetting::globalFeatureFlags();

        $this->assertTrue($flags['dates']);
        $this->assertTrue($flags['delegation']);
    }

    public function test_non_boolean_flag_value_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->putJson('/api/admin/settings/features', [
            'dates' => 'not_a_boolean',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['dates']);
    }
}
