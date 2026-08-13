<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>@yield('title', 'Side House')</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/user-navbar.css') }}">
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
            <a href="{{ route('user.dashboard') }}" class="{{ request()->routeIs('user.dashboard') ? 'active' : '' }}">Overview</a>

            <label class="nav-label">Bookings</label>
            <a href="/book" class="{{ request()->is('book') ? 'active' : '' }}">Book a Court</a>
            <a href="/my-bookings" class="{{ request()->is('my-bookings') ? 'active' : '' }}">My Bookings</a>

            <label class="nav-label">Personal</label>
            <a href="{{ route('user.profile') }}" class="{{ request()->routeIs('user.profile') ? 'active' : '' }}">Profile</a>
            <a href="notifications" class="{{ request()->is('notifications') ? 'active' : '' }}">Notifications</a>

            <label class="nav-label">Improvements</label>
            <a href="/feedback" class="{{ request()->is('feedback') ? 'active' : '' }}">Feedback and Suggestions</a>
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