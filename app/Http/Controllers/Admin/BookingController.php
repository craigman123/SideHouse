<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtClosure;
use Carbon\Carbon;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        $bookings = Booking::query()
            ->status($request->status)
            ->search($request->search)
            ->onDate($request->date)
            ->orderBy('date', 'desc')
            ->orderBy('start_time', 'desc')
            ->paginate(15)
            ->withQueryString();

        // The table above remains paginated and filterable. The court board
        // needs a small, date-windowed dataset instead so its day switcher
        // can update instantly without replacing the page.
        $boardStart = Carbon::today()->subDay(); // includes overnight tails
        $boardEnd = Carbon::today()->addMonths(6);

        $courtBoardBookings = Booking::with(['court', 'slots', 'equipment.equipment'])
            ->whereBetween('date', [$boardStart, $boardEnd])
            ->orderBy('date')
            ->orderBy('start_time')
            ->get()
            ->map(fn (Booking $booking) => [
                'id'       => $booking->id,
                'customer' => $booking->customer_name,
                'court_id' => $booking->court_id,
                'court'    => $booking->court?->name ?? ('Court ' . $booking->court_id),
                'date'     => $booking->date?->toDateString(),
                'start'    => substr((string) $booking->start_time, 0, 5),
                'end'      => substr((string) $booking->end_time, 0, 5),
                'status'   => $booking->status,
                'amount'   => (float) $booking->amount,
                'slots'    => $booking->slots->map(fn ($slot) => [
                    'date'  => $slot->date?->toDateString(),
                    'start' => substr((string) $slot->start_time, 0, 5),
                    'end'   => substr((string) $slot->end_time, 0, 5),
                ])->values(),
                'equipment' => $booking->equipment->map(fn ($line) => [
                    'name'     => $line->equipment?->name ?? 'Equipment',
                    'quantity' => $line->quantity,
                ])->values(),
            ])
            ->values();

        $courtBoardClosures = CourtClosure::whereBetween('date', [$boardStart, $boardEnd])
            ->get()
            ->map(fn (CourtClosure $closure) => [
                'court_id' => $closure->court_id,
                'date'     => $closure->date->toDateString(),
                'reason'   => $closure->reason,
            ])
            ->values();

        $courts = Court::orderBy('name')->get(['id', 'name']);

        return view('admin.bookings.index', compact(
            'bookings', 'courts', 'courtBoardBookings', 'courtBoardClosures'
        ));
    }
}
