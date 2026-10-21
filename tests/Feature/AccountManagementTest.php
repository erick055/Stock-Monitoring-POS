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

    public function test_owner_can_disable_a_temporary_password_account_without_a_reason(): void
    {
        Notification::fake();
        $owner = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff', 'must_change_password' => true, 'password' => 'Temporary!Password123']);
        $this->actingAs($owner)->patch(route('admin.accounts.disable', $staff))
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('disabled', $staff->fresh()->account_status);
        $this->assertSame('Access disabled by the owner.', $staff->fresh()->disabled_reason);
        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => $staff->email, 'password' => 'Temporary!Password123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        Notification::assertNothingSent();
    }

    public function test_disabled_staff_cannot_continue_the_password_change_popup(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff', 'must_change_password' => true]);
        $this->actingAs($owner)->patch(route('admin.accounts.disable', $staff))->assertSessionHas('success');
        $this->actingAs($staff->fresh())->get(route('staff.password.edit'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_deleting_staff_removes_the_database_row_and_access_but_preserves_business_history(): void
    {
        config(['session.driver' => 'database']);
        $owner = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff', 'must_change_password' => true]);
        $sale = \App\Models\SalesTransaction::create(['staff_id' => $staff->id, 'sale_date' => now(), 'total_sale_amount' => 100]);
        $supplier = \App\Models\Supplier::create(['name' => 'History Supplier', 'code' => 'STAFF-DELETE']);
        $import = \App\Models\SupplierImport::create(['supplier_id' => $supplier->supplier_id, 'uploaded_by' => $staff->id,
            'source_filename' => 'history.csv', 'status' => 'approved']);
        $product = \App\Models\Product::create(['sku' => 'DELETE-HISTORY', 'name' => 'History Part']);
        $promotion = \App\Models\ProductPromotion::create(['product_id' => $product->product_id, 'applied_by' => $staff->id,
            'action_type' => 'discount', 'discount_percent' => 10, 'original_price' => 100,
            'promotional_price' => 90, 'status' => 'active', 'started_at' => now()]);
        $staff->loginVerificationCode()->create(['code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'last_sent_at' => now()]);
        $staff->trustedLoginDevices()->create(['token_hash' => hash('sha256', 'delete-device'), 'ip_address' => '127.0.0.1',
            'user_agent_hash' => hash('sha256', 'Test'), 'last_used_at' => now(), 'expires_at' => now()->addDays(30)]);
        DB::table('sessions')->insert(['id' => 'delete-session', 'user_id' => $staff->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        DB::table('password_reset_tokens')->insert(['email' => $staff->email, 'token' => 'test-token', 'created_at' => now()]);

        $this->actingAs($owner)->delete(route('admin.accounts.destroy', $staff), ['confirm_delete' => '1'])
            ->assertRedirect(route('admin.accounts'))->assertSessionHas('success');
        $this->assertDatabaseMissing('users', ['id' => $staff->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'delete-session']);
        $this->assertDatabaseMissing('login_verification_codes', ['user_id' => $staff->id]);
        $this->assertDatabaseMissing('trusted_login_devices', ['user_id' => $staff->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $staff->email]);
        $this->assertNotNull($sale->fresh());
        $this->assertNull($sale->fresh()->staff_id);
        $this->assertNull($import->fresh()->uploaded_by);
        $this->assertNull($promotion->fresh()->applied_by);
        $this->get(route('admin.pos.receipts.show', $sale))->assertOk()->assertSee('Former staff');
    }

    public function test_staff_deletion_requires_confirmation_and_only_owners_can_delete_staff(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff)->delete(route('admin.accounts.destroy', $staff), ['confirm_delete' => '1'])->assertForbidden();
        $this->actingAs($owner)->delete(route('admin.accounts.destroy', $owner), ['confirm_delete' => '1'])->assertNotFound();
        $this->delete(route('admin.accounts.destroy', $staff))->assertSessionHasErrors('confirm_delete');
        $this->assertDatabaseHas('users', ['id' => $staff->id]);
    }

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
