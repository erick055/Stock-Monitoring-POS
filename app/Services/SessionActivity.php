<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;

class SessionActivity
{
    public const KEY = 'auth.last_activity_at';

    public function minutes(User $user): int
    {
        return max(1, (int) config('session.idle_timeout_by_role.'.$user->role, 60));
    }

    public function snapshot(Request $request): array
    {
        $lastActivity = (int) $request->attributes->get(self::KEY, $request->session()->get(self::KEY, now()->timestamp));
        $seconds = $this->minutes($request->user()) * 60;

        return [
            'authenticated' => true,
            'idle_timeout_seconds' => $seconds,
            'last_activity_at' => $lastActivity,
            'expires_at' => $lastActivity + $seconds,
            'server_time' => now()->timestamp,
            'scope' => $request->user()->id.':'.hash('sha256', $request->session()->token()),
        ];
    }
}
