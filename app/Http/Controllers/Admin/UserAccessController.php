<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\AdminChangedAccountEmailNotice;
use App\Models\User;
use App\Services\UserAccessService;
use App\Support\ActivityLogger;
use App\Support\Mask;
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

    public function page(): View
    {
        $permissionDefinitions = config('user_access.permissions', []);

        return view('admin.user-access.index', [
            'actorRole' => auth()->user()->role,
            'actorId' => (int) auth()->user()->user_id,
            'permissionDefinitions' => collect(config('user_access.staff_permissions', []))
                ->mapWithKeys(fn (string $key) => [$key => $permissionDefinitions[$key] ?? null])
                ->all(),
            'staffPreset' => config('user_access.presets.staff', []),
            'ownerExists' => User::query()->where('role', 'owner')->exists(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(['all', ...self::MANAGED_ROLES])],
        ]);

        $query = User::query()->whereIn('role', self::MANAGED_ROLES)->with('promotedBy');
        if (($validated['role'] ?? 'all') !== 'all') {
            $query->where('role', $validated['role']);
        }
        if (trim((string) ($validated['q'] ?? '')) !== '') {
            $this->applySearch($query, trim($validated['q']));
        }

        $users = $query->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END")
            ->orderBy('name')->get()
            ->map(fn (User $user) => $this->serializeUser($user, (int) $request->user()->user_id));

        return response()->json(['users' => $users]);
    }

    public function candidates(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $term = trim((string) ($validated['q'] ?? ''));
        if (mb_strlen($term) < 3) {
            return response()->json(['candidates' => []]);
        }

        $query = User::query()->where('role', 'user');
        $this->applySearch($query, $term);
        $candidates = $query->orderBy('name')->limit(8)->get(['user_id', 'name', 'username', 'email'])
            ->map(fn (User $user) => [
                'id' => (int) $user->user_id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => Mask::email($user->email),
            ]);

        return response()->json(['candidates' => $candidates]);
    }

    public function store(Request $request, UserAccessService $access): JsonResponse
    {
        $actor = $this->freshActor($request);
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'role' => ['required', Rule::in(['staff', 'admin', 'owner'])],
            'permissions' => ['sometimes', 'array', 'max:'.count(config('user_access.staff_permissions', []))],
            'permissions.*' => ['string', 'distinct', Rule::in(array_keys(config('user_access.permissions', [])))],
            'password' => ['required', 'string'],
        ]);
        $this->assertPassword($actor, $validated['password']);
        $this->assertGrantable($actor, $validated['role'], $validated['permissions'] ?? [], $access);

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

        return response()->json(['message' => 'Access updated.', 'user' => $this->serializeUser($user)], 201);
    }

    public function update(Request $request, User $user, UserAccessService $access): JsonResponse
    {
        $actor = $this->freshActor($request);
        $validated = $request->validate([
            'role' => ['required', Rule::in(self::MANAGED_ROLES)],
            'permissions' => ['sometimes', 'array', 'max:'.count(config('user_access.staff_permissions', []))],
            'permissions.*' => ['string', 'distinct', Rule::in(array_keys(config('user_access.permissions', [])))],
            'password' => ['required', 'string'],
        ]);
        $this->assertPassword($actor, $validated['password']);
        $this->assertMayManageTarget($actor, $user);
        $this->assertGrantable($actor, $validated['role'], $validated['permissions'] ?? [], $access);

        $updated = DB::transaction(function () use ($actor, $user, $validated, $access) {
            $target = User::query()->whereKey($user->user_id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($target->role, self::MANAGED_ROLES, true), 404);
            $this->assertMayManageTarget($actor, $target);

            $nextRole = $validated['role'];
            if ($target->role === 'owner' && $nextRole !== 'owner') {
                $this->lockOwnerRows();
                abort_if(User::query()->where('role', 'owner')->count() <= 1, 409, 'The last owner cannot be demoted or removed.');
            }

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

        return response()->json(['message' => 'Access updated.', 'user' => $this->serializeUser($updated)]);
    }

    public function removeAccess(Request $request, User $user): JsonResponse
    {
        $actor = $this->freshActor($request);
        $validated = $request->validate(['password' => ['required', 'string']]);
        $this->assertPassword($actor, $validated['password']);
        $this->assertMayManageTarget($actor, $user);

        $removed = DB::transaction(function () use ($actor, $user) {
            $target = User::query()->whereKey($user->user_id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($target->role, self::MANAGED_ROLES, true), 404);
            $this->assertMayManageTarget($actor, $target);
            if ($target->role === 'owner') {
                $this->lockOwnerRows();
                abort_if(User::query()->where('role', 'owner')->count() <= 1, 409, 'The last owner cannot be demoted or removed.');
            }

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

    public function details(Request $request, User $user): JsonResponse
    {
        $actor = $this->freshAdmin($request);
        $target = User::query()->findOrFail($user->user_id);
        $this->assertDetailsTarget($target);

        ActivityLogger::log(
            'user_details.viewed',
            "Admin account #{$actor->user_id} viewed account details for elevated account #{$target->user_id}.",
            actor: $actor,
            subject: $target,
            properties: ['fields' => ['name', 'username', 'email', 'phone_number']],
        );

        return response()->json(['details' => $this->detailsSnapshot($target)]);
    }

    public function updateDetails(Request $request, User $user): JsonResponse
    {
        $actor = $this->freshAdmin($request);
        $target = User::query()->findOrFail($user->user_id);
        $this->assertDetailsTarget($target);

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

        $result = DB::transaction(function () use ($actor, $target, $validated, $normalizedEmail) {
            $locked = User::query()->whereKey($target->user_id)->lockForUpdate()->firstOrFail();
            $this->assertDetailsTarget($locked);

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
                    "Admin account #{$actor->user_id} updated elevated account #{$locked->user_id} details (fields: {$descriptionFields}).",
                    actor: $actor,
                    subject: $locked,
                    properties: ['changes' => $changes],
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

    private function assertDetailsTarget(User $target): void
    {
        abort_unless(in_array($target->role, ['staff', 'owner'], true), 404);
    }

    private function freshAdmin(Request $request): User
    {
        $actor = User::query()->findOrFail($request->user()->user_id);
        abort_unless($actor->role === 'admin', 403);

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

    private function serializeUser(User $user, ?int $actorId = null): array
    {
        return [
            'id' => (int) $user->user_id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $actorId !== null && (int) $user->user_id === $actorId
                ? $user->email
                : Mask::email($user->email),
            'role' => $user->role,
            'permissions' => $user->role === 'staff' ? ($user->permissions ?? config('user_access.presets.staff')) : [],
            'mfa_enabled' => $user->hasMfaEnabled(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'promoted_by' => $user->promotedBy ? [
                'id' => (int) $user->promotedBy->user_id,
                'name' => $user->promotedBy->name,
            ] : null,
            'promoted_at' => $user->promoted_at?->toIso8601String(),
        ];
    }

    private function freshActor(Request $request): User
    {
        $user = User::query()->findOrFail($request->user()->user_id);
        abort_unless(in_array($user->role, ['owner', 'admin'], true), 403);
        return $user;
    }

    private function assertPassword(User $actor, string $password): void
    {
        abort_unless(Hash::check($password, $actor->password), 422, 'Password confirmation failed.');
    }

    private function assertMayManageTarget(User $actor, User $target): void
    {
        abort_if((int) $actor->user_id === (int) $target->user_id, 403, 'You cannot change your own role, permissions, or access.');
        abort_if($target->role === 'owner' && $actor->role !== 'owner', 403, 'Only an owner can manage owner accounts.');
    }

    private function assertGrantable(User $actor, string $role, array $permissions, UserAccessService $access): void
    {
        if ($role === 'owner') {
            abort_unless($actor->role === 'owner', 403, 'Only an owner can grant the owner role.');
        }
        if ($role !== 'staff') {
            abort_if($permissions !== [], 422, 'Permissions can only be customized for staff accounts.');
        }
        foreach ($permissions as $permission) {
            abort_unless($access->mayGrant($actor, $permission), 403, 'You cannot grant a permission you do not have.');
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

    private function lockOwnerRows(): void
    {
        User::query()->where('role', 'owner')->orderBy('user_id')->lockForUpdate()->get(['user_id']);
    }
}
