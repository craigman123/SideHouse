<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Not Found | Court Booking</title>
    <link rel="icon" href="public/images/tab_icon.png">
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
            right: -60px;
            width: 180px;
            height: 180px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(35, 134, 54, .18), transparent 70%);
        }

        .error-court {
            width: 96px;
            height: 96px;
            margin: 0 auto 24px;
            position: relative;
        }

        .error-court svg { width: 100%; height: 100%; }

        .error-code {
            font-size: 84px;
            font-weight: 800;
            letter-spacing: -2px;
            line-height: 1;
            background: linear-gradient(135deg, #56d364, #238636);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 8px;
        }

        .error-title {
            font-size: 20px;
            font-weight: 800;
            color: #f0f6fc;
            margin-bottom: 10px;
        }

        .error-subtitle {
            font-size: 14px;
            color: #8b949e;
            line-height: 1.6;
            margin-bottom: 32px;
        }

        .error-subtitle code {
            background: #0d1117;
            border: 1px solid #30363d;
            border-radius: 4px;
            padding: 2px 6px;
            font-size: 12.5px;
            color: #79c0ff;
        }

        .error-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            border: 1px solid #30363d;
            cursor: pointer;
            transition: filter .15s ease, border-color .15s ease;
        }

        .btn-primary {
            background: #238636;
            color: #fff;
            border-color: #238636;
        }
        .btn-primary:hover { filter: brightness(1.1); }

        .btn-secondary {
            background: #0d1117;
            color: #f0f6fc;
        }
        .btn-secondary:hover { border-color: #56d364; color: #56d364; }

        .error-footer {
            margin-top: 28px;
            font-size: 12px;
            color: #6e7681;
        }

        @media (max-width: 420px) {
            .error-card { padding: 36px 24px; }
            .error-code { font-size: 64px; }
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-court" aria-hidden="true">
            <svg viewBox="0 0 96 96" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="8" y="20" width="80" height="56" rx="6" stroke="#30363d" stroke-width="2.5"/>
                <line x1="48" y1="20" x2="48" y2="76" stroke="#30363d" stroke-width="2.5"/>
                <rect x="8" y="20" width="80" height="56" rx="6" stroke="#238636" stroke-width="2.5" stroke-dasharray="4 220" stroke-dashoffset="0">
                    <animate attributeName="stroke-dashoffset" from="0" to="-448" dur="3.2s" repeatCount="indefinite"/>
                </rect>
                <circle cx="48" cy="48" r="6" fill="#56d364"/>
            </svg>
        </div>

        <div class="error-code">404</div>
        <div class="error-title">This court doesn't exist</div>
        <p class="error-subtitle">
            The page you're looking for isn't on the schedule.
            It may have been moved, renamed, or the link
            <code>{{ request()->path() }}</code> might just be off.
        </p>

        <div class="error-actions">
            <a href="{{ url('/') }}" class="btn btn-primary">Refresh</a>
            <a href="{{ url()->previous() }}" class="btn btn-secondary">Go back</a>
        </div>

        <p class="error-footer">Court Booking &middot; Side House Paddlers</p>
    </div>
</body>
</html>
