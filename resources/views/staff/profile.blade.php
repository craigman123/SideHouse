@extends('staff.layouts.app')

@section('title', 'Staff Profile | Side House')
@section('page-title', 'Profile')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/shared/dashboard-base.css') . '?v=' . filemtime(public_path('css/shared/dashboard-base.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/shared/profile.css') . '?v=' . filemtime(public_path('css/shared/profile.css')) }}">
@endpush

@section('content')
    <div class="profile-page" id="staffProfilePage">
        @if (session('success'))<p class="profile-flash-success" role="status">{{ session('success') }}</p>@endif
        @if ($errors->any())<p class="profile-flash-error" role="alert">{{ $errors->first() }}</p>@endif

        <section class="profile-card">
            <header class="profile-header"><div class="profile-avatar-lg">{{ strtoupper(substr($user->name, 0, 1)) }}</div><div><h2>{{ $user->name }}</h2><p>{{ $user->email }}</p><span class="admin-role-badge">Staff</span></div></header>
            <form method="POST" action="{{ route('staff.profile.update') }}" id="staffProfileForm">
                @csrf @method('PUT')
                <div class="profile-grid">
                    <div class="profile-item"><label for="staffName">Name</label><input id="staffName" name="name" value="{{ old('name', $user->name) }}" required maxlength="255"></div>
                    <div class="profile-item"><label for="staffPhone">Phone number</label><input id="staffPhone" type="tel" name="phone_number" value="{{ old('phone_number', $user->phone_number) }}" autocomplete="tel"></div>
                    <div class="profile-item"><label for="staffEmail">Email</label><input id="staffEmail" type="email" value="{{ $user->email }}" readonly autocomplete="email"></div>
                </div>
                <div class="profile-actions"><button class="btn btn-primary" type="submit">Save profile</button></div>
            </form>
        </section>

        <section class="profile-card">
            <h2>Change password</h2>
            <form method="POST" action="{{ route('staff.profile.password') }}" id="staffPasswordForm">
                @csrf @method('PUT')
                <div class="profile-grid">
                    <div class="profile-item"><label for="staffCurrentPassword">Current password</label><input id="staffCurrentPassword" type="password" name="current_password" autocomplete="current-password" required></div>
                    <div class="profile-item"><label for="staffNewPassword">New password</label><input id="staffNewPassword" type="password" name="password" autocomplete="new-password" minlength="12" required></div>
                    <div class="profile-item"><label for="staffConfirmPassword">Confirm new password</label><input id="staffConfirmPassword" type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></div>
                </div>
                <div class="profile-actions"><button class="btn btn-primary" type="submit">Update password</button></div>
            </form>
        </section>

        @include('partials.management.privileged-mfa')

        <section class="profile-card staff-signout-card" aria-labelledby="staffSignoutTitle">
            <div>
                <h2 id="staffSignoutTitle">Sign out</h2>
                <p>End your staff session on this device.</p>
            </div>
            <form method="POST" action="{{ role_route('logout') }}" id="staffLogoutForm">
                @csrf
                <div class="profile-actions">
                    <button class="btn btn-danger btn-ghost-danger" type="button" id="staffLogoutButton">Log out</button>
                </div>
            </form>
        </section>
    </div>

    <div class="modal-overlay" id="staffLogoutModal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="staffLogoutModalTitle" aria-describedby="staffLogoutModalDescription" hidden>
        <div class="modal-box modal-box-sm">
            <div class="modal-header">
                <h3 id="staffLogoutModalTitle">Log out?</h3>
                <button type="button" class="modal-close btn-ghost-danger" id="staffLogoutModalClose" aria-label="Close">&times;</button>
            </div>
            <p class="modal-text" id="staffLogoutModalDescription">You'll need to sign in again to access the staff dashboard.</p>
            <div class="modal-actions">
                <button type="button" class="btn-secondary btn-ghost-cancel" id="staffLogoutModalCancel">Stay signed in</button>
                <button type="button" class="btn btn-danger btn-ghost-danger" id="staffLogoutConfirm">Log out</button>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/staff/staff-profile.js') . '?v=' . filemtime(public_path('js/staff/staff-profile.js')) }}" defer></script>
@endpush

