<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="margin:0; padding:0; background-color:#0d1117; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, Helvetica, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#0d1117; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" style="max-width:480px; background-color:#161b22; border:1px solid #30363d; border-radius:12px; overflow:hidden;">
                    <tr>
                        <td style="padding:28px 28px 8px;">
                            <p style="margin:0; font-size:13px; font-weight:700; letter-spacing:0.4px; text-transform:uppercase; color:#3fb950;">
                                Side House Paddlers
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 28px 4px;">
                            <h1 style="margin:0; font-size:22px; font-weight:800; color:#f0f6fc;">
                                Your court time is coming up
                            </h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 28px 24px;">
                            <p style="margin:0; font-size:15px; color:#c9d1d9; line-height:1.6;">
                                Hi {{ $booking->customer_name }}, just a heads up — your booking starts in about an hour.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 28px 28px;">
                            <table role="presentation" width="100%" style="background-color:#0d1117; border:1px solid #30363d; border-radius:10px;">
                                <tr>
                                    <td style="padding:16px 18px;">
                                        <p style="margin:0 0 6px; font-size:14px; color:#8b949e;">
                                            <strong style="color:#f0f6fc;">{{ optional($booking->court)->name ?? 'Court' }}</strong>
                                        </p>
                                        <p style="margin:0 0 6px; font-size:14px; color:#c9d1d9;">
                                            {{ \Carbon\Carbon::parse($booking->date)->format('l, F j') }}
                                        </p>
                                        <p style="margin:0; font-size:14px; color:#c9d1d9;">
                                            {{ \Carbon\Carbon::parse($booking->start_time)->format('g:i A') }}
                                            &ndash;
                                            {{ \Carbon\Carbon::parse($booking->end_time)->format('g:i A') }}
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 28px 32px;">
                            <p style="margin:0; font-size:13px; color:#6e7681; line-height:1.6;">
                                See you on the court! If your plans changed, no reply is needed here — just make sure to cancel from your booking confirmation if you can no longer make it.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
