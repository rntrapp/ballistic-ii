<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ItemCognitiveLoadTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_item_with_cognitive_load(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/items', [
            'title' => 'Heavy thinking task',
            'status' => 'todo',
            'cognitive_load' => 9,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.cognitive_load', 9);

        $this->assertDatabaseHas('items', [
            'user_id' => $user->id,
            'title' => 'Heavy thinking task',
            'cognitive_load' => 9,
        ]);
    }

    public function test_cognitive_load_defaults_to_null(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/items', [
            'title' => 'Untagged task',
            'status' => 'todo',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.cognitive_load', null);
    }

    public function test_cognitive_load_rejects_out_of_range(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/items', [
                'title' => 'Bad',
                'status' => 'todo',
                'cognitive_load' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cognitive_load');

        $this->actingAs($user)
            ->postJson('/api/items', [
                'title' => 'Bad',
                'status' => 'todo',
                'cognitive_load' => 11,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cognitive_load');
    }

    public function test_can_update_cognitive_load(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->todo()->create([
            'user_id' => $user->id,
            'cognitive_load' => 3,
        ]);

        $response = $this->actingAs($user)->patchJson("/api/items/{$item->id}", [
            'cognitive_load' => 7,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.cognitive_load', 7);

        $this->assertDatabaseHas('items', [
            'id' => $item->id,
            'cognitive_load' => 7,
        ]);
    }

    public function test_cognitive_load_in_item_resource(): void
    {
        $user = User::factory()->create();
        Item::factory()->todo()->withCognitiveLoad(6)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson('/api/items');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertArrayHasKey('cognitive_load', $data[0]);
        $this->assertSame(6, $data[0]['cognitive_load']);
    }
}
