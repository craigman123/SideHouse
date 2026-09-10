<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Maintenance Mode | Court Booking</title>

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #0d1117;
            color: #f0f6fc;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, Helvetica, sans-serif;
            padding: 24px;
        }

        .status-card {
            width: 100%;
            max-width: 480px;
            text-align: center;
            padding: 44px 36px;
            border: 1px solid #30363d;
            border-radius: 16px;
            background: #161b22;
        }

        .status-icon {
            width: 72px;
            height: 72px;
            margin: 0 auto 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
            margin-bottom: 18px;
        }

        .status-dot { width: 7px; height: 7px; border-radius: 50%; }

        .status-title { font-size: 22px; font-weight: 800; margin-bottom: 10px; }
        .status-subtitle { font-size: 14px; color: #8b949e; line-height: 1.6; margin-bottom: 8px; }
        .status-footer { margin-top: 26px; font-size: 12px; color: #6e7681; }

        /* ── ON state (maintenance active) — amber ── */
        .is-on .status-icon { background: rgba(210, 153, 34, .12); color: #e3b341; }
        .is-on .status-pill { border: 1px solid #d29922; background: rgba(210, 153, 34, .1); color: #e3b341; }
        .is-on .status-dot { background: #e3b341; animation: pulse 1.6s ease-in-out infinite; }

        /* ── OFF state (site live again) — green ── */
        .is-off .status-icon { background: rgba(35, 134, 54, .14); color: #56d364; }
        .is-off .status-pill { border: 1px solid #238636; background: rgba(35, 134, 54, .1); color: #56d364; }
        .is-off .status-dot { background: #56d364; }

        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: .35; } }

        @media (max-width: 420px) {
            .status-card { padding: 34px 22px; }
            .status-title { font-size: 19px; }
        }
    </style>
</head>
<body>

    @php
        $isOn = ($status ?? 'off') === 'on';
    @endphp

    <div class="status-card {{ $isOn ? 'is-on' : 'is-off' }}">
        <div class="status-icon">{{ $isOn ? '🔧' : '✅' }}</div>

        <div class="status-pill">
            <span class="status-dot"></span>
            {{ $isOn ? 'Maintenance ON' : 'Maintenance OFF' }}
        </div>

        @if ($isOn)
            <div class="status-title">Site is now in maintenance mode</div>
            <p class="status-subtitle">
                Visitors are seeing the maintenance page. You're browsing
                normally because your bypass cookie is active.
            </p>
            <input type="text" name="bypass" placeholder="Enter Token to bypass Maintenance">
        @else
            <div class="status-title">Site is back online</div>
            <p class="status-subtitle">
                Maintenance mode has been turned off and the bypass cookie
                was cleared. Everyone, including you, now sees the live site.
            </p>
            <a href="{{ url('/') }}" class="button">Refresh</a>
        @endif

        <p class="status-footer">Court Booking &middot; Side House Paddlers</p>
    </div>

</body>
</html>