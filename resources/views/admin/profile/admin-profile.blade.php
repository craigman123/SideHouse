@extends('layouts.app')

@section('title', 'My Profile | Side House')
@section('page-title', 'My Profile')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/shared/dashboard-base.css') . '?v=' . filemtime(public_path('css/shared/dashboard-base.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/shared/profile.css') . '?v=' . filemtime(public_path('css/shared/profile.css')) }}">
@endpush

@section('content')
    @include('partials.management.profile')
@endsection

@push('scripts')
    <script src="{{ asset('js/user/profile.js') }}" defer></script>
    <script>
        // Logout confirmation modal — same self-contained pattern as the
        // user profile page, kept here rather than in profile.js since it
        // only needs to open/close one modal and submit one form.
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
