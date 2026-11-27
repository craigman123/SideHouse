<!DOCTYPE html>
<html lang="en" class="{{ $shell['html_class'] }}" data-shell-area="{{ $shell['area'] }}">
<head>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Side House')</title>
    <script src="{{ asset('js/shared/dashboard-shell.js') . '?v=' . filemtime(public_path('js/shared/dashboard-shell.js')) }}"></script>
    <script src="{{ asset('js/guest/theme-toggle.js') . '?v=' . filemtime(public_path('js/guest/theme-toggle.js')) }}"></script>
    <link rel="stylesheet" href="{{ asset('css/tokens.css') . '?v=' . filemtime(public_path('css/tokens.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/fonts.css') . '?v=' . filemtime(public_path('css/fonts.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') . '?v=' . filemtime(public_path('css/app.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/shared/card-icons.css') . '?v=' . filemtime(public_path('css/shared/card-icons.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/auth/mfa-modal.css') . '?v=' . filemtime(public_path('css/auth/mfa-modal.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/guest/theme-toggle.css') . '?v=' . filemtime(public_path('css/guest/theme-toggle.css')) }}">
    <link rel="icon" type="image/png" href="{{ asset('images/tab_icon.png') . '?v=' . filemtime(public_path('images/tab_icon.png')) }}">
    <script src="{{ asset('js/app.js') . '?v=' . filemtime(public_path('js/app.js')) }}" defer></script>
    <script src="{{ asset('js/shared/card-icons.js') . '?v=' . filemtime(public_path('js/shared/card-icons.js')) }}" defer></script>
    <script src="{{ asset('js/config/back-guard.js') . '?v=' . filemtime(public_path('js/config/back-guard.js')) }}" defer></script>
    @stack('styles')
    <link rel="stylesheet" href="{{ asset('css/shared/dashboard-shell.css') . '?v=' . filemtime(public_path('css/shared/dashboard-shell.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/shared/page-glow.css') . '?v=' . filemtime(public_path('css/shared/page-glow.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/shared/switch-access.css') . '?v=' . filemtime(public_path('css/shared/switch-access.css')) }}">
    <script src="{{ asset('js/shared/switch-access.js') . '?v=' . filemtime(public_path('js/shared/switch-access.js')) }}" defer></script>
