@extends('owner.layouts.app')
@section('title', 'My Profile | Owner | Side House')
@section('page-title', 'My Profile')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/owner/owner-base.css') . '?v=' . filemtime(public_path('css/owner/owner-base.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/owner/owner-profile.css') . '?v=' . filemtime(public_path('css/owner/owner-profile.css')) }}">
@endpush
@section('content')
    @include('partials.management.profile')
@endsection
@push('scripts')
    <script src="{{ asset('js/user/profile.js') }}" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const logoutModal = document.getElementById('logoutModal');
            const logoutBtn = document.getElementById('logoutBtn');
            const logoutForm = document.getElementById('logoutForm');
            const close = document.getElementById('logoutModalClose');
            const cancel = document.getElementById('logoutModalCancel');
            const submit = document.getElementById('logoutConfirmSubmit');
            if (!logoutModal || !logoutBtn || !logoutForm) return;
            const open = () => logoutModal.classList.add('open');
            const dismiss = () => logoutModal.classList.remove('open');
            logoutBtn.addEventListener('click', open);
            close?.addEventListener('click', dismiss);
            cancel?.addEventListener('click', dismiss);
            logoutModal.addEventListener('click', (event) => { if (event.target === logoutModal) dismiss(); });
            submit?.addEventListener('click', () => logoutForm.submit());
        });
    </script>
@endpush
