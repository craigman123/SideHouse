<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

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

        if (Auth::attempt($credentials)) {
            $request->session()->regenerate();

            if (auth()->user()->isAdmin()) {
                ActivityLogger::log(
                    'admin.logged_in',
                    auth()->user()->name . ' logged in.',
                    subject: auth()->user(),
                );

                return redirect()->route('admin.dashboard')->with('success', 'Logged in successfully!');

            }

            ActivityLogger::log(
                'user.logged_in',
                auth()->user()->name . ' logged in.',
                subject: auth()->user(),
            );

            return redirect()->route('user.dashboard')->with('success', 'Logged in successfully!');
        }

        ActivityLogger::log(
            'user.login_failed',
            $credentials['username'] . ' failed to log in.',
            subject: auth()->user(),
        );

        return back()->withErrors(['username' => 'Invalid username or password.'])->onlyInput('username');
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
            'password' => 'required|min:6|confirmed',
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