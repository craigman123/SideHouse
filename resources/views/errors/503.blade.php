<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="60">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnGoing Maintenance | Court Booking</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        html {
            scroll-behavior: smooth;
            overflow: hidden;
        }

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

        body::before {
            content: "";
            position: absolute;
            bottom: -60px;
            right: -60px;
            width: 500px;
            height: 500px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(210, 153, 34, .16), transparent 70%);
        }

        .error-card {
            width: 100%;
            max-width: 520px;
            text-align: center;
            padding: 48px 40px;
            border: 1px solid #30363d;
            border-radius: 16px;
            background: #161b22;
            position: relative;
            overflow: hidden;
        }

        .error-card::before {
            content: "";
            position: absolute;
            top: -60px;
            left: -60px;
            width: 180px;
            height: 180px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(210, 153, 34, .16), transparent 70%);
        }

        .error-icon {
            width: 88px;
            height: 88px;
            margin: 0 auto 22px;
            position: relative;
        }

        .error-icon svg { width: 100%; height: 100%; }

        .spin {
            transform-origin: 48px 48px;
            animation: spin 2.4s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 6px 14px;
            border-radius: 999px;
            border: 1px solid #d29922;
            background: rgba(210, 153, 34, .1);
            color: #e3b341;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
            margin-bottom: 18px;
        }

        .status-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #e3b341;
            animation: pulse 1.6s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: .35; }
        }

        .error-title {
            font-size: 22px;
            font-weight: 800;
            color: #f0f6fc;
            margin-bottom: 10px;
        }

        .error-subtitle {
            font-size: 14px;
            color: #8b949e;
            line-height: 1.6;
            margin-bottom: 28px;
        }

        .error-footer {
            margin-top: 28px;
            font-size: 12px;
            color: #6e7681;
        }

        .refresh-note {
            font-size: 12px;
            color: #6e7681;
            border-top: 1px solid #21262d;
            padding-top: 18px;
        }

        .refresh-btn {
            display: inline-flex;
            margin: 10px;
            text-decoration: none;
            border: 1px solid goldenrod;
            border-radius: 6px;
            padding: 6px 12px;
            color: #8b949e;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        @media (max-width: 420px) {
            .error-card { padding: 36px 24px; }
            .error-title { font-size: 19px; }
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-icon" aria-hidden="true">
            <svg viewBox="0 0 96 96" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="48" cy="48" r="38" stroke="#30363d" stroke-width="2.5"/>
                <g class="spin">
                    <path d="M48 14 A34 34 0 0 1 82 48" stroke="#d29922" stroke-width="2.5" stroke-linecap="round"/>
                </g>
                <circle cx="48" cy="48" r="5" fill="#d29922"/>
                <path d="M48 48 L48 28" stroke="#e3b341" stroke-width="2.5" stroke-linecap="round"/>
                <path d="M48 48 L62 54" stroke="#e3b341" stroke-width="2.5" stroke-linecap="round"/>
            </svg>
        </div>

        <div class="status-pill"><span class="status-dot"></span>Maintenance in progress</div>

        <div class="error-title">We'll be right back</div>
        <p class="error-subtitle">
            The court booking system is currently undergoing scheduled
            maintenance. We're making a few improvements and will be back
            online shortly. Thanks for your patience.
        </p>

        <p class="refresh-note">This page checks again automatically every minute.</p>

        <p class="error-footer">Court Booking &middot; Side House Paddlers</p>

        <a class="refresh-btn" href="{{ url('/') }}" class="btn btn-primary">Refresh</a>
    </div>
</body>
</html>
