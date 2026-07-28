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
            <a href="/profile" class="{{ request()->is('profile') ? 'active' : '' }}">Profile</a>
            <a href="notifications" class="{{ request()->is('notifications') ? 'active' : '' }}">Notifications</a>
        </nav>

        <div class="sidebar-profile">
            <button type="button" class="profile-trigger" onclick="toggleProfileDropdown()">
                <span class="profile-avatar">{{ strtoupper(substr($userName, 0, 1)) }}</span>
                <span class="profile-name">{{ ucfirst(explode(' ', $userName)[0]) }}</span>
            </button>

            <div class="profile-dropdown" id="profile-dropdown">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="dropdown-logout">Logout</button>
                </form>
                <button type="button" class="dropdown-cancel" onclick="closeProfileDropdown()">Cancel</button>
            </div>
        </div>

        <div class="sidebar-footer">&copy; {{ date('Y') }} Side House</div>
    </aside>

    <div class="main">
        <header class="topbar">
            <h1>@yield('page-title', 'Dashboard')</h1>
            <div class="date">{{ now()->format('F d, Y') }}</div>
        </header>

        <main class="content">
            @yield('content')
        </main>
    </div>

    @stack('scripts')

</body>
</html>