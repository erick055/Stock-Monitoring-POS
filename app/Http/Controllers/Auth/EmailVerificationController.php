<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class EmailVerificationController extends Controller
{
    public function verify(Request $request): View|RedirectResponse|Response
    {
        // The expiring signed URL proves access to the mailbox; it never logs a browser in.
        if (! $request->hasValidSignature() || ! $request->query('expires')) {
            return response()->view('auth.verification-result', ['verified' => false], 403);
        }
        $newlyVerified = false;
        $user = DB::transaction(function () use ($request, &$newlyVerified) {
            $user = User::query()->lockForUpdate()->find($request->route('id'));
            if (! $user || $user->account_status !== 'active'
                || ! in_array($user->role, ['admin', 'staff'], true)
                || ! hash_equals(sha1($user->getEmailForVerification()), (string) $request->route('hash'))) {
                return null;
            }
            if (! $user->hasVerifiedEmail()) {
                $newlyVerified = $user->markEmailAsVerified();
            }

            return $user;
        });
        if (! $user) {
            return response()->view('auth.verification-result', ['verified' => false], 403);
        }
        if ($newlyVerified) {
            event(new Verified($user));
        }
        if ($request->user()?->id === $user->id) {
            return redirect()->to($this->destination($user));
        }

        return view('auth.verification-result', ['verified' => true]);
    }

    public function notice(Request $request): View|RedirectResponse
    {
        $user = $request->user()->fresh();
        if ($user->hasVerifiedEmail()) {
            return redirect()->to($this->destination($user));
        }

        return view('auth.verify-email');
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->user()->fresh();

        return response()->json([
            'verified' => $user->hasVerifiedEmail(),
            'redirect_url' => $user->hasVerifiedEmail() ? $this->destination($user) : null,
        ]);
    }

    private function destination(User $user): string
    {
        return $user->role === 'staff' && $user->must_change_password
            ? route('staff.password.edit') : route($user->role.'.dashboard');
    }
}
