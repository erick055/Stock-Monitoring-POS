<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG_PASSWORD = 'Secure!Password123';

    public function test_public_registration_creates_only_an_unverified_staff_account(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test Staff',
            'email' => 'STAFF@example.com ',
            'role' => 'admin',
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
            'auth_mode' => 'register',
        ]);

        $response->assertRedirect(route('verification.notice'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'staff@example.com',
            'role' => 'staff',
            'email_verified_at' => null,
        ]);
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
        User::factory()->create([
            'email' => 'staff@example.com',
            'role' => 'staff',
            'password' => self::STRONG_PASSWORD,
        ]);

        $this->post('/login', [
            'email' => 'STAFF@example.com ',
            'password' => self::STRONG_PASSWORD,
            'role' => 'admin',
        ])->assertRedirect(route('staff.dashboard'));

        $this->assertAuthenticated();
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
