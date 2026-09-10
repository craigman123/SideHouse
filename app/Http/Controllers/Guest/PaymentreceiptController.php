<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;

class PaymentReceiptController extends Controller
{
    /**
     * GET /guest/bookings/{booking}/receipt?token=...
     *
     * Same ownership rule as PaymongoQrPhController::createQr() — either
     * the logged-in owner, or a matching poll_token — so a guessed booking
     * id alone can't pull up someone else's receipt.
     */
    public function show(Request $request, Booking $booking)
    {
        $isOwner = auth()->check() && $booking->user_id === auth()->id();
        $queryToken = (string) $request->query('token', '');
        $hasValidToken = $booking->poll_token
            && $queryToken !== ''
            && hash_equals($booking->poll_token, $queryToken);

        if (! $isOwner && ! $hasValidToken) {
            abort(403);
        }

        $paymentReference = $booking->paymentReference;

        if (! $paymentReference || ! $paymentReference->isConfirmed()) {
            return redirect()
                ->route('guest.book.waiting', [
                    'booking' => $booking,
                    'token'   => $hasValidToken ? $queryToken : $booking->poll_token,
                ])
                ->with('status', 'Your payment hasn\'t been confirmed yet.');
        }

        $bookings = $paymentReference->bookings()
            ->with('court')
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        return view('receipt', [
            'paymentReference' => $paymentReference,
            'bookings' => $bookings,
        ]);
    }
}