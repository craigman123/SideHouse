<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Court Booking System')</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
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
            <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">Overview</a>

            <label class="nav-label">Management</label>
            <a href="{{ route('bookings.index') }}" class="{{ request()->routeIs('bookings.*') ? 'active' : '' }}">Bookings</a>
            <a href="{{ route('courts.index') }}" class="{{ request()->routeIs('courts.*') ? 'active' : '' }}">Courts</a>

            <label class="nav-label">Users</label>
            <a href="#">Customers</a>
            <a href="{{ route('admin.announcements.index') }}" class="{{ request()->routeIs('admin.announcements.*') ? 'active' : '' }}">Announcements</a>

            <label class="nav-label">Audit Logs</label>
            <a href="#">Reports</a>
            <a href="{{ route('activity_logs.index') }}" class="{{ request()->routeIs('activity_logs.*') ? 'active' : '' }}">Activity Logs</a>
        </nav>

        <div class="sidebar-profile">
            <button type="button" class="profile-trigger" onclick="toggleProfileDropdown()">
                <span class="profile-avatar">{{ strtoupper(substr(auth()->user()->name ?? 'A', 0, 1)) }}</span>
                <span class="profile-name">{{ auth()->user()->name ?? 'Admin' }}</span>
            </button>

            <div class="profile-dropdown" id="profile-dropdown">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="dropdown-logout">Logout</button>
                </form>
                <button type="button" class="dropdown-cancel" onclick="closeProfileDropdown()">Cancel</button>
            </div>
        </div>

        <div class="sidebar-footer">&copy; {{ date('Y') }} Court Booking</div>
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