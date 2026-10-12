<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Throwable;

class AccountManagementController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $validated = $request->validateWithBag('createAccount', [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users')],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        $user = User::create($validated + [
            'role' => 'staff', 'account_status' => 'active',
            'approved_by' => $request->user()->id, 'approved_at' => now(),
        ]);

        return redirect()->route('admin.accounts')->with('success', "{$user->name}'s staff account was created.".$this->sendVerification($user));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role === 'staff', 404);
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $validated = $request->validateWithBag('editAccount'.$user->id, [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => ['nullable', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        $emailChanged = $validated['email'] !== $user->email;
        $passwordChanged = ! empty($validated['password']);
        if (! $passwordChanged) unset($validated['password']);

        DB::transaction(function () use ($user, $validated, $emailChanged, $passwordChanged) {
            if ($emailChanged) DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->fill($validated);
            if ($emailChanged) $user->email_verified_at = null;
            if ($emailChanged || $passwordChanged) $user->remember_token = Str::random(60);
            $user->save();
            if ($emailChanged || $passwordChanged) $this->revokeAccess($user);
        });

        return back()->with('success', "{$user->name}'s staff account was updated.".($emailChanged ? $this->sendVerification($user) : ''));
    }

    private function sendVerification(User $user): string
    {
        try {
            $user->sendEmailVerificationNotification();
            return ' A verification email was sent.';
        } catch (Throwable $error) {
            report($error);
            return ' The verification email could not be sent; staff can request it when logging in.';
        }
    }

    private function revokeAccess(User $user): void
    {
        $user->loginVerificationCode()->delete();
        $user->trustedLoginDevices()->delete();
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)->delete();
        }
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
    }

    public function index(Request $request): View
    {
        $status = (string) $request->query('status', 'all');
        $search = trim((string) $request->query('search'));

        $staff = User::query()
            ->with(['approvedBy', 'disabledBy'])
            ->where('role', 'staff')
            ->when(in_array($status, ['pending', 'active', 'disabled'], true), fn ($query) => $query->where('account_status', $status))
            ->when($search, fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
            }))
            ->orderByRaw("CASE account_status WHEN 'pending' THEN 0 WHEN 'active' THEN 1 ELSE 2 END")
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $summary = User::query()->where('role', 'staff')->selectRaw(
            "COUNT(*) as total, SUM(account_status = 'pending') as pending, SUM(account_status = 'active') as active, SUM(account_status = 'disabled') as disabled"
        )->first();

        return view('admin.account-management', compact('staff', 'summary', 'status', 'search'));
    }

    public function approve(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role === 'staff', 404);

        DB::transaction(function () use ($user, $request) {
            $user->update([
                'account_status' => 'active',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'disabled_by' => null,
                'disabled_at' => null,
                'disabled_reason' => null,
            ]);
        });

        $emailNotice = '';
        if (! $user->hasVerifiedEmail()) {
            try {
                $user->sendEmailVerificationNotification();
                $emailNotice = ' A verification email was sent.';
            } catch (Throwable $error) {
                report($error);
                $emailNotice = ' The account is active, but its verification email could not be sent.';
            }
        }

        return back()->with('success', "{$user->name} is now approved as staff.{$emailNotice}");
    }

    public function disable(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role === 'staff', 404);
        $validated = $request->validate([
            'disabled_reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        DB::transaction(function () use ($user, $request, $validated) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $user->forceFill([
                'account_status' => 'disabled',
                'disabled_by' => $request->user()->id,
                'disabled_at' => now(),
                'disabled_reason' => trim($validated['disabled_reason']),
                'remember_token' => Str::random(60),
            ])->save();
            $this->revokeAccess($user);
        });

        return back()->with('success', "{$user->name}'s staff access was disabled.");
    }

    public function reactivate(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role === 'staff', 404);

        $user->update([
            'account_status' => 'active',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'disabled_by' => null,
            'disabled_at' => null,
            'disabled_reason' => null,
        ]);

        return back()->with('success', "{$user->name}'s staff access was restored.");
    }
}
