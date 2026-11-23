<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('users')->where('role', 'staff')->select(['user_id', 'permissions'])->orderBy('user_id')->get()
                ->each(function (object $user): void {
                    if ($user->permissions === null) {
                        return;
                    }

                    $permissions = is_array($user->permissions)
                        ? $user->permissions
                        : json_decode((string) $user->permissions, true);
                    if (! is_array($permissions)) {
                        return;
                    }

                    $normalized = array_values(array_filter(
                        $permissions,
                        fn ($permission) => ! in_array($permission, ['bookings.create', 'calendar_events.manage'], true),
                    ));
                    if (in_array('calendar_events.manage', $permissions, true)) {
                        $normalized[] = 'calendar_events.open_play';
                    }
                    $normalized = array_values(array_unique($normalized));

                    if ($normalized !== $permissions) {
                        DB::table('users')->where('user_id', $user->user_id)->update([
                            'permissions' => json_encode($normalized, JSON_THROW_ON_ERROR),
                        ]);
                    }
                });
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::table('users')->where('role', 'staff')->select(['user_id', 'permissions'])->orderBy('user_id')->get()
                ->each(function (object $user): void {
                    if ($user->permissions === null) {
                        return;
                    }

                    $permissions = is_array($user->permissions)
                        ? $user->permissions
                        : json_decode((string) $user->permissions, true);
                    if (! is_array($permissions)) {
                        return;
                    }

                    $normalized = array_values(array_filter($permissions, fn ($permission) => $permission !== 'calendar_events.open_play'));
                    if (in_array('calendar_events.open_play', $permissions, true)) {
                        $normalized[] = 'calendar_events.manage';
                    }
                    $normalized = array_values(array_unique($normalized));

                    if ($normalized !== $permissions) {
                        DB::table('users')->where('user_id', $user->user_id)->update([
                            'permissions' => json_encode($normalized, JSON_THROW_ON_ERROR),
                        ]);
                    }
                });
        });
    }
};
