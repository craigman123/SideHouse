@extends('layouts.app')

@section('title', 'Courts | Side House')
@section('page-title', 'Courts')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/shared/dashboard-base.css') . '?v=' . filemtime(public_path('css/shared/dashboard-base.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/admin/admin-courts.css') }}">
@endpush

@section('content')
    @include('partials.management.courts')
@endsection

@push('scripts')
    <script>
        window.COURTS_STORE_URL = "{{ route('courts.store') }}";
        window.COURTS_UPDATE_URL_TEMPLATE = "{{ route('courts.update', ':id') }}";
        window.COURTS_DESTROY_URL_TEMPLATE = "{{ route('courts.destroy', ':id') }}";

        // Fallback toast helper — only defines itself if app.js doesn't already provide one
        if (typeof showToast !== 'function') {
            window.showToast = function (message, type = 'success') {
                let container = document.querySelector('.toast-container');
                if (!container) {
                    container = document.createElement('div');
                    container.className = 'toast-container';
                    document.body.appendChild(container);
                }

                const toast = document.createElement('div');
                toast.className = `toast toast-${type}`;
                toast.innerHTML = `<span class="toast-message">${message}</span>
                    <button type="button" class="toast-close" onclick="this.parentElement.remove()">&times;</button>`;

                container.appendChild(toast);

                setTimeout(() => {
                    toast.classList.add('toast-out');
                    setTimeout(() => toast.remove(), 300);
                }, 4000);
            };
        }
    </script>
    <script src="{{ asset('js/config/courts.js') }}" defer></script>
@endpush
