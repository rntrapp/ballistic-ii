<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class FeatureFlagAdminTest extends TestCase
{
    use RefreshDatabase;

    // ── Index page ───────────────────────────────────────────────

    public function test_admin_can_view_feature_flags_page(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin/feature-flags');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/feature-flags/index')
            ->has('flags')
            ->where('flags.dates', true)
            ->where('flags.delegation', true)
        );
    }

    public function test_admin_can_view_feature_flags_with_custom_values(): void
    {
        $admin = User::factory()->admin()->create();

        AppSetting::set('feature_flags', [
            'dates' => false,
            'delegation' => true,
        ]);

        $response = $this->actingAs($admin)->get('/admin/feature-flags');

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('flags.dates', false)
            ->where('flags.delegation', true)
        );
    }

    public function test_non_admin_cannot_view_feature_flags_page(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->get('/admin/feature-flags');

        $response->assertForbidden();
    }

    public function test_unauthenticated_cannot_view_feature_flags_page(): void
    {
        $response = $this->get('/admin/feature-flags');

        $response->assertRedirect('/login');
    }

    // ── Update flags ─────────────────────────────────────────────

    public function test_admin_can_update_feature_flags(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->put('/admin/feature-flags', [
            'dates' => false,
            'delegation' => true,
        ]);

        $response->assertRedirect('/admin/feature-flags');
        $response->assertSessionHas('success');

        Cache::flush();
        $flags = AppSetting::globalFeatureFlags();
        $this->assertFalse($flags['dates']);
        $this->assertTrue($flags['delegation']);
    }

    public function test_admin_can_partially_update_feature_flags(): void
    {
        $admin = User::factory()->admin()->create();

        AppSetting::set('feature_flags', [
            'dates' => true,
            'delegation' => true,
        ]);

        $response = $this->actingAs($admin)->put('/admin/feature-flags', [
            'delegation' => false,
        ]);

        $response->assertRedirect('/admin/feature-flags');

        Cache::flush();
        $flags = AppSetting::globalFeatureFlags();
        $this->assertTrue($flags['dates']);
        $this->assertFalse($flags['delegation']);
    }

    public function test_non_admin_cannot_update_feature_flags(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->put('/admin/feature-flags', [
            'dates' => false,
        ]);

        $response->assertForbidden();
    }

    public function test_invalid_flag_values_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->put('/admin/feature-flags', [
            'dates' => 'not_a_boolean',
        ]);

        $response->assertSessionHasErrors(['dates']);
    }
}
