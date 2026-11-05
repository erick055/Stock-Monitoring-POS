<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerIdleSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_navigation_and_successful_forms_publish_a_fresh_sixty_minute_deadline(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $this->actingAs($owner)->withSession(['auth.last_activity_at' => now()->timestamp]);
        foreach (range(1, 6) as $visit) {
            $this->travel(14)->minutes();
            $this->get(route('admin.dashboard'))->assertOk()
                ->assertHeader('X-Session-Timeout', '3600')
                ->assertHeader('X-Session-Expires-At', (string) (now()->timestamp + 3600))
                ->assertSessionHas('auth.last_activity_at', now()->timestamp);
            $this->post(route('admin.low-stocks.settings'), [
                'email_enabled' => 0, 'daily_summary_enabled' => 0, 'daily_summary_time' => '08:00',
            ])->assertRedirect()->assertSessionHas('auth.last_activity_at', now()->timestamp);
        }
        $this->assertAuthenticatedAs($owner);
        $this->getJson(route('session.status'))->assertOk()->assertJsonPath('idle_timeout_seconds', 3600)
            ->assertJsonPath('expires_at', now()->timestamp + 3600);
    }

    public function test_failed_forms_and_status_polls_do_not_extend_the_deadline(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $last = now()->subMinutes(50)->timestamp;
        $this->actingAs($owner)->withSession(['auth.last_activity_at' => $last]);
        $this->postJson(route('admin.low-stocks.settings'), [])->assertUnprocessable()
            ->assertSessionHas('auth.last_activity_at', $last);
        $this->getJson(route('session.status'))->assertOk()
            ->assertJsonPath('expires_at', $last + 3600)->assertSessionHas('auth.last_activity_at', $last);
        $this->travel(10)->minutes();
        $this->getJson(route('session.status'))->assertUnauthorized()->assertJsonPath('reason', 'idle_timeout')
            ->assertSessionHas('status', 'You were logged out after 60 minutes of inactivity. Please log in again.');
    }

    public function test_owner_timeout_metadata_and_storage_floor_share_the_configured_duration(): void
    {
        $this->assertSame((int) env('SESSION_LIFETIME', 60), config('session.idle_timeout_by_role.admin'));
        $this->assertGreaterThanOrEqual(config('session.idle_timeout_by_role.admin'), config('session.lifetime'));
        $this->assertGreaterThanOrEqual(config('session.idle_timeout_by_role.staff'), config('session.lifetime'));
        config(['session.idle_timeout_by_role.admin' => 75]);
        $owner = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($owner)->get(route('admin.dashboard'))->assertOk()->assertHeader('X-Session-Timeout', '4500');
        $this->assertStringContainsString('&quot;idle_timeout_seconds&quot;:4500', $response->getContent());
        $this->getJson(route('session.status'))->assertOk()->assertJsonPath('idle_timeout_seconds', 4500);
    }
}
