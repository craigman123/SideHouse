<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard · SideHouse</title>
<!-- These three stylesheets load together, same pattern as your admin pages -->
<link rel="stylesheet" href="css/app.css">
<link rel="stylesheet" href="css/dashboard.css">
<link rel="stylesheet" href="css/user-dashboard.css">
</head>
<body>

<div class="app-layout">

  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="sidebar-logo">
      <!-- swap for your real logo image -->
      <span class="logo-text">SideHouse</span>
    </div>

    <nav class="sidebar-nav">
      <a href="/dashboard" class="active">Dashboard</a>
      <a href="/book">Book a Court</a>
      <a href="/my-bookings">My Bookings</a>
      <a href="/profile">Profile</a>
    </nav>

    <!-- Reuses the profile dropdown component already defined in app.css -->
    <div class="sidebar-profile">
      <button class="profile-trigger" id="profileTrigger" aria-haspopup="true" aria-expanded="false">
        <span class="profile-avatar">AL</span>
        <span class="profile-name">Alex Lee</span>
      </button>
      <div class="profile-dropdown" id="profileDropdown">
        <button type="button">View profile</button>
        <button type="button" class="dropdown-logout">Log out</button>
        <button type="button" class="dropdown-cancel">Cancel</button>
      </div>
    </div>
  </aside>

  <!-- Main -->
  <div class="main">
    <header class="topbar">
      <h1>Good afternoon, Alex</h1>
      <span class="date" id="topbarDate"></span>
    </header>

    <div class="content">

      <!-- Signature element: next game + live countdown + court graphic -->
      <section class="hero-next-game">
        <div class="hero-info">
          <span class="hero-eyebrow">Your next game</span>
          <h2>Court 2 &middot; Padel</h2>
          <p class="hero-meta">Saturday, 26 July &middot; 6:00 PM &ndash; 7:00 PM</p>

          <div class="countdown" id="countdown" aria-live="polite">
            <div class="countdown-unit">
              <span id="cdHours">00</span>
              <label>Hours</label>
            </div>
            <div class="countdown-unit">
              <span id="cdMinutes">00</span>
              <label>Minutes</label>
            </div>
            <div class="countdown-unit">
              <span id="cdSeconds">00</span>
              <label>Seconds</label>
            </div>
          </div>

          <a href="/book" class="btn-hero-cta">Book another court</a>
        </div>

        <div class="hero-court" aria-hidden="true">
          <svg viewBox="0 0 240 320" xmlns="http://www.w3.org/2000/svg">
            <rect x="4" y="4" width="232" height="312" rx="10" class="court-surface" />
            <rect x="20" y="20" width="200" height="280" class="court-boundary" />
            <line x1="20" y1="160" x2="220" y2="160" class="court-line net-line" />
            <line x1="20" y1="100" x2="220" y2="100" class="court-line" />
            <line x1="20" y1="220" x2="220" y2="220" class="court-line" />
            <line x1="120" y1="20" x2="120" y2="300" class="court-line" />
            <circle cx="120" cy="70" r="7" class="court-ball" />
          </svg>
        </div>
      </section>

      <!-- Quick stats -->
      <section class="card-grid">
        <div class="card">
          <p class="label">Upcoming Bookings</p>
          <p class="value bookings">3</p>
        </div>
        <div class="card">
          <p class="label">Hours Played This Month</p>
          <p class="value income">8.5</p>
        </div>
        <div class="card">
          <p class="label">Favorite Court</p>
          <p class="value bookings">Court 2</p>
        </div>
      </section>

      <!-- Upcoming bookings -->
      <section class="panel">
        <h2>Upcoming Bookings</h2>
        <table>
          <thead>
            <tr>
              <th>Court</th>
              <th>Date</th>
              <th>Time</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="upcomingBody">
            <tr data-booking="Court 2 · Sat 26 Jul, 6:00 PM">
              <td class="cell-name">Court 2 &middot; Padel</td>
              <td>26 Jul 2026</td>
              <td>6:00 PM &ndash; 7:00 PM</td>
              <td><span class="status status-paid">Confirmed</span></td>
              <td class="actions-cell">
                <button type="button" class="btn-icon btn-delete" data-cancel>Cancel</button>
              </td>
            </tr>
            <tr data-booking="Court 1 · Wed 30 Jul, 7:30 PM">
              <td class="cell-name">Court 1 &middot; Tennis</td>
              <td>30 Jul 2026</td>
              <td>7:30 PM &ndash; 8:30 PM</td>
              <td><span class="status status-pending">Awaiting payment</span></td>
              <td class="actions-cell">
                <button type="button" class="btn-icon btn-delete" data-cancel>Cancel</button>
              </td>
            </tr>
            <tr data-booking="Court 3 · Fri 1 Aug, 5:00 PM">
              <td class="cell-name">Court 3 &middot; Padel</td>
              <td>01 Aug 2026</td>
              <td>5:00 PM &ndash; 6:00 PM</td>
              <td><span class="status status-paid">Confirmed</span></td>
              <td class="actions-cell">
                <button type="button" class="btn-icon btn-delete" data-cancel>Cancel</button>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- Booking history -->
      <section class="panel">
        <h2>Recent Activity</h2>
        <table>
          <thead>
            <tr>
              <th>Court</th>
              <th>Date</th>
              <th>Amount</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td class="cell-name">Court 2 &middot; Padel</td>
              <td>18 Jul 2026</td>
              <td>&#8369;600</td>
              <td><span class="status status-paid">Paid</span></td>
            </tr>
            <tr>
              <td class="cell-name">Court 1 &middot; Tennis</td>
              <td>12 Jul 2026</td>
              <td>&#8369;500</td>
              <td><span class="status status-paid">Paid</span></td>
            </tr>
            <tr>
              <td class="cell-name">Court 3 &middot; Padel</td>
              <td>05 Jul 2026</td>
              <td>&#8369;600</td>
              <td><span class="status status-cancelled">Cancelled</span></td>
            </tr>
          </tbody>
        </table>
        <div class="pagination-wrapper">
          <nav aria-label="Booking history pages">
            <span class="count-badge">Page 1 of 4</span>
          </nav>
        </div>
      </section>

    </div>
  </div>
</div>

<!-- Cancel confirmation modal -->
<div class="modal-overlay" id="cancelModal">
  <div class="modal-box modal-box-sm">
    <div class="modal-header">
      <h3>Cancel this booking?</h3>
      <button type="button" class="modal-close" id="modalClose" aria-label="Close">&times;</button>
    </div>
    <p class="modal-text" id="modalText">This will free up the slot for other players. This can't be undone.</p>
    <div class="modal-actions">
      <button type="button" class="btn btn-secondary" id="modalKeep">Keep booking</button>
      <button type="button" class="btn btn-danger" id="modalConfirm">Cancel booking</button>
    </div>
  </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<script src="js/user-dashboard.js"></script>
</body>
</html>
