<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\LoginVerificationCodeNotification;
use App\Services\SingleSessionLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SingleSessionLoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Secure!Password123';

    public function test_second_owner_login_rejects_the_first_device_but_keeps_the_new_device(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'password' => self::PASSWORD]);
        $payload = ['email' => $owner->email, 'password' => self::PASSWORD];
        $this->post(route('login.store'), $payload)->assertRedirect(route('admin.dashboard'));
        $firstToken = session(SingleSessionLogin::SESSION_KEY);
        Auth::logout(); // Simulate an unauthenticated second browser.
        $this->post(route('login.store'), $payload)->assertRedirect(route('admin.dashboard'));
        $secondToken = session(SingleSessionLogin::SESSION_KEY);
        $this->assertNotSame($firstToken, $secondToken);
        $this->assertSame(hash('sha256', $secondToken), $owner->fresh()->active_session_hash);
        $this->actingAs($owner->fresh())->withSession([SingleSessionLogin::SESSION_KEY => $firstToken])
            ->getJson(route('session.status'))->assertUnauthorized()->assertJsonPath('login_url', route('login'));
        $this->assertGuest();
        $this->actingAs($owner->fresh())->withSession([SingleSessionLogin::SESSION_KEY => $secondToken])
            ->getJson(route('session.status'))->assertOk();
        $this->get(route('admin.dashboard'))->assertOk();
        $this->assertArrayNotHasKey('active_session_hash', $owner->fresh()->toArray());
    }

    public function test_login_deletes_only_that_accounts_database_sessions(): void
    {
        config(['session.driver' => 'database']);
        $owner = User::factory()->create(['role' => 'admin', 'password' => self::PASSWORD]);
        $other = User::factory()->create(['role' => 'staff']);
        foreach (['old-owner-session' => $owner->id, 'other-account-session' => $other->id] as $id => $userId) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => now()->timestamp]);
        }
        $this->post(route('login.store'), ['email' => $owner->email, 'password' => self::PASSWORD])->assertRedirect();
        $this->assertDatabaseMissing('sessions', ['id' => 'old-owner-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-account-session']);
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_staff_session_is_replaced_only_after_correct_email_code(): void
    {
        Notification::fake();
        $staff = User::factory()->create(['role' => 'staff', 'password' => self::PASSWORD]);
        $oldToken = 'previous-device-token';
        $staff->forceFill(['active_session_hash' => hash('sha256', $oldToken)])->save();
        $this->post(route('login.store'), ['email' => $staff->email, 'password' => self::PASSWORD])->assertRedirect(route('login.verify'));
        $this->assertSame(hash('sha256', $oldToken), $staff->fresh()->active_session_hash);
        $code = Notification::sent($staff, LoginVerificationCodeNotification::class)->first()->code;
        $wrongCode = $code === '123456' ? '654321' : '123456';
        $this->post(route('login.verify.store'), ['code' => $wrongCode])->assertSessionHasErrors('code');
        $this->assertSame(hash('sha256', $oldToken), $staff->fresh()->active_session_hash);
        $this->post(route('login.verify.store'), ['code' => $code])->assertRedirect(route('staff.dashboard'));
        $this->assertNotSame(hash('sha256', $oldToken), $staff->fresh()->active_session_hash);
        $this->actingAs($staff->fresh())->withSession([SingleSessionLogin::SESSION_KEY => $oldToken])
            ->postJson(route('staff.pos.checkout'), [])->assertUnauthorized();
        $this->assertDatabaseCount('sales_transactions', 0);
    }

    public function test_wrong_password_does_not_sign_out_an_existing_device(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'password' => self::PASSWORD]);
        $owner->forceFill(['active_session_hash' => hash('sha256', 'existing')])->save();
        $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'incorrect'])->assertSessionHasErrors('email');
        $this->assertSame(hash('sha256', 'existing'), $owner->fresh()->active_session_hash);
    }

    public function test_old_sessions_without_a_token_are_rejected_after_a_new_login(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'password' => self::PASSWORD]);
        $this->post(route('login.store'), ['email' => $owner->email, 'password' => self::PASSWORD])->assertRedirect();
        session()->forget(SingleSessionLogin::SESSION_KEY);
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Your account was signed in on another device. Please log in again.');
        $this->assertGuest();
    }

    public function test_session_checks_do_not_extend_idle_timeout(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'password' => self::PASSWORD]);
        $this->post(route('login.store'), ['email' => $owner->email, 'password' => self::PASSWORD])->assertRedirect();
        $previous = now()->subMinutes(20)->timestamp;
        $this->withSession(['auth.last_activity_at' => $previous])->getJson(route('session.status'))
            ->assertOk()->assertSessionHas('auth.last_activity_at', $previous);
    }
}
