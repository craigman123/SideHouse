<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureAccessVersion;
use App\Models\User;
use App\Services\PrivilegedMfaService;
use App\Support\ActivityLogger;
use App\Support\GuardSessionState;
use App\Support\GoogleIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function showLogin()
    {
        $guard = 'customer';

        return view('auth.login', [
            'loginLane' => $guard,
            'loginSubmitUrl' => route('login.submit'),
            'googleAuthUrl' => route('auth.google'),
            'loginHomeUrl' => route('user.dashboard'),
            'mfaSetupInitUrl' => role_route('customer.mfa.setup.init'),
            'mfaEnableUrl' => role_route('customer.mfa.enable'),
            'mfaVerifyUrl' => role_route('customer.mfa.verify'),
        ]);
    }

    private function privilegedMfaResponseType(User $user): string
    {
        return $user->hasMfaEnabled()
            && ! app(PrivilegedMfaService::class)->isReenrollmentRequired($user)
            ? 'challenge'
            : 'setup';
    }

    private function privilegedMfaRequired(Request $request, User $user, ?string $guard = null): bool
    {
        $mfa = app(PrivilegedMfaService::class);
        if ($mfa->isReenrollmentRequired($user) || ! $user->hasMfaEnabled()) {
            return true;
        }

        $guard = config('auth.multi_guard')
            ? ($guard ?? GuardSessionState::currentGuard())
            : null;
        $passedAt = $request->session()->get(GuardSessionState::key('mfa_passed_at', $guard));
        $passedUserId = $request->session()->get(GuardSessionState::key('mfa_passed_user_id', $guard));

        return ! (
            $passedAt
            && (int) $passedUserId === (int) $user->user_id
            && (now()->timestamp - $passedAt) < 60 * 60 * 8
        );
    }

    private function privilegedMfaResponse(Request $request, User $user)
    {
        $mfaType = $this->privilegedMfaResponseType($user);
        $guard = $user->role;
        $routeName = mfa_route_name('mfa.'.$mfaType, config('auth.multi_guard') ? $guard : null);

        if (! $request->expectsJson()) {
            return redirect()->route($routeName);
        }

        return response()->json([
            'mfa_required' => true,
            'mfa_type' => $mfaType,
            'csrf_token' => csrf_token(),
            'mfa_urls' => $this->mfaUrls($guard),
        ]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required',
        ]);

        if (config('auth.multi_guard')) {
            return $this->loginOnRoleGuard($request, $credentials);
        }

        if (!Auth::attempt($credentials)) {
            ActivityLogger::log(
                'user.login_failed',
                'Failed login attempt from ' . $request->ip() . '.',
            );

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Invalid username or password.'], 422);
            }

            return back()->withErrors(['username' => 'Invalid username or password.'])->onlyInput('username');
        }

        $request->session()->forget(GuardSessionState::key('view_as'));
        $request->session()->regenerate();
        $user = auth()->user();
        $this->resetLumiPopoverSession($request);
        $this->recordSuccessfulLogin($request, $user);

        if ($user->requiresMfa()) {
            $mfaType = $this->privilegedMfaResponseType($user);
            ActivityLogger::log(
                $user->isAdmin() ? 'admin.logged_in' : 'staff.logged_in',
                $user->name . ' logged in.',
                subject: $user,
            );

            // Tell the frontend to show the correct MFA modal
            if ($request->expectsJson()) {
                return response()->json([
                    'mfa_required' => true,
                    'mfa_type' => $mfaType,
                    'csrf_token' => csrf_token(),
                ]);
            }

            return redirect()->route('mfa.'.$mfaType);
        }

        // Normal user
        ActivityLogger::log(
            'user.logged_in',
            $user->name . ' logged in.',
            subject: $user,
        );

        if ($user->role === 'user' && $user->hasMfaEnabled()) {
            if ($request->expectsJson()) {
                return $this->customerMfaResponse($request);
            }

            return redirect()->route('mfa.challenge');
        }

        if ($user->requiresMfa()) {
            return $this->privilegedMfaResponse($request, $user);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'redirect' => $this->homePath($user),
                'message' => 'Logged in successfully!',
            ]);
        }

        return redirect($this->homePath($user))->with('success', 'Logged in successfully!');
    }

    public function showRegister()
    {
        return view('auth.register');
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,username',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|min:12|confirmed',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'user',
        ]);

        $request->session()->forget(GuardSessionState::key('view_as'));
        if (config('auth.multi_guard')) {
            Auth::guard('customer')->login($user);
        } else {
            Auth::login($user);
        }
        $this->resetLumiPopoverSession($request);
        $this->recordSuccessfulLogin($request, $user);

        ActivityLogger::log(
            'user.registered',
            $user->name . ' registered.',
            subject: $user,
        );

        return redirect()->route('user.dashboard')->with('success', 'Account created! Welcome to Side House.');
    }

    /**
     * Google sign-in for both login and registration — one endpoint
     * handles both, since from the button's perspective there's no
     * meaningful difference: verify the token, then log the matching
     * account in or create one on the spot.
     *
     * Unlike the password flow, a verified Google token IS proof of
     * identity on its own — Google has already confirmed this person
     * owns the email address, so an existing account is logged into
     * directly with no password check.
     */
    public function googleAuth(Request $request)
    {
        $validated = $request->validate([
            'id_token' => ['required', 'string'],
        ]);

        $claims = GoogleIdentity::verifyIdToken($validated['id_token']);

        if ($claims === null) {
            return response()->json([
                'message' => "We couldn't verify that Google sign-in. Please try again.",
            ], 422);
        }

        if (config('auth.multi_guard')) {
            return $this->googleLoginOnRoleGuard($request, $claims);
        }

        $user = User::whereRaw('LOWER(email) = ?', [$claims['email']])->first();
        $isNewAccount = false;

        if ($user === null) {
            $user = User::create([
                'name'     => $claims['name'] ?: Str::before($claims['email'], '@'),
                'username' => $this->generateUniqueUsername($claims['name'] ?? $claims['email']),
                'email'    => $claims['email'],
                // Google already verified this person owns the email, so
                // there's nothing meaningful for a password to protect —
                // a random value just keeps the (required) column filled
                // without implying a real password exists to sign in with
                // any other way.
                'password' => Str::random(40),
                'role'     => 'user',
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $isNewAccount = true;
        }

        $request->session()->forget(GuardSessionState::key('view_as'));
        Auth::login($user);
        $request->session()->regenerate();
        $this->resetLumiPopoverSession($request);
        $this->recordSuccessfulLogin($request, $user);

        ActivityLogger::log(
            $isNewAccount ? 'user.registered' : 'user.logged_in',
            $isNewAccount
                ? $user->name . ' registered via Google sign-in.'
                : $user->name . ' logged in via Google sign-in.',
            subject: $user,
        );

        if ($user->role === 'user' && $user->hasMfaEnabled()) {
            return $this->customerMfaResponse($request);
        }

        if ($user->requiresMfa()) {
            return $this->privilegedMfaResponse($request, $user);
        }

        return response()->json([
            'message' => $isNewAccount ? 'Account created! Welcome to Side House.' : 'Logged in successfully!',
            'redirect' => $this->homePath($user),
        ]);
    }

    private function loginOnRoleGuard(Request $request, array $credentials)
    {
        $provider = Auth::guard('customer')->getProvider();
        $user = $provider->retrieveByCredentials($credentials);
        $guard = $user instanceof User ? $this->guardForRole($user->role) : null;

        if (
            ! $user instanceof User
            || ! $provider->validateCredentials($user, $credentials)
            || $guard === null
        ) {
            ActivityLogger::log(
                'user.login_failed',
                'Failed login attempt from '.$request->ip().'.',
            );

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Invalid username or password.'], 422);
            }

            return back()->withErrors(['username' => 'Invalid username or password.'])->onlyInput('username');
        }

        if (Auth::guard($guard)->user()?->is($user)) {
            if ($guard === 'customer' && $this->customerMfaRequired($request, $user)) {
                return $this->customerMfaResponse($request);
            }
            if (
                $user->requiresMfa()
                && app(PrivilegedMfaService::class)->isReenrollmentRequired($user)
            ) {
                return $this->privilegedMfaResponse($request, $user);
            }
            if (
                $user->requiresMfa()
                && $user->hasMfaEnabled()
                && $this->privilegedMfaRequired($request, $user, $guard)
            ) {
                return $this->privilegedMfaResponse($request, $user);
            }

            return $this->loginRedirectResponse($request, $user);
        }

        Auth::shouldUse($guard);
        GuardSessionState::runPreservingToken(
            $request,
            fn () => Auth::guard($guard)->login($user),
        );
        $request->session()->forget(GuardSessionState::key('view_as'));
        GuardSessionState::regenerateSession($request);
        $this->resetLumiPopoverSession($request);
        $this->recordSuccessfulLogin($request, $user);

        if ($user->requiresMfa()) {
            $mfaType = $this->privilegedMfaResponseType($user);
            ActivityLogger::log(
                $user->isAdmin() ? 'admin.logged_in' : 'staff.logged_in',
                $user->name.' logged in.',
                subject: $user,
            );

            if ($request->expectsJson()) {
                return response()->json([
                    'mfa_required' => true,
                    'mfa_type' => $mfaType,
                    'csrf_token' => csrf_token(),
                    'mfa_urls' => $this->mfaUrls($guard),
                ]);
            }

            return redirect()->route(mfa_route_name(
                'mfa.'.$mfaType,
                $guard,
            ));
        }

        ActivityLogger::log(
            'user.logged_in',
            $user->name.' logged in.',
            subject: $user,
        );

        if ($guard === 'customer' && $this->customerMfaRequired($request, $user)) {
            return $this->customerMfaResponse($request);
        }

        if ($user->requiresMfa()) {
            return $this->privilegedMfaResponse($request, $user);
        }

        return $this->loginRedirectResponse($request, $user);
    }

    private function googleLoginOnRoleGuard(Request $request, array $claims)
    {
        $user = User::whereRaw('LOWER(email) = ?', [$claims['email']])->first();

        $isNewAccount = false;
        if ($user === null) {
            $user = User::create([
                'name' => $claims['name'] ?: Str::before($claims['email'], '@'),
                'username' => $this->generateUniqueUsername($claims['name'] ?? $claims['email']),
                'email' => $claims['email'],
                'password' => Str::random(40),
                'role' => 'user',
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $isNewAccount = true;
        }

        $guard = $this->guardForRole($user->role);
        if ($guard === null) {
            return response()->json([
                'message' => "We couldn't verify that Google sign-in. Please try again.",
            ], 422);
        }

        if (Auth::guard($guard)->user()?->is($user)) {
            if ($guard === 'customer' && $this->customerMfaRequired($request, $user)) {
                return $this->customerMfaResponse($request);
            }
            if (
                $user->requiresMfa()
                && (
                    app(PrivilegedMfaService::class)->isReenrollmentRequired($user)
                    || ($user->hasMfaEnabled() && $this->privilegedMfaRequired($request, $user, $guard))
                )
            ) {
                return $this->privilegedMfaResponse($request, $user);
            }

            return response()->json([
                'message' => 'Logged in successfully!',
                'redirect' => $this->homePath($user),
            ]);
        }

        Auth::shouldUse($guard);
        GuardSessionState::runPreservingToken(
            $request,
            fn () => Auth::guard($guard)->login($user),
        );
        $request->session()->forget(GuardSessionState::key('view_as'));
        GuardSessionState::regenerateSession($request);
        $this->resetLumiPopoverSession($request);
        $this->recordSuccessfulLogin($request, $user);

        ActivityLogger::log(
            $isNewAccount ? 'user.registered' : 'user.logged_in',
            $isNewAccount
                ? $user->name.' registered via Google sign-in.'
                : $user->name.' logged in via Google sign-in.',
            subject: $user,
        );

        if ($guard === 'customer' && $this->customerMfaRequired($request, $user)) {
            return $this->customerMfaResponse($request);
        }

        if ($user->requiresMfa()) {
            return $this->privilegedMfaResponse($request, $user);
        }

        return response()->json([
            'message' => $isNewAccount ? 'Account created! Welcome to Side House.' : 'Logged in successfully!',
            'redirect' => $this->homePath($user),
        ]);
    }

    private function guardForRole(string $role): ?string
    {
        return match ($role) {
            'user' => 'customer',
            'admin', 'owner', 'staff' => $role,
            default => null,
        };
    }

    private function mfaUrls(string $guard): array
    {
        if ($guard === 'customer') {
            return [
                'setup_init' => role_route('customer.mfa.setup.init'),
                'enable' => role_route('customer.mfa.enable'),
                'verify' => role_route('customer.mfa.verify'),
            ];
        }

        return [
            'setup_init' => route(mfa_route_name('mfa.setup.init', $guard), absolute: false),
            'enable' => route(mfa_route_name('mfa.enable', $guard), absolute: false),
            'verify' => route(mfa_route_name('mfa.verify', $guard), absolute: false),
        ];
    }

    private function customerMfaRequired(Request $request, User $user): bool
    {
        if ($user->role !== 'user' || !$user->hasMfaEnabled()) {
            return false;
        }

        $passedAt = $request->session()->get(GuardSessionState::key(
            'mfa_passed_at',
            config('auth.multi_guard') ? 'customer' : null,
        ));
        $passedUserId = $request->session()->get(GuardSessionState::key(
            'mfa_passed_user_id',
            config('auth.multi_guard') ? 'customer' : null,
        ));

        return !(
            $passedAt
            && (int) $passedUserId === (int) $user->user_id
            && (now()->timestamp - $passedAt) < 60 * 60 * 8
        );
    }

    private function customerMfaResponse(Request $request)
    {
        if (!$request->expectsJson()) {
            return redirect()->to(role_route('customer.mfa.challenge'));
        }

        return response()->json([
            'mfa_required' => true,
            'mfa_type' => 'challenge',
            'csrf_token' => csrf_token(),
            'mfa_urls' => $this->mfaUrls('customer'),
        ]);
    }

    private function loginRedirectResponse(Request $request, User $user)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'redirect' => $this->homePath($user),
                'message' => 'Logged in successfully!',
            ]);
        }

        return redirect($this->homePath($user))->with('success', 'Logged in successfully!');
    }

    /**
     * Builds a unique username from a Google display name (falling back
     * to the email local-part if no name claim came through) — lowercased,
     * alnum/underscore only, with a numeric suffix appended if the base
     * is already taken.
     */
    private function generateUniqueUsername(string $seed): string
    {
        $base = Str::of($seed)
            ->before('@')
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_');

        if ($base->isEmpty()) {
            $base = Str::of('user');
        }

        $base = $base->limit(30, '');

        $username = (string) $base;
        $suffix = 1;

        while (User::where('username', $username)->exists()) {
            $suffix++;
            $username = (string) $base . $suffix;
        }

        return $username;
    }

    private function recordSuccessfulLogin(Request $request, User $user): void
    {
        app(PrivilegedMfaService::class)->afterSuccessfulLogin($user);
        $user->forceFill(['last_login_at' => now()])->save();
        $user->refresh();
        EnsureAccessVersion::stamp($request, $user);
    }

    private function resetLumiPopoverSession(Request $request): void
    {
        $request->session()->forget([
            GuardSessionState::key('lumi_login_popover_pending'),
            GuardSessionState::key('lumi_login_popover_shown'),
            GuardSessionState::key('lumi_suggestions_seen'),
        ]);
        $request->session()->put(GuardSessionState::key('lumi_login_popover_pending'), true);
    }

    private function homePath(User $user): string
    {
        return match ($user->role) {
            'owner' => route('owner.dashboard', absolute: false),
            'admin' => route('admin.dashboard', absolute: false),
            'staff' => route('staff.dashboard', absolute: false),
            default => route('user.dashboard', absolute: false),
        };
    }

    public function logout(Request $request)
    {
        $guard = GuardSessionState::currentGuard();
        $user = config('auth.multi_guard')
            ? Auth::guard($guard)->user()
            : Auth::user();

        if ($user) {
            ActivityLogger::log(
                'user.logged_out',
                $user->name . ' logged out.',
                subject: $user,
            );
        }

        if (config('auth.multi_guard')) {
            Auth::guard($guard)->logout();
            GuardSessionState::forgetGuard($request, $guard);
            GuardSessionState::regenerateSession($request);
        } else {
            $request->session()->forget('view_as');
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $loginRoute = config('auth.multi_guard') && $guard !== 'customer'
            ? $guard.'.login'
            : 'login';

        return redirect()->route($loginRoute)->with('success', 'Logged out successfully.');
    }
}
