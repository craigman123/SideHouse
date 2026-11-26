<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\PrivilegedMfaNotice;
use App\Models\MfaResetRequest;
use App\Models\User;
use App\Services\CustomerMfaService;
use App\Services\PrivilegedMfaService;
use App\Support\ActivityLogger;
use App\Support\BrevoMailer;
use App\Support\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PrivilegedMfaController extends Controller
{
    public function disable(Request $request, User $user, PrivilegedMfaService $mfa): JsonResponse
    {
        $actor = $this->freshActor($request);
        Gate::forUser($actor)->authorize('disablePrivilegedMfa', $user);
        $validated = $request->validate(['code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/']]);

        $target = DB::transaction(function () use ($actor, $user, $validated, $mfa): User {
            [$lockedActor, $lockedTarget] = $this->lockActorAndTarget($actor->user_id, $user->user_id);
            Gate::forUser($lockedActor)->authorize('disablePrivilegedMfa', $lockedTarget);
            abort_if($mfa->isTemporarilyDisabled($lockedTarget), 409, 'This account already has MFA temporarily disabled.');

            $step = $mfa->verifyFreshTotp($lockedActor, $validated['code']);
            $lockedActor->forceFill(['mfa_last_admin_action_step' => $step])->save();
            $this->disableTarget($lockedTarget);
            $this->notifyTargetAndOwner(
                $lockedTarget,
                'privileged_mfa_disabled',
                'MFA disabled',
                'An administrator temporarily disabled MFA for this account. The saved authenticator and recovery codes remain in place, and MFA will be required again within 24 hours.',
                'disable',
            );
            $this->audit($lockedActor, $lockedTarget, 'privileged_mfa.disabled', 'disable', 'success');

            return $lockedTarget->fresh();
        });

        return $this->jsonWithEmail(
            $target,
            'MFA is disabled for up to 24 hours. The saved authenticator and recovery codes were retained.',
            'MFA disabled',
            'An administrator temporarily disabled MFA for your Side House account. Your saved authenticator and recovery codes remain in place. MFA will be required again after the temporary disablement expires.',
        );
    }

    public function enable(Request $request, User $user, PrivilegedMfaService $mfa): JsonResponse
    {
        $actor = $this->freshActor($request);
        Gate::forUser($actor)->authorize('enablePrivilegedMfa', $user);
        $validated = $request->validate(['code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/']]);

        $target = DB::transaction(function () use ($actor, $user, $validated, $mfa): User {
            [$lockedActor, $lockedTarget] = $this->lockActorAndTarget($actor->user_id, $user->user_id);
            Gate::forUser($lockedActor)->authorize('enablePrivilegedMfa', $lockedTarget);
            abort_unless($mfa->isTemporarilyDisabled($lockedTarget), 409, 'This account does not have MFA temporarily disabled.');

            $step = $mfa->verifyFreshTotp($lockedActor, $validated['code']);
            $lockedActor->forceFill(['mfa_last_admin_action_step' => $step])->save();
            $lockedTarget->forceFill([
                'mfa_admin_disabled_until' => null,
                'access_version' => (int) $lockedTarget->access_version + 1,
                'remember_token' => Str::random(60),
            ])->save();
            $this->notifyTargetAndOwner(
                $lockedTarget,
                'privileged_mfa_enabled',
                'MFA enabled',
                'An administrator ended this account\'s temporary MFA disablement. The account’s normal MFA requirement applies again.',
                'enable',
            );
            $this->audit($lockedActor, $lockedTarget, 'privileged_mfa.enabled', 'enable', 'success');

            return $lockedTarget->fresh();
        });

        return $this->jsonWithEmail(
            $target,
            'The temporary MFA disablement ended. The normal MFA requirement applies again.',
            'MFA enabled',
            'An administrator ended the temporary MFA disablement for your account. Your normal MFA requirement applies again.',
        );
    }

    public function forceReenrollment(Request $request, User $user, PrivilegedMfaService $mfa): JsonResponse
    {
        $actor = $this->freshActor($request);
        Gate::forUser($actor)->authorize('forcePrivilegedMfaReenrollment', $user);
        $validated = $request->validate(['code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/']]);

        $target = DB::transaction(function () use ($actor, $user, $validated, $mfa): User {
            [$lockedActor, $lockedTarget] = $this->lockActorAndTarget($actor->user_id, $user->user_id);
            Gate::forUser($lockedActor)->authorize('forcePrivilegedMfaReenrollment', $lockedTarget);
            abort_if(
                $lockedTarget->mfa_reenroll_required && $lockedTarget->mfa_admin_disabled_until === null,
                409,
                'This account already requires MFA re-enrollment.',
            );

            $step = $mfa->verifyFreshTotp($lockedActor, $validated['code']);
            $lockedActor->forceFill(['mfa_last_admin_action_step' => $step])->save();
            $this->applyForcedReenrollment($lockedTarget);
            $this->notifyTargetAndOwner(
                $lockedTarget,
                'privileged_mfa_reenrollment',
                'MFA re-enrollment required',
                'An administrator erased this account\'s saved authenticator and recovery codes. A new TOTP factor is required before accessing protected pages.',
                'force_reenrollment',
            );
            $this->audit($lockedActor, $lockedTarget, 'privileged_mfa.reenrollment_required', 'force_reenrollment', 'success');

            return $lockedTarget->fresh();
        });

        return $this->jsonWithEmail(
            $target,
            'The saved factor and recovery codes were erased. MFA setup is required at the next sign-in.',
            'MFA re-enrollment required',
            'An administrator erased the saved authenticator and recovery codes for your account. Enroll a new TOTP factor before accessing protected pages.',
        );
    }

    public function allowedMethods(Request $request, User $user): JsonResponse
    {
        $actor = $this->freshActor($request);
        Gate::forUser($actor)->authorize('setPrivilegedMfaMethods', $user);
        $validated = $request->validate([
            'allowed_methods' => ['required', Rule::in(['totp', 'passkey', 'both'])],
        ]);
        abort_if($validated['allowed_methods'] === 'passkey', 422, 'Passkey-only access is unavailable until passkey enrollment is implemented.');

        $result = DB::transaction(function () use ($actor, $user, $validated): array {
            [$lockedActor, $lockedTarget] = $this->lockActorAndTarget($actor->user_id, $user->user_id);
            Gate::forUser($lockedActor)->authorize('setPrivilegedMfaMethods', $lockedTarget);

            $requested = $validated['allowed_methods'];
            $currentMethod = $lockedTarget->mfa_method
                ?? ($lockedTarget->hasMfaEnabled() ? 'totp' : null);
            $requestedMethods = match ($requested) {
                'both' => ['totp', 'passkey'],
                default => ['totp'],
            };
            $staged = $currentMethod !== null && ! in_array($currentMethod, $requestedMethods, true);

            if ($staged) {
                $lockedTarget->forceFill(['mfa_pending_allowed_methods' => $requested])->save();
            } else {
                $lockedTarget->forceFill([
                    'mfa_allowed_methods' => $requested,
                    'mfa_pending_allowed_methods' => null,
                ])->save();
            }

            $result = $staged ? 'staged' : 'success';
            $this->notifyTargetAndOwner(
                $lockedTarget,
                'privileged_mfa_methods',
                'MFA methods updated',
                $staged
                    ? 'A future MFA method change is pending enrollment and verification of the new method.'
                    : 'An administrator updated the MFA methods allowed for this account.',
                'allowed_methods',
            );
            $this->audit(
                $lockedActor,
                $lockedTarget,
                'privileged_mfa.allowed_methods_changed',
                'allowed_methods',
                $result,
                ['allowed_methods' => $requested, 'pending' => $staged],
            );

            return [
                'target' => $lockedTarget->fresh(),
                'staged' => $staged,
            ];
        });

        $message = $result['staged']
            ? 'The method policy is pending a staged method switch.'
            : 'Allowed methods updated.';

        return $this->jsonWithEmail(
            $result['target'],
            $message,
            'MFA methods updated',
            $message,
        );
    }

    public function requestOwnerChange(Request $request, User $user): JsonResponse
    {
        $actor = $this->freshActor($request);
        Gate::forUser($actor)->authorize('requestOwnerMfaChange', $user);
        $validated = $request->validate([
            'action' => ['required', Rule::in(['disable', 'force_reenroll'])],
        ]);

        $resetRequest = DB::transaction(function () use ($actor, $user, $validated): MfaResetRequest {
            [$lockedActor, $lockedTarget] = $this->lockActorAndTarget($actor->user_id, $user->user_id);
            Gate::forUser($lockedActor)->authorize('requestOwnerMfaChange', $lockedTarget);
            abort_unless($lockedTarget->role === 'owner', 404);

            $pending = MfaResetRequest::query()
                ->where('target_user_id', $lockedTarget->user_id)
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->exists();
            abort_if($pending, 409, 'An owner MFA request is already awaiting approval.');

            $requestRecord = MfaResetRequest::query()->create([
                'target_user_id' => $lockedTarget->user_id,
                'requested_by_user_id' => $lockedActor->user_id,
                'action' => $validated['action'],
                'status' => 'pending',
                'expires_at' => now()->addDay(),
            ]);

            $this->notifyTargetAndOwner(
                $lockedTarget,
                'privileged_mfa_owner_request',
                'Owner MFA request pending',
                $validated['action'] === 'disable'
                    ? 'A second administrator must approve temporarily disabling MFA for this owner account within 24 hours.'
                    : 'A second administrator must approve forcing MFA re-enrollment for this owner account within 24 hours.',
                'owner_request',
                ['request_id' => $requestRecord->id],
            );
            $this->audit(
                $lockedActor,
                $lockedTarget,
                'privileged_mfa.owner_request_created',
                $validated['action'],
                'pending',
                ['request_id' => $requestRecord->id, 'expires_at' => $requestRecord->expires_at->toIso8601String()],
            );

            return $requestRecord;
        });

        $emailResult = $this->sendEmails(
            [$user->fresh()],
            'Owner MFA request pending',
            'A second administrator must approve an MFA change for your account within 24 hours.',
        );

        return response()->json([
            'message' => 'Owner MFA request created. A different administrator must approve it within 24 hours.',
            'request' => [
                'id' => $resetRequest->id,
                'action' => $resetRequest->action,
                'expires_at' => $resetRequest->expires_at->toIso8601String(),
            ],
            'email_notification' => $emailResult,
        ], 201);
    }

    public function approveOwnerChange(
        Request $request,
        User $user,
        int $requestId,
        PrivilegedMfaService $mfa
    ): JsonResponse {
        $actor = $this->freshActor($request);
        $requestRecord = MfaResetRequest::query()->findOrFail($requestId);
        Gate::forUser($actor)->authorize('approveOwnerMfaChange', [$user, $requestRecord]);
        $validated = $request->validate(['code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/']]);

        $result = DB::transaction(function () use ($actor, $user, $requestRecord, $validated, $mfa): array {
            [$lockedActor, $lockedTarget] = $this->lockActorAndTarget($actor->user_id, $user->user_id);
            $lockedRequest = MfaResetRequest::query()
                ->whereKey($requestRecord->id)
                ->lockForUpdate()
                ->firstOrFail();
            Gate::forUser($lockedActor)->authorize('approveOwnerMfaChange', [$lockedTarget, $lockedRequest]);
            abort_unless($lockedRequest->status === 'pending', 409, 'This MFA request is no longer pending.');

            if ($lockedRequest->expires_at->lessThanOrEqualTo(now())) {
                $lockedRequest->forceFill(['status' => 'expired'])->save();
                $this->audit(
                    $lockedActor,
                    $lockedTarget,
                    'privileged_mfa.owner_request_approval',
                    $lockedRequest->action,
                    'expired',
                    ['request_id' => $lockedRequest->id],
                );

                return ['expired' => true, 'target' => $lockedTarget];
            }

            $step = $mfa->verifyFreshTotp($lockedActor, $validated['code']);
            $lockedActor->forceFill(['mfa_last_admin_action_step' => $step])->save();
            $this->applyAction($lockedTarget, $lockedRequest->action);
            $lockedRequest->forceFill([
                'status' => 'approved',
                'approved_by_user_id' => $lockedActor->user_id,
                'approved_at' => now(),
                'executed_at' => now(),
            ])->save();

            $this->notifyTargetAndOwner(
                $lockedTarget,
                'privileged_mfa_owner_executed',
                'Owner MFA change completed',
                $lockedRequest->action === 'disable'
                    ? 'Two administrators approved temporarily disabling MFA. The saved factor remains in place and MFA will be required again within 24 hours.'
                    : 'Two administrators approved erasing the saved factor and requiring MFA re-enrollment.',
                'owner_execution',
                ['request_id' => $lockedRequest->id],
            );
            $this->audit(
                $lockedActor,
                $lockedTarget,
                'privileged_mfa.owner_request_approved',
                $lockedRequest->action,
                'success',
                ['request_id' => $lockedRequest->id, 'requested_by_user_id' => $lockedRequest->requested_by_user_id],
            );

            return ['expired' => false, 'target' => $lockedTarget->fresh()];
        });

        if ($result['expired']) {
            return response()->json(['message' => 'This MFA request expired and cannot be approved.'], 410);
        }

        $message = $requestRecord->action === 'disable'
            ? 'Owner MFA is temporarily disabled for up to 24 hours. The saved factor was retained.'
            :             'The owner\'s saved factor and recovery codes were erased. MFA setup is required at the next sign-in.';
        $emailResult = $this->sendEmails([$result['target']], 'Owner MFA change completed', $message);

        return response()->json(['message' => $message, 'email_notification' => $emailResult]);
    }

    public function regenerateRecoveryCodes(
        Request $request,
        PrivilegedMfaService $mfa,
        CustomerMfaService $recoveryCodes
    ): RedirectResponse {
        $actor = $this->freshActor($request);
        Gate::forUser($actor)->authorize('regenerateOwnRecoveryCodes', $actor);
        $validated = $request->validate(['code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/']]);

        $codes = DB::transaction(function () use ($actor, $validated, $mfa, $recoveryCodes): array {
            $lockedActor = User::query()->whereKey($actor->user_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($lockedActor)->authorize('regenerateOwnRecoveryCodes', $lockedActor);
            abort_if(
                $mfa->isTemporarilyDisabled($lockedActor),
                409,
                'Recovery codes cannot be regenerated while MFA is temporarily disabled.',
            );
            abort_unless($lockedActor->hasMfaEnabled(), 409, 'Set up MFA before regenerating recovery codes.');
            $step = $mfa->verifyFreshTotp($lockedActor, $validated['code']);
            $generated = $recoveryCodes->generateRecoveryCodes();
            $lockedActor->forceFill([
                'mfa_recovery_codes' => $generated['hashed'],
                'mfa_last_admin_action_step' => $step,
            ])->save();
            $this->audit(
                $lockedActor,
                $lockedActor,
                'privileged_mfa.recovery_codes_regenerated',
                'recovery_codes_regenerated',
                'success',
            );

            return $generated['plain'];
        });

        return back()->with('recovery_codes', $codes)->with('success', 'Recovery codes regenerated.');
    }

    private function freshActor(Request $request): User
    {
        return User::query()->findOrFail($request->user()->user_id);
    }

    private function lockActorAndTarget(int $actorId, int $targetId): array
    {
        $ids = [$actorId, $targetId];
        sort($ids);

        $locked = User::query()
            ->whereIn('user_id', $ids)
            ->orderBy('user_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('user_id');

        return [
            $locked->get($actorId) ?? abort(404),
            $locked->get($targetId) ?? abort(404),
        ];
    }

    private function disableTarget(User $target): void
    {
        $target->forceFill([
            'mfa_admin_disabled_until' => now()->addHours(24),
        ])->save();
    }

    private function applyForcedReenrollment(User $target): void
    {
        $target->forceFill([
            'mfa_enabled' => false,
            'mfa_secret' => null,
            'mfa_recovery_codes' => null,
            'mfa_method' => null,
            'mfa_admin_disabled_until' => null,
            'mfa_reenroll_required' => true,
            'access_version' => (int) $target->access_version + 1,
            'remember_token' => Str::random(60),
        ])->save();
    }

    private function applyAction(User $target, string $action): void
    {
        if ($action === 'disable') {
            abort_unless(! app(PrivilegedMfaService::class)->isTemporarilyDisabled($target), 409);
            $this->disableTarget($target);

            return;
        }

        abort_unless($action === 'force_reenroll', 409);
        abort_if($target->mfa_reenroll_required && $target->mfa_admin_disabled_until === null, 409, 'This owner account already requires MFA re-enrollment.');
        $this->applyForcedReenrollment($target);
    }

    private function notifyTargetAndOwner(
        User $target,
        string $type,
        string $title,
        string $body,
        string $action,
        array $data = [],
    ): void {
        NotificationService::notify(
            (int) $target->user_id,
            $type,
            $title,
            $body,
            ['action' => $action, 'target_id' => (int) $target->user_id] + $data,
        );

        $owner = User::query()->where('role', 'owner')->first();
        if ($owner && (int) $owner->user_id !== (int) $target->user_id) {
            NotificationService::notify(
                (int) $owner->user_id,
                $type,
                $title,
                $body,
                ['action' => $action, 'target_id' => (int) $target->user_id] + $data,
            );
        }
    }

    private function audit(
        User $actor,
        User $target,
        string $event,
        string $action,
        string $result,
        array $extra = [],
    ): void {
        ActivityLogger::logStrict(
            $event,
            "{$actor->name} performed privileged MFA action {$action} for {$target->name}: {$result}.",
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

    private function jsonWithEmail(User $target, string $message, string $subject, string $body): JsonResponse
    {
        $recipients = [$target];
        $owner = User::query()->where('role', 'owner')->first();
        if ($owner && (int) $owner->user_id !== (int) $target->user_id) {
            $recipients[] = $owner;
        }

        $emailResult = $this->sendEmails($recipients, $subject, $body);

        return response()->json([
            'message' => $message,
            'mfa_state' => app(PrivilegedMfaService::class)->state($target),
            'email_notification' => $emailResult,
        ]);
    }

    private function sendEmails(array $recipients, string $subject, string $body): array
    {
        if (! config('services.brevo.key')) {
            return ['status' => 'not_configured'];
        }

        $failed = [];
        foreach ($recipients as $recipient) {
            if (! $recipient instanceof User || ! $recipient->email) {
                continue;
            }

            try {
                BrevoMailer::send(
                    $recipient->email,
                    $recipient->name,
                    new PrivilegedMfaNotice($subject, $body),
                );
            } catch (\Illuminate\Http\Client\ConnectionException|\RuntimeException $exception) {
                $failed[] = $recipient->user_id;
                Log::error('Privileged MFA notice email failed.', [
                    'user_id' => $recipient->user_id,
                    'exception' => get_class($exception),
                ]);
            }
        }

        return $failed === []
            ? ['status' => 'sent']
            : ['status' => 'failed', 'user_ids' => $failed];
    }
}
