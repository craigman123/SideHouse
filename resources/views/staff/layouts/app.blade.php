<!doctype html>
<html lang="en" class="staff-layout-shell">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Staff | Side House')</title>
    <script src="{{ asset('js/guest/theme-toggle.js') . '?v=' . filemtime(public_path('js/guest/theme-toggle.js')) }}"></script>
    <link rel="stylesheet" href="{{ asset('css/tokens.css') . '?v=' . filemtime(public_path('css/tokens.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/fonts.css') . '?v=' . filemtime(public_path('css/fonts.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') . '?v=' . filemtime(public_path('css/app.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/guest/theme-toggle.css') . '?v=' . filemtime(public_path('css/guest/theme-toggle.css')) }}">
    <link rel="icon" type="image/png" href="{{ asset('images/tab_icon.png') . '?v=' . filemtime(public_path('images/tab_icon.png')) }}">
    <script src="{{ asset('js/app.js') . '?v=' . filemtime(public_path('js/app.js')) }}" defer></script>
    @stack('styles')
</head>
<body>
    @php($staffAccess = app(\App\Services\UserAccessService::class))
    <div class="staff-shell">
        <aside class="staff-sidebar" aria-label="Staff navigation">
            <a class="staff-brand" href="{{ route('staff.dashboard') }}">
                <img src="{{ asset('images/logo.png') . '?v=' . filemtime(public_path('images/logo.png')) }}" alt="">
                <span>Side House</span>
            </a>
            <nav class="staff-nav">
                <span class="staff-nav-label">Staff area</span>
                @include('staff.partials.navigation-links', ['staffAccess' => $staffAccess])
            </nav>
            <div class="staff-sidebar-footer">
                <a class="staff-user-block" href="{{ route('staff.profile') }}">
                    <span class="staff-user-avatar" aria-hidden="true">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
                    <span class="staff-user-block-copy"><strong>{{ auth()->user()->name }}</strong><small>Profile</small></span>
                </a>
                <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Log out</button></form>
            </div>
        </aside>

        <main class="staff-main">
            <header class="staff-topbar">
                <div class="staff-mobile-menu">
                    <details>
                        <summary aria-label="Open staff navigation">Menu</summary>
                        <div class="staff-mobile-drawer">
                            <nav aria-label="Staff navigation">
                                <span class="staff-nav-label">Staff area</span>
                                @include('staff.partials.navigation-links', ['staffAccess' => $staffAccess])
                            </nav>
                            <div class="staff-mobile-user-block">
                                <a class="staff-user-block" href="{{ route('staff.profile') }}">
                                    <span class="staff-user-avatar" aria-hidden="true">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
                                    <span class="staff-user-block-copy"><strong>{{ auth()->user()->name }}</strong><small>Profile</small></span>
                                </a>
                                <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Log out</button></form>
                            </div>
                        </div>
                    </details>
                    <a class="staff-mobile-brand" href="{{ route('staff.dashboard') }}">Side House</a>
                </div>
                <h1>@yield('page-title', 'Staff')</h1>
                <div class="staff-topbar-actions">
                    @include('partials.guest.theme-toggle', ['placement' => 'toolbar'])
                    <span>{{ now()->format('F d, Y') }}</span>
                </div>
            </header>
            <div class="staff-content">@yield('content')</div>
        </main>
    </div>
    @stack('scripts')
</body>
</html>
