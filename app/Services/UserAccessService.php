<?php

namespace App\Services;

use App\Models\User;
use App\Support\GuardSessionState;
use Illuminate\Http\Request;

class UserAccessService
{
    private const VIEW_AS_ROLES = ['admin', 'owner', 'staff', 'customer'];

    public function realRole(User $user): string
    {
        return (string) (User::query()->whereKey($user->user_id)->value('role') ?? 'user');
    }

    public function effectiveRole(User $user, ?Request $request = null): string
    {
        $request ??= request();
        $realRole = $this->realRole($user);
        $isCurrentActor = (int) $request->user()?->user_id === (int) $user->user_id;

        return $this->roleForView($realRole, $isCurrentActor, $request);
    }

    private function roleForView(string $realRole, bool $isCurrentActor, Request $request): string
    {
        if (! $isCurrentActor) return $realRole;

        $viewAsKey = GuardSessionState::key('view_as');
        $viewAs = $request->session()->get($viewAsKey);
        if ($realRole !== 'admin') {
            if ($viewAs !== null) $request->session()->forget($viewAsKey);
            return $realRole;
        }

        if ($viewAs === null) return 'admin';
        if (! in_array($viewAs, self::VIEW_AS_ROLES, true)) {
            $request->session()->forget($viewAsKey);
            return 'admin';
        }

        return $viewAs === 'customer' ? 'user' : $viewAs;
    }

    public function effectiveUser(User $user, ?Request $request = null): User
    {
        $request ??= request();
        $isCurrentActor = (int) $request->user()?->user_id === (int) $user->user_id;
        $effective = $isCurrentActor
            ? (User::query()->find($user->user_id) ?? clone $user)
            : clone $user;

        if ($isCurrentActor) {
            $realRole = $effective->role;
            $effectiveRole = $this->roleForView($realRole, true, $request);
            $effective->setAttribute('role', $effectiveRole);
            if ($realRole === 'admin' && $effectiveRole === 'staff') {
                // An admin viewing Staff receives only the default staff preset.
                $effective->setAttribute('permissions', null);
            }
        }

        return $effective;
    }

    public function isRealAdmin(User $user, ?Request $request = null): bool
    {
        $this->effectiveRole($user, $request); // also clears stale view_as for non-admin accounts
        return $this->realRole($user) === 'admin';
    }

    public function canManage(User $actor, ?User $target, string $action, ?string $role = null): bool
    {
        $actor = $this->effectiveUser($actor);
        if (! in_array($actor->role, ['admin', 'owner'], true)) return false;
        if ($action === 'grant_role') {
            return in_array($role, $actor->role === 'admin' ? ['staff', 'owner', 'admin'] : ['staff'], true);
        }
        if ($target === null) return in_array($action, ['edit_access', 'remove_access'], true)
            || ($action === 'edit_details' && $actor->role === 'admin');
        if ((int) $actor->user_id === (int) $target->user_id) return false;
        if ($action === 'edit_details') return $actor->role === 'admin';
        if (! in_array($action, ['edit_access', 'remove_access'], true)) return false;
        if (! in_array($target->role, ['owner', 'admin', 'staff'], true)) return false;
        if ($actor->role === 'owner') return $target->role === 'staff';
        return in_array($target->role, ['owner', 'admin', 'staff', 'user'], true);
    }

    public function grantableRoles(User $actor): array
    {
        return array_values(array_filter(['staff', 'owner', 'admin'], fn (string $role) => $this->canManage($actor, null, 'grant_role', $role)));
    }

    public function managementReason(User $actor, ?User $target, string $action, ?string $role = null): ?string
    {
        $actor = $this->effectiveUser($actor);
        if ($target && (int) $actor->user_id === (int) $target->user_id) return 'You can\'t change your own access.';
        if ($action === 'edit_details' && $actor->role !== 'admin') return 'Only an admin can edit account details.';
        if ($action === 'grant_role' && ! $this->canManage($actor, $target, $action, $role)) return 'Only an admin can grant this role.';
        if ($actor->role === 'owner' && $target && $target->role !== 'staff') return 'Owners can manage staff accounts only.';
        return 'You don\'t have permission to do this.';
    }

    public function permissionsFor(User $user): array
    {
        return $this->permissionsForEffective($this->effectiveUser($user));
    }

    private function permissionsForEffective(User $user): array
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
            : array_values(array_intersect($this->staffPermissionKeys(), $stored));
    }

    public function allows(User $user, string $permission): bool
    {
        return $this->allowsEffective($this->effectiveUser($user), $permission);
    }

    private function allowsEffective(User $user, string $permission): bool
    {
        if (! array_key_exists($permission, config('user_access.permissions', []))) {
            return false;
        }

        if ($permission === 'reports.system' && $user->role !== 'admin') {
            return false;
        }

        if ($permission === 'user_access.manage') return $this->canManage($user, null, 'edit_access');
        if ($permission === 'user_details.edit') return $this->canManage($user, null, 'edit_details');

        // Fixed staff restrictions must override legacy stored permission grants.
        if ($user->role === 'staff' && in_array($permission, ['bookings.create', 'calendar_events.manage'], true)) {
            return false;
        }

        return in_array($permission, $this->permissionsForEffective($user), true);
    }

    public function allowsAny(User $user, array $permissions): bool
    {
        $user = $this->effectiveUser($user);
        foreach ($permissions as $permission) {
            if ($this->allowsEffective($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function allowsCalendarEventType(User $user, string $type): bool
    {
        return $type === 'open_play'
            ? $this->allows($user, 'calendar_events.open_play')
            : $this->allows($user, 'calendar_events.manage');
    }

    public function mayGrant(User $actor, string $permission): bool
    {
        return $this->allows($actor, $permission);
    }

    /** @return array<string, bool> */
    public function flagsFor(User $user): array
    {
        $user = $this->effectiveUser($user);
        $flags = [];
        foreach (array_keys(config('user_access.permissions', [])) as $permission) {
            $flags[$permission] = $this->allowsEffective($user, $permission);
        }

        return $flags;
    }

    public function staffPermissionKeys(): array
    {
        return config('user_access.staff_permissions', []);
    }
}