</head>
<body>
    @include('partials.card-icon-sprite')
    <svg class="dashboard-shell-icons" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" hidden>
        <symbol id="shell-icon-overview" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></symbol>
        <symbol id="shell-icon-bookings" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M8 15l2 2 5-5"/></symbol>
        <symbol id="shell-icon-courts" viewBox="0 0 24 24"><rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="8" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/><rect x="13" y="13" width="8" height="8" rx="1"/></symbol>
        <symbol id="shell-icon-configuration" viewBox="0 0 24 24"><path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z"/><path d="m19.4 15 .1.1a1.7 1.7 0 0 1-2.4 2.4l-.1-.1a1.7 1.7 0 0 0-2.9 1.2v.2a1.7 1.7 0 0 1-3.4 0v-.2a1.7 1.7 0 0 0-2.9-1.2l-.1.1a1.7 1.7 0 1 1-2.4-2.4l.1-.1A1.7 1.7 0 0 0 4.2 12h-.2a1.7 1.7 0 0 1 0-3.4h.2a1.7 1.7 0 0 0 1.2-2.9l-.1-.1a1.7 1.7 0 1 1 2.4-2.4l.1.1a1.7 1.7 0 0 0 2.9-1.2v-.2a1.7 1.7 0 0 1 3.4 0v.2a1.7 1.7 0 0 0 2.9 1.2l.1-.1a1.7 1.7 0 1 1 2.4 2.4l-.1.1a1.7 1.7 0 0 0 1.2 2.9h.2a1.7 1.7 0 0 1 0 3.4h-.2a1.7 1.7 0 0 0-1.2 2.9Z"/></symbol>
        <symbol id="shell-icon-widgets" viewBox="0 0 24 24"><path d="M18 4H6c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2M6 18V6h12v12z" fill="currentColor"/><path d="M8 14h8v2H8zm0-6h4v4H8z" fill="currentColor"/></symbol>
        <symbol id="shell-icon-profile" viewBox="0 0 24 24"><circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/></symbol>
        <symbol id="shell-icon-equipment" viewBox="0 0 24 24"><path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5M3 16l9 5 9-5M12 13v8"/></symbol>
        <symbol id="shell-icon-customers" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></symbol>
        <symbol id="shell-icon-memberships" viewBox="0 0 24 24"><path d="M12 11c1.71 0 3-1.29 3-3s-1.29-3-3-3-3 1.29-3 3 1.29 3 3 3m0-4c.6 0 1 .4 1 1s-.4 1-1 1-1-.4-1-1 .4-1 1-1m1 5h-2c-2.76 0-5 2.24-5 5v.5c0 .83.67 1.5 1.5 1.5h9c.83 0 1.5-.67 1.5-1.5V17c0-2.76-2.24-5-5-5m-5 5c0-1.65 1.35-3 3-3h2c1.65 0 3 1.35 3 3zm-1.5-6c.47 0 .9-.12 1.27-.33a5.03 5.03 0 0 1-.42-4.52C7.09 6.06 6.8 6 6.5 6 5.06 6 4 7.06 4 8.5S5.06 11 6.5 11m-.39 1H5.5C3.57 12 2 13.57 2 15.5v1c0 .28.22.5.5.5H4c0-1.96.81-3.73 2.11-5m11.39-1c1.44 0 2.5-1.06 2.5-2.5S18.94 6 17.5 6c-.31 0-.59.06-.85.15a5.03 5.03 0 0 1-.42 4.52c.37.21.79.33 1.27.33m1 1h-.61A6.97 6.97 0 0 1 20 17h1.5c.28 0 .5-.22.5-.5v-1c0-1.93-1.57-3.5-3.5-3.5" fill="currentColor" stroke="none"/></symbol>
        <symbol id="shell-icon-access" viewBox="0 0 24 24"><circle cx="9" cy="8" r="4"/><path d="M2 21v-1a7 7 0 0 1 14 0v1M19 8v6M16 11h6"/></symbol>
        <symbol id="shell-icon-announcements" viewBox="0 0 24 24"><path d="m3 11 18-5-5 18-4-8-9-5Z"/><path d="M12 16 3 11m9 5 2-6"/></symbol>
        <symbol id="shell-icon-reports" viewBox="0 0 24 24"><path d="M3 3v18h18M18 17V9M13 17V5M8 17v-3"/></symbol>
        <symbol id="shell-icon-activity" viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></symbol>
    </svg>

    <aside class="sidebar" id="adminSidebar" aria-label="{{ $shell['area_label'] }} navigation">
        <div class="sidebar-logo">
            <img src="{{ asset('images/logo.png') . '?v=' . filemtime(public_path('images/logo.png')) }}" alt="Side House" class="logo-img">
            <span class="sidebar-brand">Side House</span>
        </div>
        @php($shellAccess = app(\App\Services\UserAccessService::class))
        @php($visibleMenu = collect($shell['menu'])->filter(fn ($item) => empty($item['permission']) || $shellAccess->allows(auth()->user(), $item['permission'])))
        <nav class="sidebar-nav">
            @foreach ($visibleMenu->groupBy('section') as $section => $items)
                <label class="nav-label">{{ $section }}</label>
                @foreach ($items as $item)
                    @php($menuUrl = in_array($item['route'], ['admin.dashboard', 'admin.user-access.page'], true) ? role_route($item['route']) : route($item['route']))
                    <a href="{{ $menuUrl }}" @class(['active' => request()->routeIs($item['active'] ?? $item['route'])]) aria-label="{{ $item['label'] }}" title="{{ $item['label'] }}">
                        <svg class="sidebar-nav-icon" @if(in_array($item['icon'], ['bookings', 'configuration', 'customers', 'access'], true)) data-sidebar-icon="{{ $item['icon'] }}" @endif viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#shell-icon-{{ $item['icon'] }}"></use></svg>
                        <span class="admin-nav-text {{ ($item['icon'] ?? '') === 'equipment' ? 'equipment-availability-nav-text' : '' }}">{{ $item['label'] }}</span>
                    </a>
                @endforeach
            @endforeach
        </nav>

        @include('partials.switch-access.panel')

        <div class="sidebar-profile">
            <a href="{{ route($shell['profile_route']) }}" type="button" class="profile-trigger admin-profile-trigger" @if($shell['area'] !== 'staff') onclick="toggleProfileDropdown()" @endif aria-label="{{ auth()->user()->name ?? 'User' }}, {{ ucfirst($shell['area']) }} profile" title="Profile">
                <span class="profile-avatar">{{ strtoupper(substr(auth()->user()->name ?? 'A', 0, 1)) }}</span>
                <span class="profile-name">{{ auth()->user()->name ?? 'User' }}<span class="visually-hidden">, {{ ucfirst(auth()->user()->role ?? $shell['area']) }}</span></span>
            </a>
            <button type="button" id="adminSidebarCollapseToggle" class="admin-sidebar-collapse-toggle" aria-label="Collapse sidebar" aria-expanded="true" aria-controls="adminSidebar" title="Collapse sidebar">
                <svg data-admin-sidebar-icon="collapse" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
                <svg data-admin-sidebar-icon="expand" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" hidden><path d="M19 12H5m6 6-6-6 6-6"/></svg>
            </button>
        </div>
        <div class="sidebar-footer">&copy; {{ date('Y') }} Side House</div>
    </aside>

    <button type="button" id="adminSidebarBackdrop" class="admin-sidebar-backdrop" data-admin-sidebar-backdrop aria-hidden="true" tabindex="-1" hidden></button>
    <div class="main">
        @include('partials.switch-access.banner')
        <header class="topbar">
            <h1>@yield('page-title', 'Dashboard')</h1>
            <div class="topbar-actions">
                <button type="button" class="admin-mobile-menu-toggle" data-admin-mobile-menu-toggle aria-label="Open navigation" aria-expanded="false" aria-controls="adminSidebar" title="Open navigation">
                    <svg data-admin-menu-icon="open" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                    <svg data-admin-menu-icon="close" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" hidden><path d="m6 6 12 12M18 6 6 18"/></svg>
                </button>
                <div class="date">{{ now()->format('F d, Y') }}</div>
                @include('partials.guest.theme-toggle', ['placement' => 'toolbar'])
            </div>
        </header>
        <main class="content">@yield('content')</main>
    </div>

    @php($realAdminView = auth()->user() ? app(\App\Services\UserAccessService::class)->isRealAdmin(auth()->user(), request()) : false)
    @if ($realAdminView)
        @include('partials.system-monitor-panel')
        <script src="{{ asset('js/shared/system-monitor.js') . '?v=' . filemtime(public_path('js/shared/system-monitor.js')) }}" defer></script>
    @endif

    <div id="logout-confirm-modal" class="mfa-modal hidden">
        <div class="mfa-modal-overlay"></div>
        <div class="mfa-modal-content">
            <div class="mfa-modal-header"><h2>Leave dashboard?</h2></div>
            <div class="mfa-modal-body"><p>Going back will log you out. Do you want to continue?</p><button id="logout-confirm-no" class="mfa-btn btn-ghost-cancel">Stay</button><button id="logout-confirm-yes" class="mfa-modal-ghost-btn btn-ghost-danger">Log out</button></div>
        </div>
    </div>
    <form id="logout-form" class="dashboard-shell-logout-form" method="POST" action="{{ role_route('logout') }}">@csrf</form>
    @stack('scripts')
</body>
</html>
