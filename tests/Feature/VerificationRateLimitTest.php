<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class VerificationRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_polling_does_not_block_resend_and_cooldown_displays_on_verification_page(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['role' => 'staff', 'must_change_password' => false]);
        $this->actingAs($user);
        for ($i = 0; $i < 20; $i++) {
            $this->getJson(route('verification.status'))->assertOk();
        }
        $this->from(route('verification.notice'))->post(route('verification.send'))
            ->assertRedirect(route('verification.notice'))->assertSessionHasNoErrors();
        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
        $this->post(route('verification.send'))->assertRedirect(route('verification.notice'))
            ->assertSessionHasErrors('verification')->assertHeader('Retry-After');
        $this->get(route('verification.notice'))->assertOk()->assertSee('before requesting another verification email');
        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
        $this->travel(61)->seconds();
        $this->post(route('verification.send'))->assertSessionHasNoErrors();
        Notification::assertSentToTimes($user, VerifyEmail::class, 2);
    }
}
