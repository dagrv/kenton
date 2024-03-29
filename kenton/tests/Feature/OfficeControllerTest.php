<?php

namespace Tests\Feature;

use App\Models\Tag;
use Tests\TestCase;
use App\Models\User;
use App\Models\Image;
use App\Models\Office;
use App\Models\Reservation;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use App\Notifications\OfficePendingApproval;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OfficeControllerTest extends TestCase {
    use LazilyRefreshDatabase;

    /**
     * @test
     */
    public function it_list_all_offices_paginated() {
        Office::factory(30)->create();

        $response = $this->get('/api/offices');

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta', 'links'])
            ->assertJsonCount(20, 'data')
            ->assertJsonStructure(['data' => ['*' => ['id', 'title']]]);
    }

    /**
     * @test
     */
    public function it_only_list_offices_that_are_not_hidden_and_approved() {
        Office::factory(3)->create();

        Office::factory()->create(['hidden' => true]);
        Office::factory()->create(['approval_status' => Office::APPROVAL_PENDING]);

        $response = $this->get('/api/offices');
        $response->assertOk()->assertJsonCount(3, 'data');
    }


    /**
     * @test
     */
    public function it_list_offices_including_hidden_and_non_approved_if_filtering_for_current_loggedin_user() {
        $user = User::factory()->create();

        Office::factory(3)->for($user)->create();

        Office::factory()->hidden()->for($user)->create();
        Office::factory()->pending()->for($user)->create();

        $this->actingAs($user);

        $response = $this->get('/api/offices?user_id='.$user->id);
        $response->assertOk()->assertJsonCount(5, 'data');
    }


    /**
     * @test
     */
    public function it_filters_by_user_id() {
        Office::factory(3)->create();

        $host = User::factory()->create();
        $office = Office::factory()->for($host)->create();

        $response = $this->get(
            '/api/offices?user_id='.$host->id
        );

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $office->id);
    }

    /**
     * @test
     */
    public function it_filters_by_visitor_id() {
        Office::factory(3)->create();

        $user = User::factory()->create();
        $office = Office::factory()->create();

        Reservation::factory()->for(Office::factory())->create();
        Reservation::factory()->for($office)->for($user)->create();

        $response = $this->get(
            '/api/offices?visitor_id='.$user->id
        );

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $office->id);
    }

    /**
     * @test
     */
    public function it_includes_images_tags_and_user() {
        $user = User::factory()->create();
        $tag = Tag::factory()->create();
        $office = Office::factory()->for($user)->create();

        $office->tags()->attach($tag);
        $office->images()->create(['path' => 'image.png']);

        $response = $this->get('/api/offices');

        $response->assertOk()
            ->assertJsonCount(1, 'data.0.tags')
            ->assertJsonCount(1, 'data.0.images')
            ->assertJsonPath('data.0.user.id', $user->id);
    }

    /**
     * @test
     */
    public function it_returns_the_number_of_active_reservations() {
        $office = Office::factory()->create();

        Reservation::factory()->for($office)->create(['status' => Reservation::STATUS_ACTIVE]);
        Reservation::factory()->for($office)->create(['status' => Reservation::STATUS_CANCELLED]);

        $response = $this->get('/api/offices');

        $response->assertOk()->assertJsonPath('data.0.reservations_count', 1);
    }

    /**
     * @test
     */
    public function it_orders_by_distance_when_coordinates_provided() {
        $office1 = Office::factory()->create([
            'lat' => '39.74051727562952',
            'lng' => '-8.770375324893696',
            'title' => 'Leiria, Portugal'
        ]);

        $office2 = Office::factory()->create([
            'lat' => '39.07753883078113',
            'lng' => '-9.281266331143293',
            'title' => 'Torres Vedras, Portugal'
        ]);

        $response = $this->get('/api/offices?lat=38.720661384644046&lng=-9.16044783453807');

        $response->assertOk()
            ->assertJsonPath('data.0.title', 'Torres Vedras, Portugal')
            ->assertJsonPath('data.1.title', 'Leiria, Portugal');

        $response = $this->get('/api/offices');

        $response->assertOk()
            ->assertJsonPath('data.0.title', 'Leiria, Portugal')
            ->assertJsonPath('data.1.title', 'Torres Vedras, Portugal');
    }

    /**
     * @test
     */
    public function it_shows_the_office() {
        $user = User::factory()->create();
        $tag = Tag::factory()->create();
        $office = Office::factory()->for($user)->create();

        $office->tags()->attach($tag);
        $office->images()->create(['path' => 'image.png']);

        Reservation::factory()->for($office)->create(['status' => Reservation::STATUS_ACTIVE]);
        Reservation::factory()->for($office)->create(['status' => Reservation::STATUS_CANCELLED]);

        $response = $this->get('/api/offices/'.$office->id);

        $response->assertOk()
            ->assertJsonPath('data.reservations_count', 1)
            ->assertJsonCount(1, 'data.tags')
            ->assertJsonCount(1, 'data.images')
            ->assertJsonPath('data.user.id', $user->id);
    }

    /**
     * @test
     */
    public function it_creates_an_office() {
        Notification::fake();
        
        $admin = User::factory()->create(['is_admin' => true]);
        
        $user = User::factory()->create();
        $tags = Tag::factory(2)->create();

        $this->actingAs($user);

        $response = $this->postJson('/api/offices', Office::factory()->raw([
            'tags' => $tags->pluck('id')->toArray()
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.approval_status', Office::APPROVAL_PENDING)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonCount(2, 'data.tags');

        $this->assertDatabaseHas('offices', [
            'id' => $response->json('data.id')
        ]);

        Notification::assertSentTo($admin, OfficePendingApproval::class);
    }

    /**
     * @test
     */
    public function it_doesnt_allow_creating_if_no_scopes_provided() {
        $user = User::factory()->create();
        $token = $user->createToken('test', []);

        $response = $this->postJson('/api/offices', [], [
            'Authorization' => 'Bearer '.$token->plainTextToken
        ]);

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }


    /**
     * @test
     */
    public function it_allows_creating_if_scope_is_provided() {
        $user = User::factory()->create();

        Sanctum::actingAs($user, ['office.create']);

        $response = $this->postJson('/api/offices');

        $this->assertNotEquals(Response::HTTP_FORBIDDEN, $response->status());
    }

    /**
     * @test
     */
    public function it_updates_an_office() {
        $user = User::factory()->create();
        $tags = Tag::factory(3)->create();
        $anotherTag = Tag::factory()->create();
        $office = Office::factory()->for($user)->create();

        $office->tags()->attach($tags);
        
        $this->actingAs($user);
        
        $anotherTag = Tag::factory()->create();

        $response = $this->putJson('/api/offices/'.$office->id, [
            'title' => 'Amazing Office',
            'tags' => [$tags[0]->id, $anotherTag->id]
        ]);

        $response->assertOk()
            ->assertJsonCount(2, 'data.tags')
            ->assertJsonPath('data.tags.0.id', $tags[0]->id)
            ->assertJsonPath('data.tags.1.id', $anotherTag->id)
            ->assertJsonPath('data.title', 'Amazing Office');
    }

    /**
     * @test
     */
    public function it_doesnt_update_office_that_doesnt_belong_to_user() {
        $user = User::factory()->create();
        $anotherUser = User::factory()->create();
        $office = Office::factory()->for($anotherUser)->create();

        $this->actingAs($user);

        $response = $this->putJson('/api/offices/'.$office->id, [
            'title' => 'Amazing Office'
        ]);

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    /**
     * @test
     */
    public function it_should_mark_the_office_as_pending_if_dirty() {
        $admin = User::factory()->create(['is_admin' => true]);
        
        Notification::fake();

        $user = User::factory()->create();
        $office = Office::factory()->for($user)->create();

        $this->actingAs($user);

        $response = $this->putJson('/api/offices/'.$office->id, [
            'lat' => 40.74051727562952
        ]);

        $response->assertOk();

        Notification::assertSentTo($admin, OfficePendingApproval::class);

        $this->assertDatabaseHas('offices', [
            'id' => $office->id,
            'approval_status' => Office::APPROVAL_PENDING
        ]);
    }


    /**
     * @test
     */
    public function it_updated_the_featured_image() {
        $user = User::factory()->create();
        $office = Office::factory()->for($user)->create();

        $image = $office->images()->create([
            'path' => 'image.jpg'
        ]);

        $this->actingAs($user);

        $response = $this->putJson('/api/offices/'.$office->id, [
            'featured_image_id' => $image->id,
        ]);

        $response->assertOk()->assertJsonPath('data.featured_image_id', $image->id);
    }


    /**
     * @test
     */
    public function it_doesnt_update_the_featured_image_that_belongs_to_another_office() {
        $user = User::factory()->create();
        $office = Office::factory()->for($user)->create();
        $office2 = Office::factory()->for($user)->create();

        $image = $office2->images()->create([
            'path' => 'image.jpg'
        ]);

        $this->actingAs($user);

        $response = $this->putJson('/api/offices/'.$office->id, [
            'featured_image_id' => $image->id,
        ]);

        $response->assertUnprocessable()->assertInvalid('featured_image_id');
    }


    /**
     * @test
     */
    public function it_deletes_offices() {
        Storage::put('office_image.jpg', 'empty');
        
        $user = User::factory()->create();
        $office = Office::factory()->for($user)->create();

        $image = $office->images()->create([
            'path' => 'office_image.jpg'
        ]);
        
        $this->actingAs($user);

        $response = $this->deleteJson('/api/offices/'.$office->id);
        $response->assertOk();

        $this->assertSoftDeleted($office);

        Storage::assertMissing('office_image.jpg');
    }
    
    /**
     * @test
     */
    public function it_cannot_delete_offices_that_has_reservations() {
        $user = User::factory()->create();
        $office = Office::factory()->for($user)->create();

        Reservation::factory(3)->for($office)->create();
        
        $this->actingAs($user);

        $response = $this->deleteJson('/api/offices/'.$office->id);
        $response->assertUnprocessable();

        $this->assertNotSoftDeleted($office);
    }
}
