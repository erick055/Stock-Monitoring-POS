<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_polling_and_background_refresh_do_not_extend_admin_idle_timeout(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $lastActivity = now()->timestamp;
        $this->actingAs($user)->withSession(['auth.last_activity_at' => $lastActivity]);
        $this->travel(30)->minutes();
        $this->getJson(route('inventory.live'))->assertOk()
            ->assertSessionHas('auth.last_activity_at', $lastActivity);
        $this->get(route('admin.dashboard', ['_background' => '1']))->assertOk()
            ->assertSessionHas('auth.last_activity_at', $lastActivity);
        $this->travel(31)->minutes();
        $this->getJson(route('inventory.live'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_user_activity_extends_the_timer_but_cannot_revive_an_expired_session(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->withSession(['auth.last_activity_at' => now()->timestamp]);
        $this->travel(50)->minutes();
        $this->postJson(route('session.activity'))->assertNoContent()
            ->assertSessionHas('auth.last_activity_at', now()->timestamp);
        $this->travel(30)->minutes();
        $this->get(route('admin.dashboard', ['_background' => '1']))->assertOk();
        $this->travel(31)->minutes();
        $this->postJson(route('session.activity'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_guest_cannot_record_session_activity(): void
    {
        $this->postJson(route('session.activity'))->assertUnauthorized();
    }
}
