<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MakeUserOwner extends Command
{
    protected $signature = 'users:make-owner {email : Email address of an existing account}';

    protected $description = 'Promote an existing account to owner after confirmation';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $candidate = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $candidate) {
            $this->error('No existing account matches that email.');
            return self::FAILURE;
        }
        if ($candidate->role === 'owner') {
            $this->error('That account is already an owner.');
            return self::FAILURE;
        }

        $this->line("Account: {$candidate->name} <{$candidate->email}> ({$candidate->role})");
        if (! $this->confirm('Promote this account to owner?')) {
            $this->comment('No changes made.');
            return self::SUCCESS;
        }

        $owner = DB::transaction(function () use ($candidate) {
            $user = User::query()->whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
            if ($user->role === 'owner') {
                return null;
            }

            $oldRole = $user->role;
            $oldPermissions = $user->permissions ?? [];
            $user->forceFill([
                'role' => 'owner',
                'permissions' => null,
                'promoted_by' => null,
                'promoted_at' => now(),
                'access_version' => (int) $user->access_version + 1,
                'remember_token' => Str::random(60),
            ])->save();

            ActivityLogger::log(
                'user_access.owner_bootstrapped',
                "Console command promoted {$user->name} to owner.",
                actor: (object) ['user_id' => null, 'name' => 'Console command'],
                subject: $user,
                properties: [
                    'old_role' => $oldRole,
                    'new_role' => 'owner',
                    'old_permissions' => $oldPermissions,
                    'new_permissions' => [],
                    'source' => 'users:make-owner',
                ],
            );

            return $user;
        });

        if (! $owner) {
            $this->error('The account role changed before the command completed. No change was made.');
            return self::FAILURE;
        }

        $this->info("{$owner->email} is now an owner.");
        return self::SUCCESS;
    }
}
