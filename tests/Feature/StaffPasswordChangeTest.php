<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\LoginVerificationCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StaffPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPORARY = 'Temporary!Password123';

    private const PERSONAL = 'Personal!Password456';

    public function test_login_has_no_forgot_password_link(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('Forgot password?')->assertDontSee(route('password.request'));
    }

    public function test_new_staff_must_change_password_after_login_verification(): void
    {
        Notification::fake();
        $staff = User::factory()->create(['role' => 'staff', 'password' => self::TEMPORARY, 'must_change_password' => true]);
        $this->post(route('login.store'), ['email' => $staff->email, 'password' => self::TEMPORARY])
            ->assertRedirect(route('login.verify'));
        Notification::assertSentTo($staff, LoginVerificationCodeNotification::class, function ($notification) {
            $this->post(route('login.verify.store'), ['code' => $notification->code])
                ->assertRedirect(route('staff.password.edit'));

            return true;
        });
        $this->get(route('staff.password.edit'))->assertOk()->assertSee('Create your own password')->assertSee('<dialog open', false);
        $this->get(route('staff.pos'))->assertRedirect(route('staff.password.edit'));
        $this->get(route('staff.dashboard'))->assertRedirect(route('staff.password.edit'));
        $this->postJson(route('staff.pos.checkout'), [])->assertForbidden()->assertJsonPath('password_change_url', route('staff.password.edit'));
        $this->assertDatabaseCount('sales_transactions', 0);
    }

    public function test_staff_can_change_password_and_continue_without_being_signed_out(): void
    {
        $staff = User::factory()->create(['role' => 'staff', 'password' => self::TEMPORARY, 'must_change_password' => true]);
        $this->actingAs($staff)->withSession(['password_hash_web' => $staff->password])
            ->post(route('staff.password.update'), [
                'current_password' => self::TEMPORARY, 'password' => self::PERSONAL, 'password_confirmation' => self::PERSONAL,
            ])->assertRedirect(route('staff.dashboard'));
        $staff->refresh();
        $this->assertFalse($staff->must_change_password);
        $this->assertTrue(Hash::check(self::PERSONAL, $staff->password));
        $this->assertFalse(Hash::check(self::TEMPORARY, $staff->password));
        $this->get(route('staff.dashboard'))->assertOk();
        $this->get(route('staff.password.edit'))->assertRedirect(route('staff.dashboard'));
        $this->assertAuthenticatedAs($staff);
    }

    public function test_staff_cannot_keep_the_temporary_password_or_submit_invalid_credentials(): void
    {
        $staff = User::factory()->create(['role' => 'staff', 'password' => self::TEMPORARY, 'must_change_password' => true]);
        $this->actingAs($staff)->post(route('staff.password.update'), [
            'current_password' => self::TEMPORARY, 'password' => self::TEMPORARY, 'password_confirmation' => self::TEMPORARY,
        ])->assertSessionHasErrors('password');
        $this->post(route('staff.password.update'), [
            'current_password' => 'wrong', 'password' => 'weak', 'password_confirmation' => 'different',
        ])->assertSessionHasErrors(['current_password', 'password']);
        $this->assertTrue($staff->fresh()->must_change_password);
        $this->get(route('staff.dashboard'))->assertRedirect(route('staff.password.edit'));
    }

    public function test_owner_created_and_reset_passwords_are_temporary(): void
    {
        Notification::fake();
        $owner = User::factory()->create(['role' => 'admin']);
        $this->actingAs($owner)->post(route('admin.accounts.store'), [
            'name' => 'New Staff', 'email' => 'new-staff@example.com',
            'current_password' => 'password',
            'password' => self::TEMPORARY, 'password_confirmation' => self::TEMPORARY,
        ])->assertSessionHas('success');
        $staff = User::where('email', 'new-staff@example.com')->firstOrFail();
        $this->assertTrue($staff->must_change_password);
        $staff->update(['must_change_password' => false]);
        $this->patch(route('admin.accounts.update', $staff), [
            'name' => $staff->name, 'email' => $staff->email,
            'password' => self::PERSONAL, 'password_confirmation' => self::PERSONAL,
        ])->assertSessionHas('success');
        $this->assertTrue($staff->fresh()->must_change_password);
    }

    public function test_existing_staff_and_owners_are_not_forced_to_change_password(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff)->get(route('staff.dashboard'))->assertOk();
        $owner = User::factory()->create(['role' => 'admin', 'must_change_password' => true]);
        $this->actingAs($owner)->get(route('admin.dashboard'))->assertOk();
        $this->get(route('staff.password.edit'))->assertForbidden();
    }
}
