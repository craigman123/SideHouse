@extends('layouts.user')

@section('title', 'Profile | Side House')
@section('page-title', 'My Profile')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/user-dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/profile.css') }}">
@endpush

@section('content')

    <div
        id="flash-data"
        data-success="{{ session('success') }}"
        data-error="{{ $errors->hasAny(['name', 'username', 'email']) ? $errors->first() : '' }}"
        data-delete-error="{{ $errors->first('confirmation') }}"
    ></div>

    <div class="profile-page">
        <div class="profile-card">

            <div class="profile-header">
                <div class="profile-avatar-lg">
                    {{ strtoupper(substr($user->name, 0, 1)) }}
                </div>

                <div>
                    <h2>{{ $user->name }}</h2>
                    <p class="profile-email-row">
                        {{ $user->email }}
                        @if ($user->email_verified_at)
                            <span class="verify-badge verify-badge-yes">Verified</span>
                        @else
                            <span class="verify-badge verify-badge-no">Unverified</span>
                        @endif
                    </p>

                    <p class="profile-email-row">
                        @if ($user->membership_verified_at)
                            <span class="verify-badge membership-badge-yes">Member</span>
                        @else
                            <span class="verify-badge membership-badge-no">Not a member</span>
                        @endif
                    </p>
                </div>

                <form method="POST" action="{{ route('logout') }}" class="logout-form" id="logoutForm">
                    @csrf
                    <button type="button" class="btn btn-danger" id="logoutBtn">Log Out</button>
                </form>
            </div>

            <form method="POST" action="{{ route('user.profile.update') }}" id="profileForm">
                @csrf
                @method('PUT')

                <div class="profile-grid">
                    <div class="profile-item">
                        <label for="name">Name</label>
                        <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" readonly>
                    </div>

                    <div class="profile-item">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" value="{{ old('username', $user->username) }}" readonly>
                    </div>

                    <div class="profile-item">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" readonly>
                    </div>

                    <div class="profile-item">
                        <label>Role</label>
                        <input type="text" value="{{ ucfirst($user->role) }}" readonly disabled>
                    </div>

                    <div class="profile-item">
                        <label>Member Since</label>
                        <input type="text" value="{{ $user->created_at->format('F d, Y') }}" readonly disabled>
                    </div>

                    <div class="profile-item">
                        <label>User ID</label>
                        <input type="text" value="{{ $user->user_id }}" readonly disabled>
                    </div>
                </div>

                <div class="profile-actions">
                    <button type="button" class="btn btn-secondary" id="cancelEditBtn" hidden>Cancel</button>
                    <button type="button" class="btn btn-primary" id="editProfileBtn">Edit Profile</button>
                    <button type="submit" class="btn btn-primary" id="saveProfileBtn" hidden>Save Changes</button>
                </div>
            </form>

            <div class="profile-section">
                <h3 class="profile-section-title">Preferences</h3>

                <div class="preference-row">
                    <div>
                        <p class="preference-label">Dark Mode</p>
                        <p class="preference-sub">Coming soon — the app is dark-themed by default for now.</p>
                    </div>
                    <label class="switch switch-disabled">
                        <input type="checkbox" disabled>
                        <span class="switch-slider"></span>
                    </label>
                </div>
            </div>

        </div>

        <div class="danger-zone">
            <h3 class="danger-zone-title">Danger Zone</h3>
            <div class="danger-zone-row">
                <div>
                    <p class="danger-zone-label">Delete Account</p>
                    <p class="danger-zone-sub">Permanently delete your account. This can't be undone.</p>
                </div>
                <button type="button" class="btn btn-danger" id="deleteAccountBtn">Delete Account</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="logoutModal">
        <div class="modal-box modal-box-sm">
            <div class="modal-header">
                <h3>Log out?</h3>
                <button type="button" class="modal-close" id="logoutModalClose" aria-label="Close">&times;</button>
            </div>

            <p class="modal-text">You'll need to sign in again to access your account.</p>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="logoutModalCancel">Cancel</button>
                <button type="button" class="btn btn-danger" id="logoutConfirmSubmit">Log Out</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="deleteAccountModal">
        <div class="modal-box modal-box-sm">
            <div class="modal-header">
                <h3>Delete your account?</h3>
                <button type="button" class="modal-close" id="deleteModalClose" aria-label="Close">&times;</button>
            </div>

            <p class="modal-text">
                This permanently deletes your account and can't be undone. Your past bookings will stay on
                record but your current and future bookings will be canceled and your current bookings will be availed 
                to other players. You will <strong>lose access to your account and all its data.</strong>
            </p>
            <p class="modal-text">
                Type <strong>DELETE MY ACCOUNT</strong> below to confirm.
            </p>

            <form method="POST" action="{{ route('user.profile.destroy') }}" id="deleteAccountForm">
                @csrf
                @method('DELETE')
                <input
                    type="text"
                    name="confirmation"
                    id="deleteConfirmInput"
                    class="delete-confirm-input"
                    placeholder="DELETE MY ACCOUNT"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                >
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="deleteModalCancel">Cancel</button>
                    <button type="submit" class="btn btn-danger" id="deleteConfirmSubmit" disabled>Delete Account</button>
                </div>
            </form>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

@endsection

@push('scripts')
    <script src="{{ asset('js/profile.js') }}" defer></script>
    <script>
        // Logout confirmation modal — kept self-contained here rather than
        // added to profile.js, since this only needs to open/close one
        // modal and submit one form; no need to touch the rest of that
        // file's (unseen-here) edit/delete-account wiring.
        document.addEventListener('DOMContentLoaded', () => {
            const logoutModal = document.getElementById('logoutModal');
            const logoutBtn = document.getElementById('logoutBtn');
            const logoutForm = document.getElementById('logoutForm');
            const logoutModalClose = document.getElementById('logoutModalClose');
            const logoutModalCancel = document.getElementById('logoutModalCancel');
            const logoutConfirmSubmit = document.getElementById('logoutConfirmSubmit');

            if (!logoutModal || !logoutBtn || !logoutForm) return;

            const openLogoutModal = () => logoutModal.classList.add('open');
            const closeLogoutModal = () => logoutModal.classList.remove('open');

            logoutBtn.addEventListener('click', openLogoutModal);
            if (logoutModalClose) logoutModalClose.addEventListener('click', closeLogoutModal);
            if (logoutModalCancel) logoutModalCancel.addEventListener('click', closeLogoutModal);

            logoutModal.addEventListener('click', (e) => {
                if (e.target === logoutModal) closeLogoutModal();
            });

            if (logoutConfirmSubmit) {
                logoutConfirmSubmit.addEventListener('click', () => {
                    logoutForm.submit();
                });
            }
        });
    </script>
@endpush