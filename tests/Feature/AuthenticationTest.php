<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_register_and_is_redirected_to_staff_dashboard(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test Staff', 'email' => 'staff@example.com', 'role' => 'staff',
            'password' => 'password', 'password_confirmation' => 'password',
        ]);
        $response->assertRedirect(route('staff.dashboard'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'staff@example.com', 'role' => 'staff']);
    }

    public function test_login_redirects_user_to_their_role_dashboard(): void
    {
        User::factory()->create(['email' => 'staff@example.com', 'role' => 'staff', 'password' => 'password']);
        $this->post('/login', ['email' => 'staff@example.com', 'password' => 'password', 'role' => 'staff'])
            ->assertRedirect(route('staff.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_user_cannot_login_using_the_wrong_role(): void
    {
        User::factory()->create(['email' => 'staff@example.com', 'role' => 'staff', 'password' => 'password']);
        $this->post('/login', ['email' => 'staff@example.com', 'password' => 'password', 'role' => 'admin'])
            ->assertSessionHasErrors('role');
        $this->assertGuest();
    }

    public function test_only_the_first_admin_can_register_publicly(): void
    {
        User::factory()->create(['role' => 'admin']);
        $this->post('/register', [
            'name' => 'Second Admin', 'email' => 'admin2@example.com', 'role' => 'admin',
            'password' => 'password', 'password_confirmation' => 'password',
        ])->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'admin2@example.com']);
    }

    public function test_staff_cannot_open_admin_dashboard(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff)->get('/admin/dashboard')->assertForbidden();
    }
}
