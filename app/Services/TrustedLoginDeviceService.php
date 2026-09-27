<?php

namespace App\Services;

use App\Models\TrustedLoginDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

class TrustedLoginDeviceService
{
    public const COOKIE_NAME = 'motosync_trusted_device';
    public const LIFETIME_MINUTES = 60 * 24 * 30;

    public function isTrusted(Request $request, User $user): bool
    {
        $token = (string) $request->cookie(self::COOKIE_NAME, '');
        if ($token === '') {
            return false;
        }

        $device = TrustedLoginDevice::query()
            ->where('user_id', $user->id)
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (! $device || $device->expires_at->isPast()
            || ! hash_equals($device->ip_address, (string) $request->ip())
            || ! hash_equals($device->user_agent_hash, $this->userAgentHash($request))) {
            $device?->delete();
            Cookie::queue(Cookie::forget(self::COOKIE_NAME));

            return false;
        }

        $device->update(['last_used_at' => now()]);

        return true;
    }

    public function trust(Request $request, User $user): void
    {
        $token = Str::random(64);

        $user->trustedLoginDevices()->where('expires_at', '<=', now())->delete();
        $user->trustedLoginDevices()->create([
            'token_hash' => hash('sha256', $token),
            'ip_address' => (string) $request->ip(),
            'user_agent_hash' => $this->userAgentHash($request),
            'last_used_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);

        Cookie::queue(cookie(
            self::COOKIE_NAME,
            $token,
            self::LIFETIME_MINUTES,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'lax',
        ));
    }

    private function userAgentHash(Request $request): string
    {
        return hash('sha256', (string) $request->userAgent());
    }
}
