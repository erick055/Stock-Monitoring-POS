<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class SetupOwnerAccount extends Command
{
    protected $signature = 'owner:setup';

    protected $description = 'Interactively create or reset an owner account using private password prompts';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run owner:setup in an interactive terminal to enter your password privately.');

            return self::FAILURE;
        }

        $email = Str::lower(trim((string) $this->ask('Owner email')));
        $emailValidator = Validator::make(['email' => $email], ['email' => ['required', 'email:rfc', 'max:255']]);
        if ($emailValidator->fails()) {
            $this->error($emailValidator->errors()->first());

            return self::FAILURE;
        }

        $existing = User::where('email', $email)->first();
        if ($existing && $existing->role !== 'admin') {
            $this->error('This email belongs to a staff account. Choose a different owner email.');

            return self::FAILURE;
        }
        if ($existing && ! $this->confirm('Reset this owner account password and sign out its existing sessions?', false)) {
            $this->info('No changes were made.');

            return self::SUCCESS;
        }

        $name = trim((string) $this->ask('Owner name', $existing?->name ?? 'Owner'));
        $this->line('Use at least 12 characters with uppercase, lowercase, a number, and a symbol.');
        $password = $this->secret('Owner password');
        $confirmation = $this->secret('Confirm owner password');
        $validator = Validator::make([
            'name' => $name, 'password' => $password, 'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($email, $name, $password) {
            $owner = User::where('email', $email)->lockForUpdate()->first() ?? new User;
            if ($owner->exists && $owner->role !== 'admin') {
                throw new \RuntimeException('This email belongs to a staff account.');
            }
            $owner->forceFill([
                'name' => $name, 'email' => $email, 'password' => $password,
                'role' => 'admin', 'account_status' => 'active', 'email_verified_at' => now(),
                'disabled_at' => null, 'disabled_by' => null, 'disabled_reason' => null,
                'remember_token' => Str::random(60),
            ])->save();
            $owner->loginVerificationCode()->delete();
            $owner->trustedLoginDevices()->delete();
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                    ->where('user_id', $owner->id)->delete();
            }
        });

        $this->info('Owner account is ready. Log in using the email and password you just entered.');

        return self::SUCCESS;
    }
}
