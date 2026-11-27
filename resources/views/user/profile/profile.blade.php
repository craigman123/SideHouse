@extends($profileLayout)

@section('title', $profileTitle ?? 'Profile | Side House')
@section('page-title', 'My Profile')
@section('page-class', 'user-profile-page')

@push('styles')
    @foreach ($profileStylesheets as $stylesheet)
        <link rel="stylesheet" href="{{ asset($stylesheet) }}?v={{ filemtime(public_path($stylesheet)) }}">
    @endforeach
@endpush

@section('content')@include('partials.profile.shared-content')@endsection

@push('scripts')
    <script src="{{ asset('js/user/profile.js') }}?v={{ filemtime(public_path('js/user/profile.js')) }}" defer></script>
    @foreach ($profileScripts as $script)
        <script src="{{ asset($script) }}?v={{ filemtime(public_path($script)) }}" defer></script>
    @endforeach
@endpush
