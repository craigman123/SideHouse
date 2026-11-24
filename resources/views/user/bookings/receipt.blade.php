<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt | Side House Paddlers</title>
    <script src="{{ asset('js/guest/theme-toggle.js') }}"></script>
    <link rel="icon" href="{{ asset('images/tab_icon.png') }}">
    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}">
    <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/guest/payment-receipt.css') }}">
    <link rel="stylesheet" href="{{ asset('css/guest/theme-toggle.css') }}">
</head>
<body class="receipt-body">
    @include('partials.guest.theme-toggle', ['placement' => 'corner'])

    <div class="bg-particle"></div>
    <div class="bg-particle"></div>
    <div class="bg-particle"></div>
    <div class="bg-particle"></div>
    <div class="bg-particle"></div>
    <div class="bg-particle"></div>
    <div class="bg-particle"></div>
    <div class="bg-particle"></div>

    <div class="card">

        <div class="header">
            <span class="brand">SideHouse</span>
            <span class="status">&#10003; Payment Confirmed</span>
        </div>

        <div class="intro">
            <h1>Thanks, {{ $bookings->first()->customer_name }}!</h1>
            <p>We've confirmed your payment. Here's your receipt for the record.</p>
        </div>

        <div class="details">
            <table>
                <tr>
                    <td>Reference #</td>
                    <td>{{ $paymentReference->payment_reference ?? $paymentReference->id }}</td>
                </tr>
                <tr>
                    <td>Payment method</td>
                    {{-- Show CREDITS when the QR amount is zero (fully covered by credits) --}}
                    <td>{{ (float) $paymentReference->amount <= 0 ? 'CREDITS' : strtoupper($paymentReference->payment_method) }}</td>
                </tr>
                @if ($paymentReference->gcash_reference_number)
                    <tr>
                        <td>QR Ph ref</td>
                        <td>{{ $paymentReference->gcash_reference_number }}</td>
                    </tr>
                @endif
                <tr>
                    <td>Confirmed on</td>
                    <td>{{ $paymentReference->confirmed_at->format('F j, Y g:i A') }}</td>
                </tr>
            </table>
        </div>

        @if ($paymentReference->given_used > 0 || $paymentReference->reserved_used > 0)
        <p class="section-label">Credits used</p>
        <div class="details">
            <table>
                @if ($paymentReference->given_used > 0)
                <tr>
                    <td>Given credits</td>
                    <td>&#8369;{{ number_format($paymentReference->given_used, 2) }}</td>
                </tr>
                @endif
                @if ($paymentReference->reserved_used > 0)
                <tr>
                    <td>Reserved credits</td>
                    <td>&#8369;{{ number_format($paymentReference->reserved_used, 2) }}</td>
                </tr>
                @endif
            </table>
        </div>
        @endif

        <p class="section-label">Bookings</p>

        <table class="bookings">
            <tr>
                <th>Court</th>
                <th>Date &amp; Time</th>
                <th>Amount</th>
            </tr>
            @foreach ($bookings as $booking)
                <tr>
                    <td>{{ $booking->court->name }}</td>
                    <td>
                        {{ $booking->date->format('M j, Y') }}<br>
                        <span class="time">
                            {{ \Carbon\Carbon::parse($booking->start_time)->format('g:i A') }}
                            &ndash;
                            {{ \Carbon\Carbon::parse($booking->end_time)->format('g:i A') }}
                        </span>
                    </td>
                    <td>&#8369;{{ number_format($booking->amount, 2) }}</td>
                </tr>
            @endforeach
        </table>

        <div class="total">
            @if ((float) $paymentReference->amount <= 0)
                <span class="label">Payment</span>
                <span class="amount">Credit covered</span>
            @else
                <span class="label">Total paid</span>
                <span class="amount">&#8369;{{ number_format($paymentReference->amount, 2) }}</span>
            @endif
        </div>

        <div class="footer">
            <p>If anything above looks wrong, contact us and we'll sort it out.</p>
        </div>

    </div>

    <p class="back-link">
        <a href="{{ route('user.bookings.index') }}">&larr; Back to My Bookings</a>
        &nbsp;&middot;&nbsp;
        <a href="{{ route('book.index') }}">Book again &rarr;</a>
    </p>

</body>
</html>
