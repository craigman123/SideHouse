<?php

namespace App\Services;

use App\Models\User;

class UserAccessService
{
    public function permissionsFor(User $user): array
    {
        $definitions = config('user_access.permissions', []);
        $preset = config("user_access.presets.{$user->role}", []);
        if ($preset === ['*']) {
            return array_keys($definitions);
        }

        $validPreset = array_values(array_intersect(array_keys($definitions), $preset));
        if ($user->role !== 'staff') {
            return $validPreset;
        }

        $stored = $user->permissions;
        return $stored === null
            ? $validPreset
            : array_values(array_intersect($validPreset, $stored));
    }

    public function allows(User $user, string $permission): bool
    {
        if (! array_key_exists($permission, config('user_access.permissions', []))) {
            return false;
        }

        return in_array($permission, $this->permissionsFor($user), true);
    }

    public function mayGrant(User $actor, string $permission): bool
    {
        return $this->allows($actor, $permission);
    }

    public function staffPermissionKeys(): array
    {
        return config('user_access.staff_permissions', []);
    }
}
