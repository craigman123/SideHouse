<!DOCTYPE html>
<html lang="en" class="user-layout-shell @yield('page-class')">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>@yield('title', 'Side House')</title>
    @stack('head-scripts')
    <script src="{{ asset('js/guest/theme-toggle.js') }}"></script>
    <script src="{{ asset('js/user/sidebar-collapse.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}">
    <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/user/user-navbar.css') }}">
    <link rel="stylesheet" href="{{ asset('css/user/sidebar-collapse.css') }}">
    <link rel="stylesheet" href="{{ asset('css/shared/switch-access.css') . '?v=' . filemtime(public_path('css/shared/switch-access.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/guest/theme-toggle.css') }}">
    <link rel="icon" type="image/png" href="{{ asset('images/tab_icon.png') }}">
    <script src="{{ asset('js/app.js') }}" defer></script>
    <script src="{{ asset('js/shared/switch-access.js') . '?v=' . filemtime(public_path('js/shared/switch-access.js')) }}" defer></script>
    @push('scripts')
        <script src="{{ asset('js/user/user-shell.js') }}" defer></script>
    @endpush
    @stack('styles')
</head>
<body>

    <aside class="sidebar" id="userSidebar">
        <div class="sidebar-logo">
            <a href="{{ route('user.dashboard') }}" class="sidebar-brand-link">
                <img src="{{ asset('images/logo.png') }}" alt="" class="logo-img">
                <span class="sidebar-brand">Side House</span>
            </a>
        </div>

        <nav class="sidebar-nav">
            <label class="nav-label">Main</label>
            <a href="{{ route('user.dashboard') }}" class="{{ request()->routeIs('user.dashboard') ? 'active' : '' }}" aria-label="Overview" title="Overview">
                <svg class="sidebar-nav-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
                <span>Overview</span>
            </a>

            <label class="nav-label">Bookings</label>
            <a href="/book" class="{{ request()->is('book') ? 'active' : '' }}" aria-label="Book a Court" title="Book a Court">
                <svg class="sidebar-nav-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="12" y1="14" x2="12" y2="20"/><line x1="9" y1="17" x2="15" y2="17"/></svg>
                <span>Book a Court</span>
            </a>
            <a href="/my-bookings" class="{{ request()->is('my-bookings') ? 'active' : '' }}" aria-label="My Bookings" title="My Bookings">
                <svg class="sidebar-nav-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="m9 16 2 2 4-4"/></svg>
                <span>My Bookings</span>
            </a>

            <label class="nav-label">Personal</label>
            <a href="{{ route('user.profile') }}" class="{{ request()->routeIs('user.profile') ? 'active' : '' }}" aria-label="Profile" title="Profile">
                <svg class="sidebar-nav-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/></svg>
                <span>Profile</span>
            </a>
            <a href="notifications" class="{{ request()->is('notifications') ? 'active' : '' }}" aria-label="Notifications" title="Notifications">
                <svg class="sidebar-nav-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
                <span>Notifications</span>
            </a>

            <label class="nav-label">Memberships</label>
            <a href="/feedback" class="{{ request()->is('memberships') ? 'active' : '' }}" aria-label="Feedback and Suggestions" title="Feedback and Suggestions">
                <svg  xmlns="http://www.w3.org/2000/svg" width="24" height="24"  
                    fill="currentColor" viewBox="0 0 24 24" >
                    <path d="m4.83 12.49 2.04 1.83-.83 2.9-1 3.5c-.12.4.03.84.37 1.08.34.25.8.26 1.14.02l3-2L12 18.19l2.45 1.63 3 2a.99.99 0 0 0 1.14-.02c.34-.25.49-.68.37-1.08l-1-3.5-.83-2.9 2.04-1.83 2.5-2.25c.3-.27.41-.69.28-1.06-.13-.38-.47-.64-.87-.68l-3.15-.25-2.56-.2-2.47-5.46a.998.998 0 0 0-1.82 0L8.61 8.05l-2.56.2-3.15.25c-.4.03-.74.3-.87.68s-.02.8.28 1.06l2.5 2.25Zm1.39-2.25 2.52-.2.62-.05.59-.05.84-1.86 1.2-2.66 1.2 2.66.84 1.86.59.05.62.05 2.52.2.83.07-.77.69-2.5 2.25-.46.42.17.6 1.25 4.38-3.74-2.49-.55-.37-.55.37-3.74 2.49 1.25-4.38.17-.6-.46-.42L6.16 11l-.77-.69z"></path>
                    </svg>
                <span>Memberships</span>
            </a>

            <label class="nav-label">Improvements</label>
            <a href="/feedback" class="{{ request()->is('feedback') ? 'active' : '' }}" aria-label="Feedback and Suggestions" title="Feedback and Suggestions">
                <svg class="sidebar-nav-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.5 8.5 0 0 1-12.7 7.4L3 21l2.1-5.3A8.5 8.5 0 1 1 21 11.5Z"/><path d="M8 12h.01M12 12h.01M16 12h.01"/></svg>
                <span>Feedback and Suggestions</span>
            </a>
        </nav>

        @include('partials.switch-access.panel')

        <div class="sidebar-profile">
            <a href="/profile" class="profile-trigger {{ request()->is('profile') ? 'active' : '' }}" aria-label="Profile" title="Profile">
                <span class="profile-avatar">{{ strtoupper(substr($userName, 0, 1)) }}</span>
                <span class="profile-name">{{ ucfirst(explode(' ', $userName)[0]) }}</span>
            </a>
            <button type="button" class="sidebar-collapse-toggle" aria-label="Collapse sidebar" aria-expanded="true" aria-controls="userSidebar" title="Collapse sidebar">
                <svg data-sidebar-icon="collapse" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18"/><path d="m14 9 3 3-3 3"/></svg>
                <svg data-sidebar-icon="expand" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" hidden><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18"/><path d="m16 15-3-3 3-3"/></svg>
            </button>
        </div>

        <div class="sidebar-footer">&copy; {{ date('Y') }} Side House</div>
    </aside>

    <button type="button" id="userSidebarBackdrop" class="user-sidebar-backdrop" data-user-sidebar-backdrop aria-hidden="true" tabindex="-1" hidden></button>

    <div class="main">
        @include('partials.switch-access.banner')
        <header class="topbar">
            <div class="user-mobile-menu-group">
                <button type="button" class="user-mobile-menu-toggle" data-user-mobile-menu-toggle aria-label="Open navigation" aria-expanded="false" aria-controls="userSidebar" title="Open navigation">
                    <svg data-user-menu-icon="open" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                    <svg data-user-menu-icon="close" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" hidden><path d="m6 6 12 12M18 6 6 18"/></svg>
                </button>
                <a href="{{ route('user.dashboard') }}" class="user-mobile-brand" aria-label="Side House home">
                    <img src="{{ asset('images/logo.png') }}" alt="" class="user-mobile-brand-logo">
                    <span>Side House</span>
                </a>
            </div>
            <h1>@yield('page-title', 'Dashboard')</h1>
            <div class="date">
                @include('partials.guest.theme-toggle', ['placement' => 'toolbar'])
                 <a href="notifications" class="user-notification-bell" data-unread-url="{{ route('user.notifications.unread-count') }}" style="position: relative;" aria-label="Notifications">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24" >
                        <path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"></path>
                    </svg>  
                <span class="bell-unread-dot" hidden></span>
            </a>
                <span class="user-topbar-date">{{ now()->format('F d, Y') }}</span>
            </div>
        </header>

        <main class="content">
            @yield('content')
        </main>
    </div>

    @stack('scripts')

</body>
</html>
