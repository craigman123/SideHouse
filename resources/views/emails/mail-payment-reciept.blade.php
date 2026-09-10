<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
</head>
<body style="margin:0; padding:0; background-color:#0d1117; font-family:Helvetica, Arial, sans-serif;">

    <!-- Outer wrapper -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#0d1117; padding:24px 0;">
        <tr>
            <td align="center">

                <!-- Card -->
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px; width:100%; background-color:#161b22; border:1px solid #30363d; border-radius:16px; overflow:hidden;">

                    <!-- Header -->
                    <tr>
                        <td style="background-color:#1c2128; border-bottom:1px solid #30363d; padding:24px 28px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td>
                                        <span style="font-size:18px; font-weight:800; color:#f0f6fc;">SideHouse</span>
                                    </td>
                                    <td align="right">
                                        <span style="font-size:12.5px; font-weight:700; color:#3fb950; text-transform:uppercase; letter-spacing:0.04em;">
                                            &#10003; Payment Confirmed
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Intro -->
                    <tr>
                        <td style="padding:28px 28px 4px 28px;">
                            <p style="margin:0 0 6px 0; font-size:20px; color:#f0f6fc; font-weight:800;">
                                Thanks, {{ $bookings->first()->customer_name }}!
                            </p>
                            <p style="margin:0; font-size:14px; color:#8b949e; line-height:1.6;">
                                We've confirmed your payment. Here's your receipt for the record.
                            </p>
                        </td>
                    </tr>

                    <!-- Payment details -->
                    <tr>
                        <td style="padding:20px 28px 0 28px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#1c2128; border:1px solid #30363d; border-radius:12px;">
                                <tr>
                                    <td style="padding:14px 18px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="padding:5px 0; font-size:13px; color:#8b949e;">Reference #</td>
                                                <td align="right" style="padding:5px 0; font-size:13px; color:#f0f6fc; font-weight:700;">
                                                    {{ $paymentReference->payment_reference ?? $paymentReference->id }}
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:5px 0; font-size:13px; color:#8b949e;">Payment method</td>
                                                <td align="right" style="padding:5px 0; font-size:13px; color:#f0f6fc; font-weight:700;">
                                                    {{ strtoupper($paymentReference->payment_method) }}
                                                </td>
                                            </tr>
                                            @if ($paymentReference->gcash_reference_number)
                                                <tr>
                                                    <td style="padding:5px 0; font-size:13px; color:#8b949e;">GCash ref</td>
                                                    <td align="right" style="padding:5px 0; font-size:13px; color:#f0f6fc; font-weight:700;">
                                                        {{ $paymentReference->gcash_reference_number }}
                                                    </td>
                                                </tr>
                                            @endif
                                            <tr>
                                                <td style="padding:5px 0; font-size:13px; color:#8b949e;">Confirmed on</td>
                                                <td align="right" style="padding:5px 0; font-size:13px; color:#f0f6fc; font-weight:700;">
                                                    {{ $paymentReference->confirmed_at->format('F j, Y g:i A') }}
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Bookings -->
                    <tr>
                        <td style="padding:24px 28px 0 28px;">
                            <p style="margin:0 0 12px 0; font-size:12px; font-weight:700; color:#3fb950; text-transform:uppercase; letter-spacing:0.04em;">
                                Bookings
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                <tr>
                                    <td style="padding:0 8px 8px 0; font-size:11.5px; color:#6e7681; text-transform:uppercase; letter-spacing:0.03em; border-bottom:1px solid #30363d;">Court</td>
                                    <td style="padding:0 8px 8px 0; font-size:11.5px; color:#6e7681; text-transform:uppercase; letter-spacing:0.03em; border-bottom:1px solid #30363d;">Date &amp; Time</td>
                                    <td align="right" style="padding:0 0 8px 0; font-size:11.5px; color:#6e7681; text-transform:uppercase; letter-spacing:0.03em; border-bottom:1px solid #30363d;">Amount</td>
                                </tr>
                                @foreach ($bookings as $booking)
                                    <tr>
                                        <td style="padding:12px 8px 12px 0; font-size:14px; color:#f0f6fc; border-bottom:1px solid #30363d; vertical-align:top;">
                                            {{ $booking->court->name }}
                                        </td>
                                        <td style="padding:12px 8px 12px 0; font-size:14px; color:#f0f6fc; border-bottom:1px solid #30363d; vertical-align:top;">
                                            {{ $booking->date->format('M j, Y') }}<br>
                                            <span style="color:#8b949e; font-size:12.5px;">
                                                {{ \Carbon\Carbon::parse($booking->start_time)->format('g:i A') }}
                                                &ndash;
                                                {{ \Carbon\Carbon::parse($booking->end_time)->format('g:i A') }}
                                            </span>
                                        </td>
                                        <td align="right" style="padding:12px 0 12px 0; font-size:14px; color:#f0f6fc; border-bottom:1px solid #30363d; vertical-align:top; white-space:nowrap;">
                                            &#8369;{{ number_format($booking->amount, 2) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>

                    <!-- Total -->
                    <tr>
                        <td style="padding:20px 28px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:rgba(63,185,80,0.12); border:1px solid #238636; border-radius:12px;">
                                <tr>
                                    <td style="padding:16px 18px; font-size:13px; color:#8b949e;">
                                        Total paid
                                    </td>
                                    <td align="right" style="padding:16px 18px; font-size:22px; font-weight:800; color:#3fb950;">
                                        &#8369;{{ number_format($paymentReference->amount, 2) }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding:4px 28px 28px 28px; border-top:1px solid #30363d;">
                            <p style="margin:18px 0 0 0; font-size:12.5px; color:#6e7681; line-height:1.6;">
                                If anything above looks wrong, just reply to this email and we'll sort it out.
                            </p>
                        </td>
                    </tr>

                </table>
                <!-- /Card -->

                <p style="max-width:560px; margin:16px 0 0 0; font-size:12px; color:#6e7681;">
                    SideHouse &middot; This is an automated receipt, please keep it for your records.
                </p>

            </td>
        </tr>
    </table>

</body>
</html>