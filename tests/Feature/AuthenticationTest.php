<?php

namespace Tests\Feature;

use App\Models\TrustedLoginDevice;
use App\Models\User;
use App\Notifications\LoginVerificationCodeNotification;
use App\Services\TrustedLoginDeviceService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_even_a_correct_code_cannot_bypass_the_attempt_limit(): void
    {
        $user = User::factory()->create();
        $user->loginVerificationCode()->create([
            'code_hash' => \Illuminate\Support\Facades\Hash::make('123456'), 'attempts' => 5,
            'expires_at' => now()->addMinutes(10), 'last_sent_at' => now(),
        ]);
        $this->withSession(['login_verification.user_id' => $user->id, 'login_verification.started_at' => now()->timestamp])
            ->post(route('login.verify.store'), ['code' => '123456'])
            ->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseMissing('login_verification_codes', ['user_id' => $user->id]);
    }

    public function test_incorrect_code_commits_its_attempt_before_returning_validation_error(): void
    {
        $user = User::factory()->create();
        $user->loginVerificationCode()->create([
            'code_hash' => \Illuminate\Support\Facades\Hash::make('123456'), 'attempts' => 0,
            'expires_at' => now()->addMinutes(10), 'last_sent_at' => now(),
        ]);
        $this->withSession(['login_verification.user_id' => $user->id, 'login_verification.started_at' => now()->timestamp])
            ->post(route('login.verify.store'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertDatabaseHas('login_verification_codes', ['user_id' => $user->id, 'attempts' => 1]);
        $this->assertGuest();
    }

    public function test_resending_invalidates_old_code_and_enforces_cooldown(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $oldHash = \Illuminate\Support\Facades\Hash::make('000000');
        $user->loginVerificationCode()->create([
            'code_hash' => $oldHash, 'attempts' => 0,
            'expires_at' => now()->addMinutes(10), 'last_sent_at' => now()->subSeconds(61),
        ]);
        $this->withSession(['login_verification.user_id' => $user->id, 'login_verification.started_at' => now()->timestamp])
            ->post(route('login.verify.resend'))->assertSessionHas('status');
        $newHash = $user->loginVerificationCode()->first()->code_hash;
        $this->assertNotSame($oldHash, $newHash);
        $this->post(route('login.verify.resend'))->assertSessionHasErrors('code');
        $this->assertSame($newHash, $user->loginVerificationCode()->first()->code_hash);
        Notification::assertSentToTimes($user, LoginVerificationCodeNotification::class, 1);
        $this->post(route('login.verify.store'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    private const STRONG_PASSWORD = 'Secure!Password123';

    public function test_public_registration_creates_a_pending_staff_request_without_logging_in(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test Staff',
            'email' => 'STAFF@example.com ',
            'role' => 'admin',
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
            'auth_mode' => 'register',
        ]);

        $response->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Registration received. The owner must approve your staff account before you can log in.');
        $this->assertGuest();
        $this->assertDatabaseHas('users', [
            'email' => 'staff@example.com',
            'role' => 'staff',
            'account_status' => 'pending',
            'email_verified_at' => null,
        ]);
    }

    public function test_pending_and_disabled_staff_cannot_start_login_verification(): void
    {
        Notification::fake();
        $pending = User::factory()->create([
            'email' => 'pending@example.com', 'password' => self::STRONG_PASSWORD,
            'role' => 'staff', 'account_status' => 'pending',
        ]);
        $disabled = User::factory()->create([
            'email' => 'disabled@example.com', 'password' => self::STRONG_PASSWORD,
            'role' => 'staff', 'account_status' => 'disabled',
        ]);

        $this->post('/login', ['email' => $pending->email, 'password' => self::STRONG_PASSWORD])
            ->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $disabled->email, 'password' => self::STRONG_PASSWORD])
            ->assertSessionHasErrors('email');

        Notification::assertNothingSent();
        $this->assertGuest();
    }

    public function test_registration_rejects_a_weak_password(): void
    {
        $this->post('/register', [
            'name' => 'Test Staff',
            'email' => 'staff@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('password');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'staff@example.com']);
    }

    public function test_login_uses_email_and_redirects_to_the_account_role_dashboard(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'staff@example.com',
            'role' => 'staff',
            'password' => self::STRONG_PASSWORD,
        ]);

        $this->post('/login', [
            'email' => 'STAFF@example.com ',
            'password' => self::STRONG_PASSWORD,
            'role' => 'admin',
        ])->assertRedirect(route('login.verify'));

        $this->assertGuest();

        Notification::assertSentTo($user, LoginVerificationCodeNotification::class, function ($notification) {
            $this->post(route('login.verify.store'), ['code' => $notification->code])
                ->assertRedirect(route('staff.dashboard'));

            return true;
        });

        $this->assertAuthenticated();
    }

    public function test_login_does_not_create_a_persistent_remember_cookie(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'remember@example.com',
            'role' => 'admin',
            'password' => self::STRONG_PASSWORD,
            'remember_token' => 'old-persistent-token',
        ]);

        $this->post('/login', [
            'email' => 'remember@example.com',
            'password' => self::STRONG_PASSWORD,
            'remember' => '1',
        ])->assertRedirect(route('login.verify'));

        Notification::assertSentTo($user, LoginVerificationCodeNotification::class, function ($notification) use (&$response) {
            $response = $this->post(route('login.verify.store'), ['code' => $notification->code]);

            return true;
        });

        $response->assertRedirect(route('admin.dashboard'))
            ->assertCookieExpired(Auth::guard()->getRecallerName())
            ->assertSessionHas('auth.last_activity_at');
        $this->assertAuthenticated();
        $this->assertNotSame('old-persistent-token', $user->fresh()->getRememberToken());
        $this->assertSame(43200, config('session.lifetime'));
        $this->assertSame(60, config('session.idle_timeout_by_role.admin'));
        $this->assertSame(43200, config('session.idle_timeout_by_role.staff'));
    }

    public function test_login_code_is_single_use(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'secure@example.com', 'password' => self::STRONG_PASSWORD]);

        $this->post('/login', ['email' => $user->email, 'password' => self::STRONG_PASSWORD]);

        Notification::assertSentTo($user, LoginVerificationCodeNotification::class, function ($notification) use ($user) {
            $this->post(route('login.verify.store'), ['code' => $notification->code])->assertRedirect();
            $this->post(route('logout'));
            $this->withSession([
                'login_verification.user_id' => $user->id,
                'login_verification.started_at' => now()->timestamp,
            ])->post(route('login.verify.store'), ['code' => $notification->code])
                ->assertRedirect(route('login'));

            return true;
        });
    }

    public function test_five_incorrect_login_codes_cancel_the_pending_login(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'secure@example.com', 'password' => self::STRONG_PASSWORD]);

        $this->post('/login', ['email' => $user->email, 'password' => self::STRONG_PASSWORD]);

        foreach (range(1, 4) as $attempt) {
            $this->post(route('login.verify.store'), ['code' => '000000'])->assertSessionHasErrors('code');
        }

        $this->post(route('login.verify.store'), ['code' => '000000'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseMissing('login_verification_codes', ['user_id' => $user->id]);
    }

    public function test_an_expired_login_code_cannot_authenticate(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'expired@example.com', 'password' => self::STRONG_PASSWORD]);

        $this->post('/login', ['email' => $user->email, 'password' => self::STRONG_PASSWORD]);
        $user->loginVerificationCode()->update(['expires_at' => now()->subSecond()]);

        Notification::assertSentTo($user, LoginVerificationCodeNotification::class, function ($notification) {
            $this->post(route('login.verify.store'), ['code' => $notification->code])
                ->assertRedirect(route('login'))
                ->assertSessionHasErrors('email');

            return true;
        });

        $this->assertGuest();
    }

    public function test_a_trusted_browser_and_ip_can_skip_the_email_code_for_thirty_days(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'trusted@example.com', 'password' => self::STRONG_PASSWORD,
            'role' => 'staff', 'account_status' => 'active',
        ]);
        $token = 'trusted-device-token';
        TrustedLoginDevice::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'ip_address' => '127.0.0.1',
            'user_agent_hash' => hash('sha256', 'MotoSyncTestBrowser'),
            'expires_at' => now()->addDays(30),
        ]);

        $this->withHeader('User-Agent', 'MotoSyncTestBrowser')
            ->withCookie(TrustedLoginDeviceService::COOKIE_NAME, $token)
            ->post('/login', ['email' => $user->email, 'password' => self::STRONG_PASSWORD])
            ->assertRedirect(route('staff.dashboard'));

        $this->assertAuthenticatedAs($user);
        Notification::assertNothingSent();
    }

    public function test_a_trusted_cookie_cannot_be_reused_from_another_ip_address(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'trusted@example.com', 'password' => self::STRONG_PASSWORD,
            'role' => 'staff', 'account_status' => 'active',
        ]);
        $token = 'trusted-device-token';
        TrustedLoginDevice::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'ip_address' => '192.0.2.10',
            'user_agent_hash' => hash('sha256', 'MotoSyncTestBrowser'),
            'expires_at' => now()->addDays(30),
        ]);

        $this->withHeader('User-Agent', 'MotoSyncTestBrowser')
            ->withCookie(TrustedLoginDeviceService::COOKIE_NAME, $token)
            ->post('/login', ['email' => $user->email, 'password' => self::STRONG_PASSWORD])
            ->assertRedirect(route('login.verify'));

        $this->assertGuest();
        Notification::assertSentTo($user, LoginVerificationCodeNotification::class);
    }

    public function test_user_is_logged_out_after_sixty_minutes_without_activity(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->withSession(['auth.last_activity_at' => now()->subMinutes(60)->timestamp])
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'You were logged out after 60 minutes of inactivity. Please log in again.');

        $this->assertGuest();
    }

    public function test_activity_before_sixty_minutes_keeps_the_user_logged_in(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $oldActivity = now()->subMinutes(59)->timestamp;

        $this->actingAs($user)
            ->withSession(['auth.last_activity_at' => $oldActivity])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSessionHas('auth.last_activity_at', fn ($timestamp) => $timestamp > $oldActivity);

        $this->assertAuthenticatedAs($user);
    }

    public function test_staff_pos_session_remains_active_for_up_to_thirty_days(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $oldActivity = now()->subDays(29)->timestamp;

        $this->actingAs($staff)
            ->withSession(['auth.last_activity_at' => $oldActivity])
            ->get(route('staff.pos'))
            ->assertOk()
            ->assertSessionHas('auth.last_activity_at', fn ($timestamp) => $timestamp > $oldActivity);

        $this->assertAuthenticatedAs($staff);
    }

    public function test_staff_is_logged_out_after_thirty_days_without_activity(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($staff)
            ->withSession(['auth.last_activity_at' => now()->subDays(30)->timestamp])
            ->get(route('staff.pos'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'You were logged out after 30 days of inactivity. Please log in again.');

        $this->assertGuest();
    }

    public function test_logged_in_browser_is_redirected_to_its_account_dashboard(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($admin)->get('/')->assertRedirect(route('admin.dashboard'));
        $this->actingAs($staff)->get('/')->assertRedirect(route('staff.dashboard'));
    }

    public function test_login_is_throttled_after_five_failed_attempts(): void
    {
        User::factory()->create([
            'email' => 'staff@example.com',
            'password' => self::STRONG_PASSWORD,
        ]);

        foreach (range(1, 5) as $attempt) {
            $this->from('/')->post('/login', [
                'email' => 'staff@example.com',
                'password' => 'incorrect-password',
            ])->assertSessionHasErrors('email');
        }

        $this->from('/')->post('/login', [
            'email' => 'staff@example.com',
            'password' => self::STRONG_PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_unverified_user_cannot_open_a_protected_dashboard(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'staff']);

        $this->actingAs($user)
            ->get('/staff/dashboard')
            ->assertRedirect(route('verification.notice'));
    }

    public function test_user_can_verify_their_email_with_a_signed_link(): void
    {
        Event::fake();
        $user = User::factory()->unverified()->create(['role' => 'staff']);
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())],
        );

        $this->actingAs($user)->get($url)->assertRedirect(route('staff.dashboard'));

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Event::assertDispatched(Verified::class);
    }

    public function test_staff_cannot_open_admin_dashboard(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($staff)->get('/admin/dashboard')->assertForbidden();
    }

    public function test_navigation_preserves_the_application_subdirectory(): void
    {
        URL::forceRootUrl('http://localhost/stock-pos/public');

        $this->assertSame(
            'http://localhost/stock-pos/public/admin/inventory',
            url('/admin/inventory'),
        );

        URL::forceRootUrl('http://localhost');
    }

    public function test_only_a_verified_user_can_be_promoted_from_the_console(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com', 'role' => 'staff']);

        $this->artisan('user:promote-admin', ['email' => 'OWNER@example.com'])
            ->expectsConfirmation('Promote owner@example.com to administrator?', 'yes')
            ->assertSuccessful();

        $this->assertSame('admin', $user->fresh()->role);
    }
}
