<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\GoogleIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required',
        ]);

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

        $request->session()->regenerate();
        $user = auth()->user();

        if ($user->isAdmin()) {
            ActivityLogger::log(
                'admin.logged_in',
                $user->name . ' logged in.',
                subject: $user,
            );

            // Tell the frontend to show the correct MFA modal
            if ($request->expectsJson()) {
                return response()->json([
                    'mfa_required' => true,
                    'mfa_type'     => $user->hasMfaEnabled() ? 'challenge' : 'setup',
                ]);
            }

            // Fallback for normal form submit
            return $user->hasMfaEnabled()
                ? redirect()->route('mfa.challenge')
                : redirect()->route('mfa.setup');
        }

        // Normal user
        ActivityLogger::log(
            'user.logged_in',
            $user->name . ' logged in.',
            subject: $user,
        );

        if ($request->expectsJson()) {
            return response()->json([
                'redirect' => route('user.dashboard'),
                'message'  => 'Logged in successfully!',
            ]);
        }

        return redirect()->route('user.dashboard')->with('success', 'Logged in successfully!');
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

        Auth::login($user);

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

        $user = User::where('email', $claims['email'])->first();
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

        Auth::login($user);
        $request->session()->regenerate();

        ActivityLogger::log(
            $isNewAccount ? 'user.registered' : 'user.logged_in',
            $isNewAccount
                ? $user->name . ' registered via Google sign-in.'
                : $user->name . ' logged in via Google sign-in.',
            subject: $user,
        );

        return response()->json([
            'message'  => $isNewAccount ? 'Account created! Welcome to Side House.' : 'Logged in successfully!',
            'redirect' => $user->isAdmin() ? route('admin.dashboard') : route('user.dashboard'),
        ]);
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

    public function logout(Request $request)
    {
        $user = Auth::user();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user) {
            ActivityLogger::log(
                'user.logged_out',
                $user->name . ' logged out.',
                subject: $user,
            );
        }

        return redirect()->route('login')->with('success', 'Logged out successfully.');
    }
}