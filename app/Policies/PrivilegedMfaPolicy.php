<?php

namespace App\Policies;

use App\Models\MfaResetRequest;
use App\Models\User;
use App\Support\GuardSessionState;

class PrivilegedMfaPolicy
{
    public function disablePrivilegedMfa(User $actor, User $target): bool
    {
        return $this->canManageTarget($actor, $target) && $this->realTargetRole($target) !== 'owner';
    }

    public function forcePrivilegedMfaReenrollment(User $actor, User $target): bool
    {
        return $this->canManageTarget($actor, $target) && $this->realTargetRole($target) !== 'owner';
    }

    public function enablePrivilegedMfa(User $actor, User $target): bool
    {
        return $this->canManageTarget($actor, $target);
    }

    public function setPrivilegedMfaMethods(User $actor, User $target): bool
    {
        return $this->canManageTarget($actor, $target);
    }

    public function requestOwnerMfaChange(User $actor, User $target): bool
    {
        return $this->canManageTarget($actor, $target) && $this->realTargetRole($target) === 'owner';
    }

    public function approveOwnerMfaChange(User $actor, User $target, MfaResetRequest $mfaResetRequest): bool
    {
        return $this->requestOwnerMfaChange($actor, $target)
            && (int) $mfaResetRequest->target_user_id === (int) $target->user_id
            && (int) $mfaResetRequest->requested_by_user_id !== (int) $actor->user_id;
    }

    public function regenerateOwnRecoveryCodes(User $actor, User $target): bool
    {
        $role = $this->realTargetRole($target);

        return (int) $actor->user_id === (int) $target->user_id
            && $this->realTargetRole($actor) === $role
            && in_array($role, ['admin', 'owner', 'staff'], true);
    }

    private function canManageTarget(User $actor, User $target): bool
    {
        $viewAs = request()->hasSession()
            ? request()->session()->get(GuardSessionState::key('view_as'))
            : null;

        return $this->realActorRole($actor) === 'admin'
            && $this->realTargetRole($target) !== 'user'
            && in_array($this->realTargetRole($target), ['admin', 'owner', 'staff'], true)
            && (int) $actor->user_id !== (int) $target->user_id
            && $viewAs === null;
    }

    private function realActorRole(User $actor): ?string
    {
        return User::query()->whereKey($actor->user_id)->value('role');
    }

    private function realTargetRole(User $target): ?string
    {
        return User::query()->whereKey($target->user_id)->value('role');
    }
}
