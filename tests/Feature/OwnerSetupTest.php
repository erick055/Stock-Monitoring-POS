<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OwnerSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_be_created_with_private_chosen_credentials(): void
    {
        $this->artisan('owner:setup')
            ->expectsQuestion('Owner email', 'OWNER@example.com ')
            ->expectsQuestion('Owner name', 'Shop Owner')
            ->expectsQuestion('Owner password', 'MyPrivate!Password123')
            ->expectsQuestion('Confirm owner password', 'MyPrivate!Password123')
            ->assertSuccessful();
        $owner = User::where('email', 'owner@example.com')->firstOrFail();
        $this->assertSame('admin', $owner->role);
        $this->assertSame('active', $owner->account_status);
        $this->assertTrue($owner->hasVerifiedEmail());
        $this->assertTrue(Hash::check('MyPrivate!Password123', $owner->password));
        $this->assertNotSame('MyPrivate!Password123', $owner->password);
    }

    public function test_setup_does_not_promote_a_staff_account(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->artisan('owner:setup')->expectsQuestion('Owner email', $staff->email)->assertFailed();
        $this->assertSame('staff', $staff->fresh()->role);
    }

    public function test_setup_rejects_weak_or_unconfirmed_passwords(): void
    {
        $this->artisan('owner:setup')->expectsQuestion('Owner email', 'owner@example.com')
            ->expectsQuestion('Owner name', 'Owner')->expectsQuestion('Owner password', 'weak')
            ->expectsQuestion('Confirm owner password', 'different')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_resetting_an_existing_owner_requires_confirmation(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $hash = $owner->password;
        $this->artisan('owner:setup')->expectsQuestion('Owner email', $owner->email)
            ->expectsConfirmation('Reset this owner account password and sign out its existing sessions?', 'no')
            ->assertSuccessful();
        $this->assertSame($hash, $owner->fresh()->password);
    }
}
