<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;

class RegisteredUserController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'string', 'lowercase', 'email:rfc', 'max:255', 'unique:'.User::class],
            'password' => [
                'required',
                'confirmed',
                Rules\Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
        ]);

        // Public registration creates a pending request. Only an owner may activate staff access.
        $validated['role'] = 'staff';
        $validated['account_status'] = 'pending';

        User::create($validated);

        return redirect()->route('login')->with(
            'status',
            'Registration received. The owner must approve your staff account before you can log in.'
        );
    }
}
