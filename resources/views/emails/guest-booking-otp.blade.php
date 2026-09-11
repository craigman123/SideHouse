<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Booking Lookup Code</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family: Arial, Helvetica, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5; padding:32px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:12px; overflow:hidden; border:1px solid #e5e7eb;">
                    <tr>
                        <td style="background-color:#161b22; padding:24px 32px;">
                            <span style="color:#ffffff; font-size:18px; font-weight:700;">Side House Paddlers</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 8px; color:#111827; font-size:16px;">Hi there,</p>
                            <p style="margin:0 0 24px; color:#374151; font-size:15px; line-height:1.6;">
                                Use the code below to find your booking. It expires in {{ $ttlMinutes }} minutes.
                            </p>

                            <div style="text-align:center; margin:0 0 24px;">
                                <span style="display:inline-block; letter-spacing:8px; font-size:32px; font-weight:800; color:#161b22; background-color:#f4f4f5; border-radius:10px; padding:16px 24px;">
                                    {{ $code }}
                                </span>
                            </div>

                            <p style="margin:0; color:#6b7280; font-size:13px; line-height:1.6;">
                                Didn't request this? You can safely ignore this email — no changes were made to any booking.
                            </p>

                            <p style="margin:0; color:#6b7280; font-size:13px; line-height:1.6;">
                                <strong style = "color:#0f9700;"> Reason:</strong> Booking privacy is needed to ensure the safety of all participants.
                                 And to avoid fraudelent booking attempts.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 32px; background-color:#f9fafb; border-top:1px solid #e5e7eb;">
                            <p style="margin:0; color:#9ca3af; font-size:12px;">
                                This is an automated message, please don't reply to this email.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
