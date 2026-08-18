<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0; padding:0; background-color:#0d1117; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, Helvetica, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#0d1117; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" style="max-width:480px; background-color:#161b22; border:1px solid #30363d; border-radius:12px; overflow:hidden;">

                    {{-- Brand + eyebrow --}}
                    <tr>
                        <td style="padding:28px 28px 8px;">
                            <p style="margin:0; font-size:13px; font-weight:700; letter-spacing:0.4px; text-transform:uppercase; color:#3fb950;">
                                Side House Paddlers
                            </p>
                        </td>
                    </tr>

                    {{-- Headline --}}
                    <tr>
                        <td style="padding:8px 28px 4px;">
                            <h1 style="margin:0; font-size:22px; font-weight:800; color:#f0f6fc;">
                                Your court time is coming up
                            </h1>
                        </td>
                    </tr>

                    {{-- Greeting --}}
                    <tr>
                        <td style="padding:8px 28px 20px;">
                            <p style="margin:0; font-size:15px; color:#c9d1d9; line-height:1.6;">
                                Hi {{ $booking->customer_name }}, just a heads up — your booking starts in about an hour.
                            </p>
                        </td>
                    </tr>

                    {{-- Date / time — the thing people actually scan for --}}
                    <tr>
                        <td style="padding:0 28px 16px;">
                            <table role="presentation" width="100%" style="background-color:#0d1117; border:1px solid #30363d; border-radius:10px;">
                                <tr>
                                    <td style="padding:20px 20px 18px;">
                                        <p style="margin:0 0 4px; font-size:12px; font-weight:700; letter-spacing:0.4px; text-transform:uppercase; color:#8b949e;">
                                            {{ optional($booking->court)->name ?? 'Court' }}
                                        </p>
                                        <p style="margin:0 0 8px; font-size:26px; font-weight:800; color:#f0f6fc; line-height:1.2;">
                                            {{ \Carbon\Carbon::parse($booking->start_time)->format('g:i A') }}
                                            <span style="color:#6e7681; font-weight:600;">&ndash;</span>
                                            {{ \Carbon\Carbon::parse($booking->end_time)->format('g:i A') }}
                                        </p>
                                        <p style="margin:0; font-size:14px; color:#c9d1d9;">
                                            {{ \Carbon\Carbon::parse($booking->date)->format('l, F j, Y') }}
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Equipment reserved for this booking (booking_equipment -> equipment) --}}
                    @if($booking->equipment && $booking->equipment->isNotEmpty())
                    <tr>
                        <td style="padding:0 28px 16px;">
                            <table role="presentation" width="100%" style="background-color:#0d1117; border:1px solid #30363d; border-radius:10px;">
                                <tr>
                                    <td style="padding:16px 20px;">
                                        <p style="margin:0 0 10px; font-size:12px; font-weight:700; letter-spacing:0.4px; text-transform:uppercase; color:#8b949e;">
                                            Equipment reserved
                                        </p>
                                        @foreach($booking->equipment as $item)
                                        <table role="presentation" width="100%" style="{{ !$loop->last ? 'margin-bottom:8px;' : '' }}">
                                            <tr>
                                                <td style="font-size:14px; color:#c9d1d9;">
                                                    {{ optional($item->equipment)->name ?? 'Item' }}
                                                </td>
                                                <td align="right" style="font-size:14px; color:#8b949e; white-space:nowrap;">
                                                    &times; {{ $item->quantity }}
                                                </td>
                                            </tr>
                                        </table>
                                        @endforeach
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @endif

                    {{-- Directions to the venue — same address/coordinates used on the landing page's "Find Us" section --}}
                    <tr>
                        <td style="padding:0 28px 8px;">
                            <a href="https://www.google.com/maps/dir/?api=1&destination=10.246043101731798,123.78949399013447"
                               style="display:block; text-align:center; padding:12px 18px; background-color:#238636; border-radius:8px; font-size:14px; font-weight:700; color:#f0f6fc; text-decoration:none;">
                                Get Directions
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 28px 24px;">
                            <p style="margin:0; font-size:13px; color:#6e7681; text-align:center;">
                                423 Tabay, Tunghaan, Minglanilla, Cebu
                            </p>
                        </td>
                    </tr>

                    {{-- Footer --}}
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