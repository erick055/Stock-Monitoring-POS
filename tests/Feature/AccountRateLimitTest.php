<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_background_checks_do_not_block_staff_deletion_or_account_changes(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($owner);
        for ($i = 0; $i < 25; $i++) {
            $this->getJson(route('session.status'))->assertOk();
        }
        $this->patch(route('admin.accounts.disable', $staff))->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('disabled', $staff->fresh()->account_status);
        $this->delete(route('admin.accounts.destroy', $staff), ['confirm_delete' => '1'])
            ->assertRedirect(route('admin.accounts'))->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertDatabaseMissing('users', ['id' => $staff->id]);
    }

    public function test_delete_cooldown_stays_on_account_page_and_preserves_the_blocked_account(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $accounts = User::factory()->count(11)->create(['role' => 'staff']);
        $this->actingAs($owner);
        foreach ($accounts->take(10) as $staff) {
            $this->delete(route('admin.accounts.destroy', $staff), ['confirm_delete' => '1'])
                ->assertRedirect(route('admin.accounts'))->assertSessionHasNoErrors();
            $this->assertDatabaseMissing('users', ['id' => $staff->id]);
        }
        $last = $accounts->last();
        $this->delete(route('admin.accounts.destroy', $last), ['confirm_delete' => '1'])
            ->assertRedirect(route('admin.accounts'))->assertSessionHasErrors('account_rate_limit')->assertHeader('Retry-After');
        $this->assertDatabaseHas('users', ['id' => $last->id]);
        $this->get(route('admin.accounts'))->assertOk()->assertSee('before another account action')->assertSee('No changes were made.');
        $this->travel(61)->seconds();
        $this->delete(route('admin.accounts.destroy', $last), ['confirm_delete' => '1'])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('users', ['id' => $last->id]);
    }

    public function test_one_owners_deletion_limit_does_not_block_another_owner_or_other_account_actions(): void
    {
        $first = User::factory()->create(['role' => 'admin']);
        $second = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($first);
        for ($i = 0; $i < 10; $i++) {
            $this->delete(route('admin.accounts.destroy', $staff))->assertSessionHasErrors('confirm_delete');
        }
        $this->deleteJson(route('admin.accounts.destroy', $staff), ['confirm_delete' => '1'])
            ->assertStatus(429)->assertJsonStructure(['message', 'retry_after']);
        $this->patch(route('admin.accounts.disable', $staff))->assertSessionHasNoErrors();
        $this->assertSame('disabled', $staff->fresh()->account_status);
        $this->actingAs($second)->delete(route('admin.accounts.destroy', $staff), ['confirm_delete' => '1'])
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.accounts'));
        $this->assertDatabaseMissing('users', ['id' => $staff->id]);
    }
}
