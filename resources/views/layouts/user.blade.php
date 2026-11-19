<!DOCTYPE html>
<html lang="en" class="@yield('page-class')">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>@yield('title', 'Side House')</title>
    @stack('head-scripts')
    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}">
    <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/user/user-navbar.css') }}">
    <link rel="icon" type="image/png" href="{{ asset('images/tab_icon.png') }}">
    <script src="{{ asset('js/app.js') }}" defer></script>
    @stack('styles')
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-logo">
            <img src="{{ asset('images/logo.png') }}" alt="Side House" class="logo-img">
        </div>

        <nav class="sidebar-nav">
            <label class="nav-label">Main</label>
            <a href="{{ route('user.dashboard') }}" class="{{ request()->routeIs('user.dashboard') ? 'active' : '' }}">
                <svg class="sidebar-nav-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
                <span>Overview</span>
            </a>

            <label class="nav-label">Bookings</label>
            <a href="/book" class="{{ request()->is('book') ? 'active' : '' }}">
                <svg class="sidebar-nav-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="12" y1="14" x2="12" y2="20"/><line x1="9" y1="17" x2="15" y2="17"/></svg>
                <span>Book a Court</span>
            </a>
            <a href="/my-bookings" class="{{ request()->is('my-bookings') ? 'active' : '' }}">
                <svg class="sidebar-nav-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="m9 16 2 2 4-4"/></svg>
                <span>My Bookings</span>
            </a>

            <label class="nav-label">Personal</label>
            <a href="{{ route('user.profile') }}" class="{{ request()->routeIs('user.profile') ? 'active' : '' }}">
                <svg class="sidebar-nav-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/></svg>
                <span>Profile</span>
            </a>
            <a href="notifications" class="{{ request()->is('notifications') ? 'active' : '' }}">
                <svg class="sidebar-nav-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
                <span>Notifications</span>
            </a>

            <label class="nav-label">Improvements</label>
            <a href="/feedback" class="{{ request()->is('feedback') ? 'active' : '' }}">
                <svg class="sidebar-nav-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.5 8.5 0 0 1-12.7 7.4L3 21l2.1-5.3A8.5 8.5 0 1 1 21 11.5Z"/><path d="M8 12h.01M12 12h.01M16 12h.01"/></svg>
                <span>Feedback and Suggestions</span>
            </a>
        </nav>

        <div class="sidebar-profile">
            <a href="/profile" class="profile-trigger {{ request()->is('profile') ? 'active' : '' }}">
                <span class="profile-avatar">{{ strtoupper(substr($userName, 0, 1)) }}</span>
                <span class="profile-name">{{ ucfirst(explode(' ', $userName)[0]) }}</span>
            </a>
        </div>

        <div class="sidebar-footer">&copy; {{ date('Y') }} Side House</div>
    </aside>

    <div class="main">
        <header class="topbar">
            <h1>@yield('page-title', 'Dashboard')</h1>
            <div class="date">
                @yield('topbar-actions')
                 <a href="notifications" data-unread-url="{{ route('user.notifications.unread-count') }}" style="position: relative;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24" >
                        <path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"></path>
                    </svg>  
                <span class="bell-unread-dot" hidden></span>
            </a>
                {{ now()->format('F d, Y') }}</div>
        </header>

        <main class="content">
            @yield('content')
        </main>
    </div>

    @stack('scripts')

</body>
</html>
