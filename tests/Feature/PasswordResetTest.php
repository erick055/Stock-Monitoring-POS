<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_revokes_only_the_users_sessions_devices_and_pending_codes(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        $other = User::factory()->create();
        foreach (['old-session-one' => $user, 'old-session-two' => $user, 'other-session' => $other] as $id => $owner) {
            DB::table('sessions')->insert([
                'id' => $id, 'user_id' => $owner->id, 'payload' => '', 'last_activity' => now()->timestamp,
            ]);
        }
        $user->trustedLoginDevices()->create([
            'token_hash' => hash('sha256', 'old-token'), 'ip_address' => '127.0.0.1',
            'user_agent_hash' => hash('sha256', 'test'), 'expires_at' => now()->addDays(30),
        ]);
        $user->loginVerificationCode()->create([
            'code_hash' => Hash::make('123456'), 'attempts' => 0,
            'expires_at' => now()->addMinutes(10), 'last_sent_at' => now(),
        ]);
        $this->post(route('password.update'), [
            'token' => Password::createToken($user), 'email' => $user->email,
            'password' => 'New-Secure-Password-123!', 'password_confirmation' => 'New-Secure-Password-123!',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'other-session', 'user_id' => $other->id]);
        $this->assertDatabaseMissing('trusted_login_devices', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('login_verification_codes', ['user_id' => $user->id]);
        $this->assertTrue(Hash::check('New-Secure-Password-123!', $user->fresh()->password));
    }

    public function test_session_with_an_old_password_hash_cannot_access_a_protected_page(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $oldHash = $user->password;
        $user->forceFill(['password' => Hash::make('New-Secure-Password-123!')])->save();

        $this->actingAs($user)->withSession(['password_hash_web' => $oldHash])
            ->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_forgot_password_page_is_available(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Forgot your password?');
    }

    public function test_a_reset_link_can_be_requested(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_password_can_be_reset_with_a_valid_token(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $response = $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'New-Secure-Password-123!',
                'password_confirmation' => 'New-Secure-Password-123!',
            ]);

            $response->assertRedirect(route('login'))->assertSessionHas('status');
            $this->assertTrue(Hash::check('New-Secure-Password-123!', $user->fresh()->password));

            return true;
        });
    }
}
