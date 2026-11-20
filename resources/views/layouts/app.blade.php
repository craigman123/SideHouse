<!DOCTYPE html>
<html lang="en" class="admin-layout-shell">
<head>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Court Booking System')</title>
    <script src="{{ asset('js/admin/admin-shell.js') }}"></script>
    <script src="{{ asset('js/guest/theme-toggle.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}">
    <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/auth/mfa-modal.css') }}">
    <link rel="stylesheet" href="{{ asset('css/guest/theme-toggle.css') }}">
    <link rel="icon" type="image/png" href="{{ asset('images/tab_icon.png') }}">
    <script src="{{ asset('js/app.js') }}" defer></script>
    <script src="{{ asset('js/config/back-guard.js') }}" defer></script>
    @stack('styles')
    <link rel="stylesheet" href="{{ asset('css/admin/admin-shell.css') }}">
</head>
<body>

    <aside class="sidebar" id="adminSidebar">
        <div class="sidebar-logo">
            <img src="{{ asset('images/logo.png') }}" alt="Side House" class="logo-img">
            <span class="sidebar-brand">Side House</span>
        </div>

        <nav class="sidebar-nav">
            <label class="nav-label">Main</label>
            <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}" aria-label="Overview" title="Overview">
                <svg class="sidebar-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
                <span class="admin-nav-text">Overview</span>
            </a>

            <label class="nav-label">Management</label>
            <a href="{{ route('bookings.index') }}" class="{{ request()->routeIs('bookings.*') ? 'active' : '' }}" aria-label="Bookings" title="Bookings">
                <svg class="sidebar-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M8 15l2 2 5-5"/></svg>
                <span class="admin-nav-text">Bookings</span>
            </a>
            <a href="{{ route('courts.index') }}" class="{{ request()->routeIs('courts.*') ? 'active' : '' }}" aria-label="Courts" title="Courts">
                <svg class="sidebar-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="8" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/><rect x="13" y="13" width="8" height="8" rx="1"/></svg>
                <span class="admin-nav-text">Courts</span>
            </a>
            <a href="{{ route('admin.configuration.index') }}" class="{{ request()->routeIs('admin.configuration.*') ? 'active' : '' }}" aria-label="Configuration" title="Configuration">
                <svg class="sidebar-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z"/><path d="m19.4 15 .1.1a1.7 1.7 0 0 1-2.4 2.4l-.1-.1a1.7 1.7 0 0 0-2.9 1.2v.2a1.7 1.7 0 0 1-3.4 0v-.2a1.7 1.7 0 0 0-2.9-1.2l-.1.1a1.7 1.7 0 1 1-2.4-2.4l.1-.1A1.7 1.7 0 0 0 4.2 12h-.2a1.7 1.7 0 0 1 0-3.4h.2a1.7 1.7 0 0 0 1.2-2.9l-.1-.1a1.7 1.7 0 1 1 2.4-2.4l.1.1a1.7 1.7 0 0 0 2.9-1.2v-.2a1.7 1.7 0 0 1 3.4 0v.2a1.7 1.7 0 0 0 2.9 1.2l.1-.1a1.7 1.7 0 1 1 2.4 2.4l-.1.1a1.7 1.7 0 0 0 1.2 2.9h.2a1.7 1.7 0 0 1 0 3.4h-.2a1.7 1.7 0 0 0-1.2 2.9Z"/></svg>
                <span class="admin-nav-text">Configuration</span>
            </a>
            <a href="{{ route('admin.profile') }}" class="{{ request()->routeIs('admin.profile') ? 'active' : '' }}" aria-label="Profile" title="Profile">
                <svg class="sidebar-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/></svg>
                <span class="admin-nav-text">Profile</span>
            </a>
            <a href="{{ route('admin.equipment.availability') }}" class="{{ request()->routeIs('admin.equipment.availability') ? 'active' : '' }}" aria-label="Equipment Availability" title="Equipment Availability">
                <svg class="sidebar-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5M3 16l9 5 9-5M12 13v8"/></svg>
                <span class="admin-nav-text">Equipment Availability</span>
            </a>

            <label class="nav-label">Users</label>
            <a href="{{ route('admin.customers.index') }}" class="{{ request()->routeIs('admin.customers.*') ? 'active' : '' }}" aria-label="Customers" title="Customers">
                <svg class="sidebar-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <span class="admin-nav-text">Customers</span>
            </a>
            <a href="{{ route('admin.announcements.index') }}" class="{{ request()->routeIs('admin.announcements.*') ? 'active' : '' }}" aria-label="Announcements" title="Announcements">
                <svg class="sidebar-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 11 18-5-5 18-4-8-9-5Z"/><path d="M12 16 3 11m9 5 2-6"/></svg>
                <span class="admin-nav-text">Announcements</span>
            </a>

            <label class="nav-label">Audit Logs</label>
            <a href="{{ route('admin.reports.index') }}" class="{{ request()->routeIs('admin.reports.*') ? 'active' : '' }}" aria-label="Reports" title="Reports">
                <svg class="sidebar-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v18h18"/><path d="M18 17V9M13 17V5M8 17v-3"/></svg>
                <span class="admin-nav-text">Reports</span>
            </a>
            <a href="{{ route('activity_logs.index') }}" class="{{ request()->routeIs('activity_logs.*') ? 'active' : '' }}" aria-label="Activity Logs" title="Activity Logs">
                <svg class="sidebar-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
                <span class="admin-nav-text">Activity Logs</span>
            </a>
        </nav>

        <div class="sidebar-profile">
            <a href="{{ route('admin.profile') }}" type="button" class="profile-trigger admin-profile-trigger" onclick="toggleProfileDropdown()" aria-label="Profile" title="Profile">
                <span class="profile-avatar">{{ strtoupper(substr(auth()->user()->name ?? 'A', 0, 1)) }}</span>
                <span class="profile-name">{{ auth()->user()->name ?? 'Admin' }}</span>
            </a>
            <button type="button" id="adminSidebarCollapseToggle" class="admin-sidebar-collapse-toggle" aria-label="Collapse sidebar" aria-expanded="true" aria-controls="adminSidebar" title="Collapse sidebar">
                <svg data-admin-sidebar-icon="collapse" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
                <svg data-admin-sidebar-icon="expand" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" hidden><path d="M19 12H5m6 6-6-6 6-6"/></svg>
            </button>
        </div>

        <div class="sidebar-footer">&copy; {{ date('Y') }} Court Booking</div>
    </aside>

    <button type="button" id="adminSidebarBackdrop" class="admin-sidebar-backdrop" data-admin-sidebar-backdrop aria-hidden="true" tabindex="-1" hidden></button>

    <div class="main">
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

        <main class="content">
            @yield('content')
        </main>
    </div>

    <div id="logout-confirm-modal" class="mfa-modal hidden">
        <div class="mfa-modal-overlay"></div>
        <div class="mfa-modal-content">
            <div class="mfa-modal-header">
                <h2>Leave dashboard?</h2>
            </div>
            <div class="mfa-modal-body">
                <p>Going back will log you out. Do you want to continue?</p>
                <button id="logout-confirm-no" class="mfa-btn">Stay</button>
                <button id="logout-confirm-yes" class="mfa-modal-ghost-btn">Log out</button>
            </div>
        </div>
    </div>

    <form id="logout-form" method="POST" action="{{ route('logout') }}" style="display:none">
        @csrf
    </form>

    @stack('scripts')

</body>
</html>
