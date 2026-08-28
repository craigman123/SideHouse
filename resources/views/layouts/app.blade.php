<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Court Booking System')</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/mfa-modal.css') }}">
    <link rel="icon" type="image/png" href="{{ asset('images/tab_icon.png') }}">
    <script src="{{ asset('js/app.js') }}" defer></script>
    <script src="{{ asset('js/back-guard.js') }}" defer></script>
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
            <a href="{{ route('admin.configuration.index') }}" class="{{ request()->routeIs('admin.configuration.*') ? 'active' : '' }}">Configuration</a>
            <a href="{{ route('admin.profile') }}" class="{{ request()->routeIs('admin.profile') ? 'active' : '' }}">Profile</a>
            <a href="{{ route('admin.equipment.availability') }}" class="{{ request()->routeIs('admin.equipment.availability') ? 'active' : '' }}">Equipment Availability</a>

            <label class="nav-label">Users</label>
            <a href="{{ route('admin.customers.index') }}" class="{{ request()->routeIs('admin.customers.*') ? 'active' : '' }}">Customers</a>
            <a href="{{ route('admin.announcements.index') }}" class="{{ request()->routeIs('admin.announcements.*') ? 'active' : '' }}">Announcements</a>

            <label class="nav-label">Audit Logs</label>
            <a href="{{ route('admin.reports.index') }}" class="{{ request()->routeIs('admin.reports.*') ? 'active' : '' }}">Reports</a>
            <a href="{{ route('activity_logs.index') }}" class="{{ request()->routeIs('activity_logs.*') ? 'active' : '' }}">Activity Logs</a>
        </nav>

        <div class="sidebar-profile">
            <a href="{{ route('admin.profile') }}" type="button" class="profile-trigger" onclick="toggleProfileDropdown()">
                <span class="profile-avatar">{{ strtoupper(substr(auth()->user()->name ?? 'A', 0, 1)) }}</span>
                <span class="profile-name">{{ auth()->user()->name ?? 'Admin' }}</span>
            </a>
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