<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class CrossDeviceEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function link(User $user, int $minutes = 60): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]);
    }

    public function test_another_device_can_verify_the_email_without_logging_in(): void
    {
        Event::fake([Verified::class]);
        $staff = User::factory()->unverified()->create(['role' => 'staff', 'must_change_password' => true]);
        $this->get($this->link($staff))->assertOk()->assertSee('Email verified')->assertSee('Return to the device');
        $this->assertGuest();
        $this->assertTrue($staff->fresh()->hasVerifiedEmail());
        $this->assertTrue($staff->fresh()->must_change_password);
        Event::assertDispatched(Verified::class, 1);
        $this->get($this->link($staff))->assertOk();
        Event::assertDispatched(Verified::class, 1);
    }

    public function test_verifying_a_staff_link_does_not_replace_the_owner_session(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->unverified()->create(['role' => 'staff']);
        $this->actingAs($owner)->get($this->link($staff))->assertOk()->assertSee('Email verified');
        $this->assertAuthenticatedAs($owner);
        $this->assertTrue($staff->fresh()->hasVerifiedEmail());
    }

    public function test_main_device_detects_verification_from_another_device(): void
    {
        $staff = User::factory()->unverified()->create(['role' => 'staff']);
        $this->actingAs($staff)->get(route('verification.notice'))->assertOk()->assertSee('data-email-verification', false);
        $this->getJson(route('verification.status'))->assertOk()->assertJsonPath('verified', false)->assertJsonPath('redirect_url', null);
        Auth::logout();
        $this->get($this->link($staff))->assertOk();
        $this->assertGuest();
        $this->actingAs($staff)->getJson(route('verification.status'))->assertOk()->assertJsonPath('verified', true)
            ->assertJsonPath('redirect_url', route('staff.dashboard'));
        $this->get(route('verification.notice'))->assertRedirect(route('staff.dashboard'));
    }

    public function test_fresh_staff_can_verify_but_must_still_change_their_temporary_password(): void
    {
        $staff = User::factory()->unverified()->create(['role' => 'staff', 'must_change_password' => true]);
        $this->actingAs($staff)->get($this->link($staff))->assertRedirect(route('staff.password.edit'));
        $this->getJson(route('verification.status'))->assertOk()->assertJsonPath('verified', true)
            ->assertJsonPath('redirect_url', route('staff.password.edit'));
        $this->get(route('staff.pos'))->assertRedirect(route('staff.password.edit'));
    }

    public function test_expired_tampered_changed_email_and_disabled_account_links_are_rejected(): void
    {
        $staff = User::factory()->unverified()->create(['role' => 'staff']);
        $url = $this->link($staff);
        $this->get($this->link($staff, -1))->assertForbidden()->assertSee('verification link is unavailable');
        $this->get($url.'&tampered=1')->assertForbidden();
        $this->get(route('verification.verify', ['id' => $staff->id, 'hash' => sha1($staff->email)]))->assertForbidden();
        $this->assertFalse($staff->fresh()->hasVerifiedEmail());
        $staff->update(['email' => 'changed@example.com']);
        $this->get($url)->assertForbidden();
        $staff->update(['account_status' => 'disabled']);
        $this->get($this->link($staff))->assertForbidden();
        $this->assertFalse($staff->fresh()->hasVerifiedEmail());
    }

    public function test_verification_status_is_private_and_polling_does_not_extend_idle_timeout(): void
    {
        $this->getJson(route('verification.status'))->assertUnauthorized();
        $staff = User::factory()->unverified()->create(['role' => 'staff']);
        $lastActivity = now()->subMinutes(5)->timestamp;
        $this->actingAs($staff)->withSession(['auth.last_activity_at' => $lastActivity])
            ->getJson(route('verification.status'))->assertOk()->assertSessionHas('auth.last_activity_at', $lastActivity);
    }
}
