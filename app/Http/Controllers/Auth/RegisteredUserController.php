<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

class RegisteredUserController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'role' => ['required', Rule::in(['admin', 'staff'])],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        if ($validated['role'] === 'admin' && User::where('role', 'admin')->exists()) {
            throw ValidationException::withMessages([
                'role' => 'An administrator already exists. Ask the current administrator to create another admin account.',
            ]);
        }

        $user = User::create($validated);
        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route($user->role.'.dashboard');
    }
}
