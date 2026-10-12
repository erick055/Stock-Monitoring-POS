<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_an_active_staff_account_and_sends_verification(): void
    {
        Notification::fake();
        $owner = User::factory()->create(['role' => 'admin']);
        $this->actingAs($owner)->post(route('admin.accounts.store'), [
            'name' => 'New Worker', 'email' => 'WORKER@example.com ', 'role' => 'admin',
            'password' => 'Secure!Password123', 'password_confirmation' => 'Secure!Password123',
        ])->assertRedirect(route('admin.accounts'))->assertSessionHas('success');
        $staff = User::where('email', 'worker@example.com')->firstOrFail();
        $this->assertSame('staff', $staff->role);
        $this->assertSame('active', $staff->account_status);
        $this->assertSame($owner->id, $staff->approved_by);
        $this->assertNull($staff->email_verified_at);
        $this->assertTrue(Hash::check('Secure!Password123', $staff->password));
        Notification::assertSentTo($staff, VerifyEmail::class);
    }

    public function test_staff_cannot_create_or_edit_accounts_and_owners_cannot_edit_other_owners(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $owner = User::factory()->create(['role' => 'admin']);
        $this->actingAs($staff)->post(route('admin.accounts.store'))->assertForbidden();
        $this->patch(route('admin.accounts.update', $staff))->assertForbidden();
        $this->actingAs($owner)->patch(route('admin.accounts.update', $owner))->assertNotFound();
    }

    public function test_owner_edit_preserves_password_when_blank_and_revokes_access_when_changed(): void
    {
        config(['session.driver' => 'database']);
        $owner = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff', 'password' => 'Original!Password123']);
        $originalHash = $staff->password;
        $this->actingAs($owner)->patch(route('admin.accounts.update', $staff), [
            'name' => 'Updated Worker', 'email' => $staff->email, 'password' => '',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('Updated Worker', $staff->fresh()->name);
        $this->assertSame($originalHash, $staff->fresh()->password);
        DB::table('sessions')->insert(['id' => 'edit-session', 'user_id' => $staff->id,
            'payload' => '', 'last_activity' => now()->timestamp]);
        $staff->loginVerificationCode()->create(['code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'last_sent_at' => now()]);
        $this->patch(route('admin.accounts.update', $staff), [
            'name' => 'Updated Worker', 'email' => $staff->email,
            'password' => 'Changed!Password123', 'password_confirmation' => 'Changed!Password123',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertTrue(Hash::check('Changed!Password123', $staff->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'edit-session']);
        $this->assertDatabaseMissing('login_verification_codes', ['user_id' => $staff->id]);
    }

    public function test_email_changes_require_verification_and_duplicate_emails_and_weak_passwords_are_rejected(): void
    {
        Notification::fake();
        $owner = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($owner)->post(route('admin.accounts.store'), [
            'name' => 'Worker', 'email' => $owner->email, 'password' => 'weak', 'password_confirmation' => 'weak',
        ])->assertSessionHasErrors(['email', 'password'], null, 'createAccount');
        $this->patch(route('admin.accounts.update', $staff), [
            'name' => $staff->name, 'email' => 'new-worker@example.com',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertNull($staff->fresh()->email_verified_at);
        Notification::assertSentTo($staff->fresh(), VerifyEmail::class);
    }

    public function test_owner_can_view_staff_accounts_but_staff_cannot(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $pending = User::factory()->create(['role' => 'staff', 'account_status' => 'pending', 'name' => 'Pending Worker']);

        $this->actingAs($owner)->get(route('admin.accounts'))
            ->assertOk()
            ->assertSee('Account Management')
            ->assertSee($pending->email)
            ->assertSee('Approve as staff');

        $this->actingAs($staff)->get(route('admin.accounts'))->assertForbidden();
    }

    public function test_owner_can_approve_a_pending_registration(): void
    {
        Notification::fake();
        $owner = User::factory()->create(['role' => 'admin']);
        $pending = User::factory()->unverified()->create(['role' => 'staff', 'account_status' => 'pending']);

        $this->actingAs($owner)->patch(route('admin.accounts.approve', $pending))
            ->assertRedirect()
            ->assertSessionHas('success');

        $pending->refresh();
        $this->assertSame('active', $pending->account_status);
        $this->assertSame($owner->id, $pending->approved_by);
        $this->assertNotNull($pending->approved_at);
        Notification::assertSentTo($pending, VerifyEmail::class);
    }

    public function test_owner_can_disable_staff_and_revoke_existing_sessions(): void
    {
        config(['session.driver' => 'database']);
        $owner = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff', 'account_status' => 'active']);
        DB::table('sessions')->insert([
            'id' => 'staff-session', 'user_id' => $staff->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'Test', 'payload' => '', 'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($owner)->patch(route('admin.accounts.disable', $staff), [
            'disabled_reason' => 'No longer employed by the shop.',
        ])->assertRedirect()->assertSessionHas('success');

        $staff->refresh();
        $this->assertSame('disabled', $staff->account_status);
        $this->assertSame($owner->id, $staff->disabled_by);
        $this->assertSame('No longer employed by the shop.', $staff->disabled_reason);
        $this->assertDatabaseMissing('sessions', ['id' => 'staff-session']);
    }

    public function test_owner_can_restore_a_disabled_staff_account(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create([
            'role' => 'staff', 'account_status' => 'disabled',
            'disabled_at' => now(), 'disabled_by' => $owner->id, 'disabled_reason' => 'Temporary suspension',
        ]);

        $this->actingAs($owner)->patch(route('admin.accounts.reactivate', $staff))
            ->assertRedirect()->assertSessionHas('success');

        $staff->refresh();
        $this->assertSame('active', $staff->account_status);
        $this->assertNull($staff->disabled_at);
        $this->assertNull($staff->disabled_reason);
    }

    public function test_account_actions_cannot_target_an_owner_account(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $otherOwner = User::factory()->create(['role' => 'admin']);

        $this->actingAs($owner)->patch(route('admin.accounts.disable', $otherOwner), [
            'disabled_reason' => 'Invalid request',
        ])->assertNotFound();
    }
}
