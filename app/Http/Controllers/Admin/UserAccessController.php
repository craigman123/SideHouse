<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\AdminChangedAccountEmailNotice;
use App\Mail\PrivilegedMfaNotice;
use App\Models\MfaResetRequest;
use App\Models\User;
use App\Services\PrivilegedMfaService;
use App\Services\UserAccessService;
use App\Support\ActivityLogger;
use App\Support\BrevoMailer;
use App\Support\GuardSessionState;
use App\Support\LikePattern;
use App\Support\Mask;
use App\Support\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserAccessController extends Controller
{
    private const MANAGED_ROLES = ['owner', 'admin', 'staff'];

    public function page(Request $request, UserAccessService $access): View
    {
        $actor = $request->user();
        $effectiveActor = $access->effectiveUser($actor);
        $permissionDefinitions = config('user_access.permissions', []);
        $canManageMfa = $access->isRealAdmin($actor, $request)
            && $request->session()->get(GuardSessionState::key('view_as')) === null;

        return view('admin.user-access.index', [
            'actorRole' => $effectiveActor->role,
            'actorId' => (int) $actor->user_id,
            'canManageMfa' => $canManageMfa,
            'mfaDisableUrlTemplate' => $canManageMfa
                ? role_route('admin.users.mfa.disable', ['user' => '__USER__'])
                : '',
            'mfaEnableUrlTemplate' => $canManageMfa
                ? role_route('admin.users.mfa.enable', ['user' => '__USER__'])
                : '',
            'mfaForceReenrollmentUrlTemplate' => $canManageMfa
                ? role_route('admin.users.mfa.force-reenrollment', ['user' => '__USER__'])
                : '',
            'mfaAllowedMethodsUrlTemplate' => $canManageMfa
                ? role_route('admin.users.mfa.allowed-methods', ['user' => '__USER__'])
                : '',
            'mfaOwnerRequestUrlTemplate' => $canManageMfa
                ? role_route('admin.users.mfa.owner-request', ['user' => '__USER__'])
                : '',
            'mfaOwnerApproveUrlTemplate' => $canManageMfa
                ? role_route('admin.users.mfa.owner-approve', ['user' => '__USER__', 'requestId' => '__REQUEST__'])
                : '',
            'accountLockUrlTemplate' => role_route('admin.users.lock', ['user' => '__USER__']),
            'accountPromoteAgainUrlTemplate' => role_route('admin.users.promote-again', ['user' => '__USER__']),
            'accountLockApproveUrlTemplate' => $canManageMfa
                ? role_route('admin.users.lock-approve', ['user' => '__USER__', 'requestId' => '__REQUEST__'])
                : '',
            'permissionDefinitions' => collect(config('user_access.staff_permissions', []))
                ->mapWithKeys(fn (string $key) => [$key => $permissionDefinitions[$key] ?? null])
                ->all(),
            'staffPreset' => config('user_access.presets.staff', []),
            'ownerExists' => User::query()->where('role', 'owner')->exists(),
            'grantableRoles' => $access->grantableRoles($effectiveActor),
        ]);
    }

    public function index(Request $request, UserAccessService $access): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(['all', ...self::MANAGED_ROLES, 'user'])],
        ]);

        $actor = User::query()->findOrFail($request->user()->user_id);
        $roles = $access->effectiveRole($actor, $request) === 'admin' ? [...self::MANAGED_ROLES, 'user'] : self::MANAGED_ROLES;
        $query = User::query()->whereIn('role', $roles)->with('promotedBy');
        if (($validated['role'] ?? 'all') !== 'all') {
            $query->where('role', $validated['role']);
        }
        if (trim((string) ($validated['q'] ?? '')) !== '') {
            $this->applySearch($query, trim($validated['q']));
        }

        $users = $query->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END")
            ->orderBy('name')->get();
        $pendingRequests = $users->isEmpty()
            ? collect()
            : MfaResetRequest::query()
                ->whereIn('target_user_id', $users->pluck('user_id'))
                ->whereIn('action', ['disable', 'force_reenroll'])
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->with('requester')
                ->get()
                ->keyBy('target_user_id');
        $pendingAccountRequests = $users->isEmpty()
            ? collect()
            : MfaResetRequest::query()
                ->whereIn('target_user_id', $users->pluck('user_id'))
                ->whereIn('action', ['account_lock', 'account_promote'])
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->with('requester')
                ->get()
                ->keyBy('target_user_id');

        $serialized = $users->map(fn (User $user) => $this->serializeUser(
            $user,
            (int) $actor->user_id,
            $access,
            $actor,
            $pendingRequests->get($user->user_id),
            $request,
            $pendingAccountRequests->get($user->user_id),
        ));

        return response()->json(['users' => $serialized]);
    }

    public function candidates(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $term = trim((string) ($validated['q'] ?? ''));
        if (mb_strlen($term) < 3) {
            return response()->json(['candidates' => []]);
        }

        $candidates = User::query()
            ->where('role', 'user')
            ->whereRaw("LOWER(email) LIKE ? ESCAPE '!'", [LikePattern::contains($term)])
            ->orderBy('email')
            ->limit(8)
            ->get(['user_id', 'name', 'email'])
            ->map(fn (User $user) => [
                'id' => (int) $user->user_id,
                'email' => $user->email,
                'name' => $user->name,
            ]);

        return response()->json(['candidates' => $candidates]);
    }

    public function store(Request $request, UserAccessService $access): JsonResponse
    {
        $actor = $this->freshActor($request, $access);
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'role' => ['required', Rule::in(['staff', 'admin', 'owner'])],
            'permissions' => ['sometimes', 'array', 'max:'.count(config('user_access.staff_permissions', []))],
            'permissions.*' => ['string', 'distinct', Rule::in(array_keys(config('user_access.permissions', [])))],
            'password' => ['required', 'string'],
        ]);
        $this->assertPassword($actor, $validated['password']);
        $this->authorizeAction($actor, null, 'grant_role', $validated['role'], $access);
        $this->assertStaffPermissions($actor, $validated['role'], $validated['permissions'] ?? [], $access);

        $user = DB::transaction(function () use ($validated, $actor, $access) {
            $user = User::query()->whereKey($validated['user_id'])->lockForUpdate()->firstOrFail();
            abort_unless($user->role === 'user', 409, 'Only registered customer accounts can be promoted.');
            abort_if((int) $user->user_id === (int) $actor->user_id, 403, 'You cannot change your own access.');

            $permissions = $validated['role'] === 'staff'
                ? $this->normalizedStaffPermissions($validated['permissions'] ?? $access->staffPermissionKeys())
                : null;
            $user->forceFill([
                'role' => $validated['role'],
                'permissions' => $permissions,
                'promoted_by' => $actor->user_id,
                'promoted_at' => now(),
                'access_version' => (int) $user->access_version + 1,
                'remember_token' => Str::random(60),
            ])->save();

            ActivityLogger::log(
                'user_access.promoted',
                "{$actor->name} promoted {$user->name} to {$user->role}.",
                actor: $actor,
                subject: $user,
                properties: [
                    'old_role' => 'user',
                    'new_role' => $user->role,
                    'old_permissions' => [],
                    'new_permissions' => $permissions ?? [],
                ],
            );

            return $user->fresh('promotedBy');
        });

        return response()->json(['message' => 'Access updated.', 'user' => $this->serializeUser($user, (int) $actor->user_id, $access, $actor)], 201);
    }

    public function update(Request $request, User $user, UserAccessService $access): JsonResponse
    {
        $actor = $this->freshActor($request, $access);
        $validated = $request->validate([
            'role' => ['required', Rule::in(self::MANAGED_ROLES)],
            'permissions' => ['sometimes', 'array', 'max:'.count(config('user_access.staff_permissions', []))],
            'permissions.*' => ['string', 'distinct', Rule::in(array_keys(config('user_access.permissions', [])))],
            'password' => ['required', 'string'],
        ]);
        $this->assertPassword($actor, $validated['password']);
        $this->authorizeAction($actor, $user, 'edit_access', null, $access);
        $this->authorizeAction($actor, $user, 'grant_role', $validated['role'], $access);
        $this->assertStaffPermissions($actor, $validated['role'], $validated['permissions'] ?? [], $access);

        $updated = DB::transaction(function () use ($actor, $user, $validated, $access) {
            $target = User::query()->whereKey($user->user_id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($target->role, self::MANAGED_ROLES, true), 404);
            $this->authorizeAction($actor, $target, 'edit_access', null, $access);
            $this->authorizeAction($actor, $target, 'grant_role', $nextRole = $validated['role'], $access);
            if ($target->role === 'admin' && $nextRole !== 'admin') $this->assertNotLastAdmin();

            $oldRole = $target->role;
            $oldPermissions = $target->permissions ?? [];
            if ($nextRole === 'staff') {
                $nextPermissions = array_key_exists('permissions', $validated)
                    ? $this->normalizedStaffPermissions($validated['permissions'])
                    : ($oldRole === 'staff'
                        ? $this->normalizedStaffPermissions($target->permissions ?? $access->staffPermissionKeys())
                        : $access->staffPermissionKeys());
            } else {
                $nextPermissions = null;
            }

            $changed = $oldRole !== $nextRole || $oldPermissions !== ($nextPermissions ?? []);
            $target->forceFill([
                'role' => $nextRole,
                'permissions' => $nextPermissions,
                ...($changed ? [
                    'access_version' => (int) $target->access_version + 1,
                    'remember_token' => Str::random(60),
                ] : []),
            ])->save();

            if ($changed) {
                ActivityLogger::log(
                    'user_access.updated',
                    "{$actor->name} changed {$target->name}'s user access.",
                    actor: $actor,
                    subject: $target,
                    properties: [
                        'old_role' => $oldRole,
                        'new_role' => $nextRole,
                        'old_permissions' => $oldPermissions,
                        'new_permissions' => $nextPermissions ?? [],
                    ],
                );
            }

            return $target->fresh('promotedBy');
        });

        return response()->json(['message' => 'Access updated.', 'user' => $this->serializeUser($updated, (int) $actor->user_id, $access, $actor)]);
    }

    public function removeAccess(Request $request, User $user, UserAccessService $access): JsonResponse
    {
        $actor = $this->freshActor($request, $access);
        $validated = $request->validate(['password' => ['required', 'string']]);
        $this->assertPassword($actor, $validated['password']);
        $this->authorizeAction($actor, $user, 'remove_access', null, $access);

        $removed = DB::transaction(function () use ($actor, $user, $access) {
            $target = User::query()->whereKey($user->user_id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($target->role, self::MANAGED_ROLES, true), 404);
            $this->authorizeAction($actor, $target, 'remove_access', null, $access);
            if ($target->role === 'admin') $this->assertNotLastAdmin();

            $oldRole = $target->role;
            $oldPermissions = $target->permissions ?? [];
            $target->forceFill([
                'role' => 'user',
                'permissions' => null,
                'promoted_by' => null,
                'promoted_at' => null,
                'access_version' => (int) $target->access_version + 1,
                'remember_token' => Str::random(60),
            ])->save();

            ActivityLogger::log(
                'user_access.removed',
                "{$actor->name} removed elevated access from {$target->name}.",
                actor: $actor,
                subject: $target,
                properties: [
                    'old_role' => $oldRole,
                    'new_role' => 'user',
                    'old_permissions' => $oldPermissions,
                    'new_permissions' => [],
                ],
            );

            return $target;
        });

        return response()->json([
            'message' => 'Access removed. The person remains a customer account.',
            'user_id' => (int) $removed->user_id,
        ]);
    }

    public function lockAccount(
        Request $request,
        User $user,
        UserAccessService $access,
        PrivilegedMfaService $mfa,
    ): JsonResponse {
        $actor = $this->freshLockActor($request, $access);
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $reason = trim((string) ($validated['reason'] ?? '')) ?: null;

        $result = DB::transaction(function () use ($request, $actor, $user, $access, $mfa, $validated, $reason): array {
            [$lockedActor, $target] = $this->lockActorAndTarget($actor->user_id, $user->user_id);
            $this->authorizeLockTarget($request, $lockedActor, $target, $access);
            abort_if($target->locked_at !== null, 409, 'This account is already locked.');

            if ($target->role === 'admin') {
                $this->assertNotLastAdmin('locked', true);
            } elseif ($target->role === 'owner') {
                $this->assertNotLastOwner(true);
            }

            $step = $mfa->verifyFreshTotp($lockedActor, $validated['code']);
            $lockedActor->forceFill(['mfa_last_admin_action_step' => $step])->save();

            if ($target->role === 'owner') {
                $this->assertNoPendingAccountRequest($target);
                $ownerRequest = MfaResetRequest::query()->create([
                    'target_user_id' => $target->user_id,
                    'requested_by_user_id' => $lockedActor->user_id,
                    'action' => 'account_lock',
                    'reason' => $reason,
                    'status' => 'pending',
                    'expires_at' => now()->addDay(),
                ]);
                $recipientIds = $this->notifyLockObservers(
                    $lockedActor,
                    $target,
                    'account_lock_requested',
                    'Account lock request pending',
                    'A second admin must approve locking this owner account within 24 hours.',
                    'account_lock',
                    'pending',
                    ['request_id' => $ownerRequest->id],
                );
                $this->auditAccountLock($lockedActor, $target, 'account_lock', 'pending', [
                    'request_id' => $ownerRequest->id,
                    'reason' => $reason,
                ]);

                return [
                    'pending' => true,
                    'target' => $target,
                    'request' => $ownerRequest,
                    'recipient_ids' => $recipientIds,
                ];
            }

            $this->applyAccountLock($lockedActor, $target, $reason);
            $recipientIds = $this->notifyLockObservers(
                $lockedActor,
                $target,
                'account_locked',
                'Account locked',
                'An administrator locked an account. Contact an administrator if this was unexpected.',
                'account_lock',
                'success',
            );
            $this->auditAccountLock($lockedActor, $target, 'account_lock', 'success', ['reason' => $reason]);

            return ['pending' => false, 'target' => $target->fresh(), 'recipient_ids' => $recipientIds];
        });

        $this->sendAccountLockEmails(
            $result['recipient_ids'],
            $result['pending'] ? 'Owner account lock request pending' : 'Account locked',
            $result['pending']
                ? 'A second admin must approve locking this owner account within 24 hours.'
                : 'An administrator locked an account. Contact an administrator if this was unexpected.',
        );

        if ($result['pending']) {
            return response()->json([
                'message' => 'Lock request created. A different admin must approve it within 24 hours.',
                'request' => [
                    'id' => $result['request']->id,
                    'action' => $result['request']->action,
                    'expires_at' => $result['request']->expires_at->toIso8601String(),
                ],
            ], 201);
        }

        return response()->json(['message' => 'Account locked.']);
    }

    public function promoteAgain(
        Request $request,
        User $user,
        UserAccessService $access,
        PrivilegedMfaService $mfa,
    ): JsonResponse {
        $actor = $this->freshLockActor($request, $access);
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
        ]);

        $result = DB::transaction(function () use ($request, $actor, $user, $access, $mfa, $validated): array {
            [$lockedActor, $target] = $this->lockActorAndTarget($actor->user_id, $user->user_id);
            $this->authorizeLockTarget($request, $lockedActor, $target, $access);
            abort_unless($target->locked_at !== null, 409, 'This account is not locked.');

            $step = $mfa->verifyFreshTotp($lockedActor, $validated['code']);
            $lockedActor->forceFill(['mfa_last_admin_action_step' => $step])->save();

            if ($target->role === 'owner') {
                $this->assertNoPendingAccountRequest($target);
                $ownerRequest = MfaResetRequest::query()->create([
                    'target_user_id' => $target->user_id,
                    'requested_by_user_id' => $lockedActor->user_id,
                    'action' => 'account_promote',
                    'status' => 'pending',
                    'expires_at' => now()->addDay(),
                ]);
                $recipientIds = $this->notifyLockObservers(
                    $lockedActor,
                    $target,
                    'account_promote_requested',
                    'Promotion request pending',
                    'A second admin must approve restoring this owner account within 24 hours.',
                    'account_promote',
                    'pending',
                    ['request_id' => $ownerRequest->id],
                );
                $this->auditAccountLock($lockedActor, $target, 'account_promote', 'pending', [
                    'request_id' => $ownerRequest->id,
                ]);

                return [
                    'pending' => true,
                    'target' => $target,
                    'request' => $ownerRequest,
                    'recipient_ids' => $recipientIds,
                ];
            }

            $this->applyAccountPromotion($target);
            $recipientIds = $this->notifyLockObservers(
                $lockedActor,
                $target,
                'account_promoted_again',
                'Account promoted again',
                'An administrator restored access to this account. Its normal sign-in and MFA requirements apply.',
                'account_promote',
                'success',
            );
            $this->auditAccountLock($lockedActor, $target, 'account_promote', 'success');

            return ['pending' => false, 'target' => $target->fresh(), 'recipient_ids' => $recipientIds];
        });

        $this->sendAccountLockEmails(
            $result['recipient_ids'],
            $result['pending'] ? 'Owner account promotion request pending' : 'Account promoted again',
            $result['pending']
                ? 'A second admin must approve restoring this owner account within 24 hours.'
                : 'An administrator restored access to this account. Its normal sign-in and MFA requirements apply.',
        );

        if ($result['pending']) {
            return response()->json([
                'message' => 'Promotion request created. A different admin must approve it within 24 hours.',
                'request' => [
                    'id' => $result['request']->id,
                    'action' => $result['request']->action,
                    'expires_at' => $result['request']->expires_at->toIso8601String(),
                ],
            ], 201);
        }

        return response()->json(['message' => 'Account promoted again. Normal MFA requirements apply.']);
    }

    public function approveAccountLockChange(
        Request $request,
        User $user,
        int $requestId,
        UserAccessService $access,
        PrivilegedMfaService $mfa,
    ): JsonResponse {
        $actor = $this->freshLockActor($request, $access);
        abort_unless($actor->role === 'admin', 403);
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
        ]);

        $result = DB::transaction(function () use ($request, $actor, $user, $requestId, $access, $mfa, $validated): array {
            [$lockedActor, $target] = $this->lockActorAndTarget($actor->user_id, $user->user_id);
            $ownerRequest = MfaResetRequest::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();
            $this->authorizeLockTarget($request, $lockedActor, $target, $access);
            abort_unless(
                $lockedActor->role === 'admin'
                    && $target->role === 'owner'
                    && (int) $ownerRequest->target_user_id === (int) $target->user_id
                    && in_array($ownerRequest->action, ['account_lock', 'account_promote'], true)
                    && (int) $ownerRequest->requested_by_user_id !== (int) $lockedActor->user_id,
                403,
            );
            abort_unless($ownerRequest->status === 'pending', 409, 'This account request is no longer pending.');

            if ($ownerRequest->expires_at->lessThanOrEqualTo(now())) {
                $ownerRequest->forceFill(['status' => 'expired'])->save();
                $this->auditAccountLock(
                    $lockedActor,
                    $target,
                    $ownerRequest->action,
                    'expired',
                    ['request_id' => $ownerRequest->id],
                );

                return ['expired' => true, 'recipient_ids' => []];
            }

            $isLock = $ownerRequest->action === 'account_lock';
            abort_unless(
                $isLock ? $target->locked_at === null : $target->locked_at !== null,
                409,
                'The account state changed after this request was created.',
            );
            if ($isLock) {
                $this->assertNotLastOwner(true);
            }

            $step = $mfa->verifyFreshTotp($lockedActor, $validated['code']);
            $lockedActor->forceFill(['mfa_last_admin_action_step' => $step])->save();

            if ($isLock) {
                $this->applyAccountLock($lockedActor, $target, $ownerRequest->reason);
            } else {
                $this->applyAccountPromotion($target);
            }
            $ownerRequest->forceFill([
                'status' => 'approved',
                'approved_by_user_id' => $lockedActor->user_id,
                'approved_at' => now(),
                'executed_at' => now(),
            ])->save();

            $action = $isLock ? 'account_lock' : 'account_promote';
            $title = $isLock ? 'Owner account locked' : 'Owner account promoted again';
            $body = $isLock
                ? 'Two admins approved locking this owner account.'
                : 'Two admins approved restoring access to this owner account.';
            $recipientIds = $this->notifyLockObservers(
                $lockedActor,
                $target,
                $isLock ? 'account_locked' : 'account_promoted_again',
                $title,
                $body,
                $action,
                'success',
                ['request_id' => $ownerRequest->id],
                (int) $ownerRequest->requested_by_user_id,
            );
            $this->auditAccountLock($lockedActor, $target, $action, 'success', [
                'request_id' => $ownerRequest->id,
                'requested_by_user_id' => (int) $ownerRequest->requested_by_user_id,
                'reason' => $isLock ? $ownerRequest->reason : null,
            ]);

            return [
                'expired' => false,
                'action' => $action,
                'recipient_ids' => $recipientIds,
                'title' => $title,
                'body' => $body,
            ];
        });

        if ($result['expired']) {
            return response()->json(['message' => 'This account request expired and cannot be approved.'], 410);
        }

        $this->sendAccountLockEmails($result['recipient_ids'], $result['title'], $result['body']);

        return response()->json([
            'message' => $result['action'] === 'account_lock'
                ? 'Owner account locked.'
                : 'Owner account promoted again. Normal MFA requirements apply.',
        ]);
    }

    public function details(Request $request, User $user, UserAccessService $access): JsonResponse
    {
        $actor = $this->freshActor($request, $access);
        $this->authorizeAction($actor, $user, 'edit_details', null, $access);
        $target = User::query()->findOrFail($user->user_id);
        $this->authorizeAction($actor, $target, 'edit_details', null, $access);

        ActivityLogger::log(
            'user_details.viewed',
            "Admin account #{$actor->user_id} viewed account details for account #{$target->user_id}.",
            actor: $actor,
            subject: $target,
            properties: ['fields' => ['name', 'username', 'email', 'phone_number']],
        );

        return response()->json(['details' => $this->detailsSnapshot($target)]);
    }

    public function updateDetails(Request $request, User $user, UserAccessService $access): JsonResponse
    {
        $actor = $this->freshActor($request, $access);
        $this->authorizeAction($actor, $user, 'edit_details', null, $access);
        $target = User::query()->findOrFail($user->user_id);
        $this->authorizeAction($actor, $target, 'edit_details', null, $access);

        $normalizedEmail = mb_strtolower(trim((string) $request->input('email')));
        $request->merge([
            'name' => trim((string) $request->input('name')),
            'username' => trim((string) $request->input('username')),
            'email' => $normalizedEmail,
            'phone_number' => trim((string) $request->input('phone_number')) ?: null,
        ]);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required', 'string', 'max:255',
                Rule::unique('users', 'username')->ignore($target->user_id, 'user_id'),
            ],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/'],
            'expected_updated_at' => ['required', 'date'],
            'password' => ['required', 'string'],
        ]);
        $validator->after(function ($validator) use ($target, $normalizedEmail) {
            $emailTaken = User::query()
                ->where('user_id', '<>', $target->user_id)
                ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
                ->exists();

            if ($emailTaken) {
                $validator->errors()->add('email', 'This email address is already in use.');
            }
        });
        $validated = $validator->validate();
        $this->assertPassword($actor, $validated['password']);

        $result = DB::transaction(function () use ($actor, $target, $validated, $normalizedEmail, $access) {
            $locked = User::query()->whereKey($target->user_id)->lockForUpdate()->firstOrFail();
            $this->authorizeAction($actor, $locked, 'edit_details', null, $access);

            $expectedUpdatedAt = Carbon::parse($validated['expected_updated_at']);
            if (! $locked->updated_at || ! $expectedUpdatedAt->equalTo($locked->updated_at)) {
                return [
                    'conflict' => true,
                    'current' => $this->detailsSnapshot($locked),
                ];
            }

            $emailTaken = User::query()
                ->where('user_id', '<>', $locked->user_id)
                ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
                ->exists();
            if ($emailTaken) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'email' => 'This email address is already in use.',
                ]);
            }

            $oldValues = [
                'name' => $locked->name,
                'username' => $locked->username,
                'email' => $locked->email,
                'phone_number' => $locked->phone_number,
            ];
            $newValues = [
                'name' => $validated['name'],
                'username' => $validated['username'],
                'email' => $normalizedEmail,
                'phone_number' => $validated['phone_number'] ?: null,
            ];
            $changes = [];
            foreach ($newValues as $field => $newValue) {
                if ($oldValues[$field] !== $newValue) {
                    $changes[$field] = ['old' => $oldValues[$field], 'new' => $newValue];
                }
            }

            $emailChanged = mb_strtolower((string) $locked->email) !== $normalizedEmail;
            $updates = $newValues;
            if ($emailChanged) {
                $updates += [
                    'email_verified_at' => null,
                    'access_version' => (int) $locked->access_version + 1,
                    'remember_token' => Str::random(60),
                ];
            }

            if ($changes) {
                $savedAt = now();
                if ($locked->updated_at && $savedAt->format('Y-m-d H:i:s') <= $locked->updated_at->format('Y-m-d H:i:s')) {
                    $savedAt = $locked->updated_at->copy()->addSecond();
                }
                $updates['updated_at'] = $savedAt;
                $locked->forceFill($updates)->save();
                $changedFields = array_keys($changes);
                $descriptionFields = implode(', ', $changedFields);

                ActivityLogger::log(
                    'user_details.updated',
                    "Admin account #{$actor->user_id} updated account #{$locked->user_id} details (fields: {$descriptionFields}).",
                    actor: $actor,
                    subject: $locked,
                    properties: ['old_role' => $locked->role, 'new_role' => $locked->role, 'changes' => $changes],
                );
            }

            return [
                'conflict' => false,
                'email_changed' => $emailChanged,
                'old_email' => $emailChanged ? $oldValues['email'] : null,
                'user' => $locked->fresh('promotedBy'),
            ];
        });

        if ($result['conflict']) {
            return response()->json([
                'message' => 'These account details changed after you opened the form. Review the current values and try again.',
                'current' => $result['current'],
            ], 409);
        }

        $noticeSent = null;
        if ($result['email_changed']) {
            try {
                $mailer = app()->environment('local') ? 'log' : config('mail.default');
                Mail::mailer($mailer)->to($result['old_email'])->send(new AdminChangedAccountEmailNotice());
                $noticeSent = true;
            } catch (\Throwable $exception) {
                $noticeSent = false;
                Log::warning('Admin account email change notice could not be sent.', [
                    'user_id' => $result['user']->user_id,
                    'exception' => get_class($exception),
                ]);
            }
        }

        $message = $noticeSent === false
            ? 'Account details saved, but the notice to the old email address could not be sent.'
            : 'Account details saved.'.($noticeSent === true ? ' A notice was sent to the old email address.' : '');

        return response()->json([
            'message' => $message,
            'notice_sent' => $noticeSent,
            'user' => $this->serializeUser($result['user'], (int) $actor->user_id),
        ]);
    }

    private function detailsSnapshot(User $user): array
    {
        return [
            'id' => (int) $user->user_id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'phone_number' => $user->phone_number,
            'updated_at' => $user->updated_at?->toISOString(),
        ];
    }

    private function freshLockActor(Request $request, UserAccessService $access): User
    {
        $actor = User::query()->findOrFail($request->user()->user_id);
        abort_if($request->session()->get(GuardSessionState::key('view_as')) !== null, 403);
        abort_unless(in_array($actor->role, ['admin', 'owner'], true), 403);
        $this->authorizeAction($actor, null, 'edit_access', null, $access);

        return $actor;
    }

    private function authorizeLockTarget(
        Request $request,
        User $actor,
        User $target,
        UserAccessService $access,
    ): void {
        abort_if($request->session()->get(GuardSessionState::key('view_as')) !== null, 403);
        abort_unless(in_array($target->role, self::MANAGED_ROLES, true), 404);
        $this->authorizeAction($actor, $target, 'edit_access', null, $access);
    }

    private function lockActorAndTarget(int $actorId, int $targetId): array
    {
        $users = User::query()
            ->whereIn('user_id', array_values(array_unique([$actorId, $targetId])))
            ->orderBy('user_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('user_id');

        return [
            $users->get($actorId) ?? abort(404),
            $users->get($targetId) ?? abort(404),
        ];
    }

    private function assertNoPendingAccountRequest(User $target): void
    {
        $pending = MfaResetRequest::query()
            ->where('target_user_id', $target->user_id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->lockForUpdate()
            ->exists();
        abort_if($pending, 409, 'An account request is already awaiting approval.');
    }

    private function applyAccountLock(User $actor, User $target, ?string $reason): void
    {
        $target->forceFill([
            'locked_at' => now(),
            'locked_by' => $actor->user_id,
            'locked_reason' => $reason,
            'access_version' => (int) $target->access_version + 1,
            'remember_token' => Str::random(60),
        ])->save();
    }

    private function applyAccountPromotion(User $target): void
    {
        $target->forceFill([
            'locked_at' => null,
            'locked_by' => null,
            'locked_reason' => null,
            'access_version' => (int) $target->access_version + 1,
            'remember_token' => Str::random(60),
        ])->save();
    }

    private function notifyLockObservers(
        User $actor,
        User $target,
        string $type,
        string $title,
        string $body,
        string $action,
        string $result,
        array $extra = [],
        ?int $requesterId = null,
    ): array {
        $recipientIds = User::query()->where('role', 'owner')->pluck('user_id')->all();
        $recipientIds[] = (int) $actor->user_id;
        if ($requesterId !== null) {
            $recipientIds[] = $requesterId;
        }
        $recipientIds = array_values(array_unique(array_filter(
            array_map('intval', $recipientIds),
            fn (int $id): bool => $id !== (int) $target->user_id,
        )));

        foreach ($recipientIds as $recipientId) {
            NotificationService::notify(
                $recipientId,
                $type,
                $title,
                $body,
                [
                    'actor_id' => (int) $actor->user_id,
                    'target_id' => (int) $target->user_id,
                    'action' => $action,
                    'result' => $result,
                ] + $extra,
            );
        }

        return $recipientIds;
    }

    private function auditAccountLock(
        User $actor,
        User $target,
        string $action,
        string $result,
        array $extra = [],
    ): void {
        ActivityLogger::logStrict(
            'user_access.'.$action.'.'.$result,
            "{$actor->name} performed {$action} for {$target->name}: {$result}.",
            actor: $actor,
            subject: $target,
            properties: [
                'actor_id' => (int) $actor->user_id,
                'target_id' => (int) $target->user_id,
                'action' => $action,
                'result' => $result,
            ] + $extra,
        );
    }

    private function sendAccountLockEmails(array $recipientIds, string $subject, string $body): void
    {
        if (! config('services.brevo.key') || $recipientIds === []) {
            return;
        }

        $recipients = User::query()->whereIn('user_id', $recipientIds)->get();
        foreach ($recipients as $recipient) {
            if (! $recipient->email) {
                continue;
            }

            try {
                BrevoMailer::send(
                    $recipient->email,
                    $recipient->name,
                    new PrivilegedMfaNotice($subject, $body),
                );
            } catch (\Illuminate\Http\Client\ConnectionException|\RuntimeException $exception) {
                Log::error('Account lock notification email failed.', [
                    'user_id' => $recipient->user_id,
                    'exception' => get_class($exception),
                ]);
            }
        }
    }

    private function freshActor(Request $request, UserAccessService $access): User
    {
        $actor = User::query()->findOrFail($request->user()->user_id);
        $this->authorizeAction($actor, null, 'edit_access', null, $access);

        return $actor;
    }

    private function applySearch(Builder $query, string $term): void
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term));
        $pattern = '%'.$escaped.'%';
        $exactEmail = filter_var($term, FILTER_VALIDATE_EMAIL) !== false
            ? mb_strtolower($term)
            : null;

        $query->where(function (Builder $nested) use ($pattern, $exactEmail) {
            foreach (['name', 'username'] as $column) {
                $nested->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern]);
            }
            if ($exactEmail !== null) {
                $nested->orWhereRaw('LOWER(email) = ?', [$exactEmail]);
            }
        });
    }

    private function serializeUser(
        User $user,
        ?int $actorId = null,
        ?UserAccessService $access = null,
        ?User $actor = null,
        ?MfaResetRequest $pendingRequest = null,
        ?Request $request = null,
        ?MfaResetRequest $pendingAccountRequest = null,
    ): array
    {
        $mfaService = app(PrivilegedMfaService::class);
        $state = in_array($user->role, self::MANAGED_ROLES, true)
            ? $mfaService->state($user)
            : [
                'key' => $user->hasMfaEnabled() ? 'enabled' : 'pending_setup',
                'label' => $user->hasMfaEnabled() ? 'Enabled' : 'Pending setup',
                'disabled_until' => null,
            ];
        $realAdmin = $actor !== null
            && User::query()->whereKey($actor->user_id)->value('role') === 'admin'
            && ($request === null || $request->session()->get(GuardSessionState::key('view_as')) === null);
        $realActorRole = $actor !== null
            ? User::query()->whereKey($actor->user_id)->value('role')
            : null;
        $viewAsActive = $request !== null
            && $request->session()->get(GuardSessionState::key('view_as')) !== null;
        $mfaTargetAllowed = $realAdmin
            && in_array($user->role, self::MANAGED_ROLES, true)
            && $actorId !== null
            && (int) $user->user_id !== $actorId;
        $lockTargetAllowed = $access !== null
            && $actor !== null
            && ! $viewAsActive
            && in_array($realActorRole, ['admin', 'owner'], true)
            && in_array($user->role, self::MANAGED_ROLES, true)
            && $actorId !== null
            && (int) $user->user_id !== $actorId
            && $access->canManage($actor, $user, 'edit_access');

        $row = [
            'id' => (int) $user->user_id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $actorId !== null && (int) $user->user_id === $actorId
                ? $user->email
                : Mask::email($user->email),
            'role' => $user->role,
            'permissions' => $user->role === 'staff' ? ($user->permissions ?? config('user_access.presets.staff')) : [],
            'mfa_enabled' => $user->hasMfaEnabled(),
            'mfa_state' => $state['key'],
            'mfa_state_label' => $state['label'],
            'mfa_disabled_until' => $state['disabled_until'],
            'mfa_allowed_methods' => in_array($user->role, self::MANAGED_ROLES, true)
                ? ($user->mfa_allowed_methods ?? 'totp')
                : null,
            'mfa_pending_allowed_methods' => $mfaTargetAllowed ? $user->mfa_pending_allowed_methods : null,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'promoted_by' => $user->promotedBy ? [
                'id' => (int) $user->promotedBy->user_id,
                'name' => $user->promotedBy->name,
            ] : null,
            'promoted_at' => $user->promoted_at?->toIso8601String(),
            'locked' => $user->locked_at !== null,
            'locked_at' => $user->locked_at?->toIso8601String(),
            'locked_by' => $user->locked_by,
            'locked_reason' => $user->locked_reason,
            'account_lock_request' => $pendingAccountRequest ? [
                'id' => (int) $pendingAccountRequest->id,
                'action' => $pendingAccountRequest->action,
                'reason' => $pendingAccountRequest->reason,
                'requested_by_user_id' => (int) $pendingAccountRequest->requested_by_user_id,
                'requested_by_name' => $pendingAccountRequest->requester?->name,
                'expires_at' => $pendingAccountRequest->expires_at?->toIso8601String(),
            ] : null,
        ];
        if ($mfaTargetAllowed) {
            $row['mfa_actions'] = [
                'disable' => $user->role !== 'owner' && $state['key'] !== 'disabled',
                'enable' => $state['key'] === 'disabled',
                'force_reenroll' => $user->role !== 'owner' && $state['key'] !== 'reenrollment_required',
                'allowed_methods' => true,
                'request_owner_change' => $user->role === 'owner'
                    && $pendingRequest === null
                    && in_array($state['key'], ['enabled', 'disabled', 'pending_setup', 'reenrollment_required'], true),
                'approve_owner_request' => $pendingRequest !== null
                    && (int) $pendingRequest->requested_by_user_id !== $actorId,
            ];
            $row['pending_mfa_request'] = $pendingRequest ? [
                'id' => (int) $pendingRequest->id,
                'action' => $pendingRequest->action,
                'requested_by_user_id' => (int) $pendingRequest->requested_by_user_id,
                'requested_by_name' => $pendingRequest->requester?->name,
                'expires_at' => $pendingRequest->expires_at?->toIso8601String(),
            ] : null;
        } else {
            $row['mfa_actions'] = [];
        }
        if ($access && $actor) {
            $row['actions'] = [];
            foreach (['edit_access', 'remove_access', 'edit_details'] as $action) {
                $row['actions'][$action] = [
                    'allowed' => $access->canManage($actor, $user, $action),
                    'reason' => $access->canManage($actor, $user, $action) ? null : $access->managementReason($actor, $user, $action),
                ];
            }
            $row['account_lock_actions'] = [
                'allowed' => $lockTargetAllowed,
                'lock' => $lockTargetAllowed
                    && $pendingAccountRequest === null
                    && $user->locked_at === null
                    && ! $this->isLastActiveAccountOfRole($user),
                'promote' => $lockTargetAllowed
                    && $pendingAccountRequest === null
                    && $user->locked_at !== null,
                'approve' => $lockTargetAllowed
                    && $realAdmin
                    && $user->role === 'owner'
                    && $pendingAccountRequest !== null
                    && (int) $pendingAccountRequest->requested_by_user_id !== $actorId,
            ];
            $row['grantable_roles'] = $access->grantableRoles($actor);
        } else {
            $row['account_lock_actions'] = [
                'allowed' => false,
                'lock' => false,
                'promote' => false,
                'approve' => false,
            ];
        }
        return $row;
    }

    private function isLastActiveAccountOfRole(User $user): bool
    {
        if (! in_array($user->role, ['admin', 'owner'], true)) {
            return false;
        }

        return User::query()
            ->where('role', $user->role)
            ->whereNull('locked_at')
            ->count() <= 1;
    }

    private function assertPassword(User $actor, string $password): void
    {
        abort_unless(Hash::check($password, $actor->password), 422, 'Password confirmation failed.');
    }

    private function authorizeAction(User $actor, ?User $target, string $action, ?string $role, UserAccessService $access): void
    {
        if ($access->canManage($actor, $target, $action, $role)) return;
        $message = $access->managementReason($actor, $target, $action, $role);
        ActivityLogger::log('user_access.denied', "{$actor->name} was denied {$action} for account ".($target?->user_id ?? 'new').'.', actor: $actor, subject: $target,
            properties: ['action' => $action, 'target_id' => $target?->user_id, 'target_role' => $target?->role, 'requested_role' => $role, 'reason' => $message]);
        abort(403, $message);
    }

    private function assertStaffPermissions(User $actor, string $role, array $permissions, UserAccessService $access): void
    {
        if ($role !== 'staff') {
            abort_if($permissions !== [], 422, 'Permissions can only be customized for staff accounts.');
        }
        foreach ($permissions as $permission) {
            abort_unless(in_array($permission, $access->staffPermissionKeys(), true), 422, 'Only staff permissions can be customized.');
        }
    }

    private function normalizedStaffPermissions(array $permissions): array
    {
        return array_values(array_unique(array_intersect(
            config('user_access.staff_permissions', []),
            $permissions,
        )));
    }

    private function assertNotLastAdmin(string $intent = 'demoted or removed', bool $activeOnly = false): void
    {
        $admins = User::query()->where('role', 'admin');
        if ($activeOnly) {
            $admins->whereNull('locked_at');
        }
        $lockedAdmins = $admins->orderBy('user_id')->lockForUpdate()->get(['user_id']);
        abort_if($lockedAdmins->count() <= 1, 409, "The last admin cannot be {$intent}.");
    }

    private function assertNotLastOwner(bool $activeOnly = false): void
    {
        $owners = User::query()->where('role', 'owner');
        if ($activeOnly) {
            $owners->whereNull('locked_at');
        }
        $lockedOwners = $owners->orderBy('user_id')->lockForUpdate()->get(['user_id']);
        abort_if($lockedOwners->count() <= 1, 409, 'The last owner cannot be locked.');
    }
}
