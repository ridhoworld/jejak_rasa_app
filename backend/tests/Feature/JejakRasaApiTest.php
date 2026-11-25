<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JejakRasaApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_register_login_me_and_logout(): void
    {
        $register = $this->postJson('/api/auth/register', [
            'name' => 'Rina Kuliner',
            'email' => 'rina@example.com',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ]);

        $register->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'rina@example.com',
            'password' => 'rahasia123',
        ]);

        $login->assertOk()->assertJsonStructure(['token', 'user']);
        $token = $login->json('token');

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'rina@example.com');

        $this->withToken($token)
            ->putJson('/api/auth/profile', [
                'name' => 'Rina Updated',
                'email' => 'rina.baru@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'Rina Updated');

        $this->withToken($token)
            ->putJson('/api/auth/password', [
                'current_password' => 'rahasia123',
                'password' => 'barusekali123',
                'password_confirmation' => 'barusekali123',
            ])
            ->assertOk();

        $this->withToken($token)
            ->postJson('/api/auth/logout')
            ->assertOk();
    }

    public function test_place_crud_duplicate_detection_and_wishlist_rule(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Create wishlist place
        $created = $this->postJson('/api/places', [
            'name' => 'Warung Sate Pak Budi',
            'address' => 'Jl. Merdeka No. 10',
            'category' => 'Warung',
            'is_wishlist' => true,
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.is_wishlist', true);

        $placeId = $created->json('data.id');

        // Duplicate name + address should return 409
        $this->postJson('/api/places', [
            'name' => 'warung sate pak budi',
            'address' => 'jl. merdeka no. 10',
            'category' => 'Warung',
        ])->assertStatus(409)->assertJsonPath('duplicate', true);

        // Cannot move wishlist to history without at least 1 visit
        $this->putJson("/api/places/{$placeId}", [
            'is_wishlist' => false,
        ])->assertStatus(422);

        // Complete wishlist with first visit (including dishes & tags)
        $completed = $this->postJson("/api/places/{$placeId}/complete-wishlist", [
            'visited_at' => '2026-11-20',
            'rating' => 5,
            'total_price' => 65000,
            'notes' => 'Bumbu kacangnya mantap',
            'companions' => 'Budi, Sari',
            'dishes' => [
                ['name' => 'Sate Ayam 10 Tusuk', 'price' => 35000, 'rating' => 5, 'is_favorite' => true],
                ['name' => 'Es Teh Manis', 'price' => 8000, 'rating' => 4, 'is_favorite' => false],
            ],
            'tags' => ['murah', 'buat nongkrong'],
        ]);

        $completed->assertCreated()
            ->assertJsonPath('data.place.is_wishlist', false)
            ->assertJsonCount(2, 'data.visit.dishes')
            ->assertJsonCount(2, 'data.visit.tags');

        // Detail place returns visits, dishes, tags
        $this->getJson("/api/places/{$placeId}")
            ->assertOk()
            ->assertJsonPath('data.visits_count', 1)
            ->assertJsonPath('data.visits.0.rating', 5);
    }

    public function test_search_filters_and_tags(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $placeA = Place::create([
            'user_id' => $user->id,
            'name' => 'Kopi Senja',
            'address' => 'Jl. Sudirman',
            'category' => 'Kafe',
            'is_wishlist' => false,
        ]);

        $this->postJson("/api/places/{$placeA->id}/visits", [
            'visited_at' => '2026-11-21',
            'rating' => 4,
            'total_price' => 45000,
            'dishes' => [
                ['name' => 'Es Kopi Susu Gula Aren', 'price' => 25000, 'rating' => 5, 'is_favorite' => true],
            ],
            'tags' => ['buat nongkrong', 'kopi'],
        ])->assertCreated();

        $placeB = Place::create([
            'user_id' => $user->id,
            'name' => 'Bakso Pedas Mantap',
            'address' => 'Jl. Diponegoro',
            'category' => 'Warung',
            'is_wishlist' => false,
        ]);

        $this->postJson("/api/places/{$placeB->id}/visits", [
            'visited_at' => '2026-11-22',
            'rating' => 5,
            'total_price' => 25000,
            'dishes' => [
                ['name' => 'Bakso Mercon', 'price' => 22000, 'rating' => 5, 'is_favorite' => true],
            ],
            'tags' => ['pedas', 'murah'],
        ])->assertCreated();

        // Search by dish name
        $this->getJson('/api/places?search=Mercon')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Bakso Pedas Mantap');

        // Filter by tag & rating & max_price
        $this->getJson('/api/places?tag=pedas&min_rating=5&max_price=30000')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $placeB->id);

        // Tags endpoint
        $this->getJson('/api/tags')
            ->assertOk()
            ->assertJsonCount(4, 'data');
    }

    public function test_photo_upload_and_cascade_delete_cleans_storage(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $place = Place::create([
            'user_id' => $user->id,
            'name' => 'Mie Ayam Berkah',
            'category' => 'Warung',
            'is_wishlist' => false,
        ]);

        $visitRes = $this->postJson("/api/places/{$place->id}/visits", [
            'visited_at' => '2026-11-23',
            'rating' => 5,
            'total_price' => 20000,
            'dishes' => [
                ['name' => 'Mie Ayam Bakso', 'price' => 18000, 'rating' => 5, 'is_favorite' => true],
            ],
        ])->assertCreated();

        $visitId = $visitRes->json('data.id');
        $dishId = $visitRes->json('data.dishes.0.id');

        $photoRes = $this->post("/api/visits/{$visitId}/photos", [
            'photo' => UploadedFile::fake()->image('mie_ayam.jpg', 600, 400),
            'visit_dish_id' => $dishId,
            'caption' => 'Porsi jumbo',
        ], ['Accept' => 'application/json']);

        $photoRes->assertCreated()
            ->assertJsonPath('data.visit_dish_id', $dishId);

        $storedPath = $photoRes->json('data.path');
        Storage::disk('public')->assertExists($storedPath);

        // Photos gallery endpoint returns uploaded photo with place & dish context
        $this->getJson('/api/photos')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.visit.place.name', 'Mie Ayam Berkah');

        // Deleting place cascades DB records and removes stored photo file
        $this->deleteJson("/api/places/{$place->id}")->assertOk();

        $this->assertDatabaseMissing('places', ['id' => $place->id]);
        $this->assertDatabaseMissing('visits', ['id' => $visitId]);
        $this->assertDatabaseMissing('visit_dishes', ['id' => $dishId]);
        Storage::disk('public')->assertMissing($storedPath);
    }

    public function test_validation_rules_and_user_isolation(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $place = Place::create([
            'user_id' => $owner->id,
            'name' => 'Soto Kudus Asli',
            'category' => 'Warung',
        ]);

        // Other user cannot access owner's place
        Sanctum::actingAs($other);
        $this->getJson("/api/places/{$place->id}")->assertForbidden();
        $this->deleteJson("/api/places/{$place->id}")->assertForbidden();

        // Owner cannot submit invalid rating or negative price
        Sanctum::actingAs($owner);
        $this->postJson("/api/places/{$place->id}/visits", [
            'visited_at' => '2026-11-23',
            'rating' => 6,
            'total_price' => -5000,
        ])->assertStatus(422)->assertJsonValidationErrors(['rating', 'total_price']);
    }

    public function test_stats_random_wishlist_and_csv_export(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Random wishlist returns 404 when empty
        $this->getJson('/api/wishlist/random')->assertStatus(404);

        $visited = Place::create([
            'user_id' => $user->id,
            'name' => 'Gado-Gado Enak',
            'category' => 'Warung',
            'is_wishlist' => false,
        ]);

        $wishlist = Place::create([
            'user_id' => $user->id,
            'name' => 'Ramen Baru',
            'category' => 'Restoran',
            'is_wishlist' => true,
        ]);

        $this->postJson("/api/places/{$visited->id}/visits", [
            'visited_at' => now()->format('Y-m-d'),
            'rating' => 5,
            'total_price' => 30000,
            'dishes' => [
                ['name' => 'Gado-Gado Lontong', 'price' => 25000, 'rating' => 5, 'is_favorite' => true],
            ],
            'tags' => ['sehat'],
        ])->assertCreated();

        $this->getJson('/api/stats')
            ->assertOk()
            ->assertJsonPath('data.total_places', 1)
            ->assertJsonPath('data.total_wishlist', 1)
            ->assertJsonPath('data.total_visits', 1)
            ->assertJsonPath('data.total_spent', 30000)
            ->assertJsonPath('data.favorite_category.category', 'Warung')
            ->assertJsonCount(1, 'data.favorite_dishes');

        $this->getJson('/api/wishlist/random')
            ->assertOk()
            ->assertJsonPath('data.id', $wishlist->id);

        $csvRes = $this->get('/api/export/csv');
        $csvRes->assertOk();
        $this->assertStringContainsString('Gado-Gado Enak', $csvRes->getContent());
        $this->assertStringContainsString('Ramen Baru', $csvRes->getContent());
    }
}

