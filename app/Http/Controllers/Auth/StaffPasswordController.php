<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StaffPasswordController extends Controller
{
    public function edit(Request $request): View|RedirectResponse
    {
        if (! $request->user()->must_change_password) return redirect()->route('staff.dashboard');
        return view('auth.staff-password');
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->must_change_password, 403);
        $validated = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        $user = DB::transaction(function () use ($request, $validated) {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($user->role === 'staff' && $user->account_status === 'active' && $user->must_change_password, 403);
            if (! Hash::check($validated['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'Your temporary password has changed. Please log in again.']);
            }
            if (Hash::check($validated['password'], $user->password)) {
                throw ValidationException::withMessages(['password' => 'Choose a new password different from your temporary password.']);
            }
            $user->forceFill([
                'password' => $validated['password'], 'must_change_password' => false,
                'remember_token' => Str::random(60),
            ])->save();
            $user->trustedLoginDevices()->delete();
            $user->loginVerificationCode()->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                    ->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
            }
            return $user;
        });
        $request->user()->setRawAttributes($user->getAttributes(), true);
        $request->session()->regenerate();
        $request->session()->put('password_hash_web', $user->getAuthPassword());
        $request->session()->forget('url.intended');

        return redirect()->route('staff.dashboard')->with('success', 'Your password has been changed.');
    }
}
