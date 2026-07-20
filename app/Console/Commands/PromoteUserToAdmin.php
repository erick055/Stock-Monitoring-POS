<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PromoteUserToAdmin extends Command
{
    protected $signature = 'user:promote-admin {email : The verified user email address}';

    protected $description = 'Promote an existing verified user to the administrator role';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error('No user was found with that email address.');

            return self::FAILURE;
        }

        if (! $user->hasVerifiedEmail()) {
            $this->error('The user must verify their email before becoming an administrator.');

            return self::FAILURE;
        }

        if ($user->role === 'admin') {
            $this->info('This user is already an administrator.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Promote {$user->email} to administrator?", false)) {
            $this->warn('No changes were made.');

            return self::FAILURE;
        }

        $user->forceFill(['role' => 'admin'])->save();
        $this->info("{$user->email} is now an administrator.");

        return self::SUCCESS;
    }
}
