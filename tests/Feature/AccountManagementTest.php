<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

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
