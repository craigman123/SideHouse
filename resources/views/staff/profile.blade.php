@extends('staff.layouts.app')

@section('title', 'Staff Profile | Side House')
@section('page-title', 'Profile')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/staff/staff-layout.css') . '?v=' . filemtime(public_path('css/staff/staff-layout.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/staff/staff-profile.css') . '?v=' . filemtime(public_path('css/staff/staff-profile.css')) }}">
@endpush

@section('content')
    <section class="staff-profile-page" id="staffProfilePage">
        @if (session('success'))<p class="staff-profile-message is-success" role="status">{{ session('success') }}</p>@endif
        @if ($errors->any())<p class="staff-profile-message is-error" role="alert">{{ $errors->first() }}</p>@endif

        <section class="staff-profile-card">
            <div class="staff-profile-heading"><div class="staff-profile-avatar">{{ strtoupper(substr($user->name, 0, 1)) }}</div><div><h2>{{ $user->name }}</h2><p>{{ $user->email }}</p></div></div>
            <form method="POST" action="{{ route('staff.profile.update') }}" class="staff-profile-form">
                @csrf @method('PUT')
                <label>Name<input name="name" value="{{ old('name', $user->name) }}" required maxlength="255"></label>
                <label>Phone number<input type="tel" name="phone_number" value="{{ old('phone_number', $user->phone_number) }}" autocomplete="tel"></label>
                <button type="submit">Save profile</button>
            </form>
        </section>

        <section class="staff-profile-card">
            <h2>Change password</h2>
            <form method="POST" action="{{ route('staff.profile.password') }}" class="staff-profile-form">
                @csrf @method('PUT')
                <label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label>
                <label>New password<input type="password" name="password" autocomplete="new-password" minlength="12" required></label>
                <label>Confirm new password<input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></label>
                <button type="submit">Update password</button>
            </form>
        </section>

        <section class="staff-profile-card staff-mfa-card">
            <div><h2>Multi-factor authentication</h2><p>Status: <strong>{{ $mfaEnabled ? 'Enabled' : 'Pending setup' }}</strong></p><p>MFA is required for staff access and cannot be disabled here.</p></div>
            @if ($mfaEnabled)
                <form method="POST" action="{{ route('staff.profile.recovery-codes') }}" class="staff-profile-form">
                    @csrf
                    <label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label>
                    <button type="submit">Regenerate recovery codes</button>
                </form>
            @endif
            @if (session('recovery_codes'))
                <div class="staff-recovery-codes" role="status"><h3>Save your new recovery codes</h3><ul>@foreach (session('recovery_codes') as $code)<li>{{ $code }}</li>@endforeach</ul></div>
            @endif
        </section>
    </section>
@endsection

