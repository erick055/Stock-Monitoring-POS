<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class AccountManagementController extends Controller
{
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

        $user->update([
            'account_status' => 'disabled',
            'disabled_by' => $request->user()->id,
            'disabled_at' => now(),
            'disabled_reason' => trim($validated['disabled_reason']),
        ]);
        $user->loginVerificationCode()->delete();
        $user->trustedLoginDevices()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();

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
