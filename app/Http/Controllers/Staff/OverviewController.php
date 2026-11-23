<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingEquipment;
use App\Models\Court;
use App\Models\CourtBlock;
use App\Models\CourtClosure;
use App\Services\UserAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OverviewController extends Controller
{
    public function index(Request $request, UserAccessService $access): View
    {
        $canViewBookings = $access->allows($request->user(), 'bookings.view');

        return view('staff.overview', [
            'user' => $request->user(),
            'canViewBookings' => $canViewBookings,
            'canCreateOpenPlay' => $access->allows($request->user(), 'calendar_events.open_play'),
            'overviewData' => $canViewBookings ? $this->todayData() : [],
        ]);
    }

    public function data(Request $request, UserAccessService $access): JsonResponse
    {
        if (! $access->allows($request->user(), 'bookings.view')) {
            return response()->json(['message' => 'You are not allowed to view booking data.'], 403);
        }

        return response()->json($this->todayData());
    }

    private function todayData(): array
    {
        $now = CarbonImmutable::now('Asia/Manila');
        $today = $now->toDateString();
        $todayBookings = Booking::with('court')
            ->whereDate('date', $today)
            ->whereNotIn('status', ['cancelled', 'expired'])
            ->orderBy('start_time')
            ->get();

        $upcoming = $todayBookings
            ->filter(fn (Booking $booking) => CarbonImmutable::parse($booking->date->toDateString().' '.$booking->start_time, 'Asia/Manila')->greaterThanOrEqualTo($now))
            ->take(5)
            ->map(fn (Booking $booking) => [
                'customer' => $booking->customer_name ?: 'Customer',
                'time' => CarbonImmutable::parse($booking->start_time)->format('g:i A')
                    .' – '.CarbonImmutable::parse($booking->end_time)->format('g:i A'),
                'court' => $booking->court?->name ?? ('Court '.$booking->court_id),
                'status' => ucfirst($booking->status),
            ])->values();

        $needsAttention = $todayBookings
            ->where('status', 'pending')
            ->take(5)
            ->map(fn (Booking $booking) => [
                'customer' => $booking->customer_name ?: 'Customer',
                'time' => CarbonImmutable::parse($booking->start_time)->format('g:i A')
                    .' – '.CarbonImmutable::parse($booking->end_time)->format('g:i A'),
                'court' => $booking->court?->name ?? ('Court '.$booking->court_id),
                'status' => 'Pending payment',
            ])->values();

        $blocks = CourtBlock::with('court')
            ->whereDate('block_date', $today)
            ->whereIn('type', ['open_play', 'closed', 'maintenance'])
            ->orderBy('start_time')
            ->get();

        $openPlay = $blocks->where('type', 'open_play')->map(fn (CourtBlock $block) => [
            'title' => $block->title ?: 'Open play',
            'time' => CarbonImmutable::parse($block->start_time)->format('g:i A')
                .' – '.CarbonImmutable::parse($block->end_time)->format('g:i A'),
            'court' => $block->court?->name ?? 'All courts',
        ])->values();

        $closures = CourtClosure::whereDate('date', $today)->get();
        $nowTime = $now->format('H:i:s');
        $currentBookings = $todayBookings->filter(fn (Booking $booking) =>
            CarbonImmutable::parse($booking->start_time)->format('H:i:s') <= $nowTime
            && CarbonImmutable::parse($booking->end_time)->format('H:i:s') > $nowTime
        );
        $equipmentPrep = BookingEquipment::with('equipment')
            ->whereHas('booking', fn ($query) => $query->whereDate('date', $today)->whereNotIn('status', ['cancelled', 'expired']))
            ->get()
            ->groupBy(fn (BookingEquipment $rental) => $rental->equipment?->name ?? 'Equipment')
            ->map(fn ($rentals, $name) => ['name' => $name, 'quantity' => $rentals->sum('quantity')])
            ->values();

        $courts = Court::orderBy('name')->get()->map(function (Court $court) use ($blocks, $closures, $currentBookings, $nowTime) {
            $closure = $closures->first(fn (CourtClosure $item) => $item->court_id === null || (int) $item->court_id === (int) $court->id);
            $block = $blocks->first(fn (CourtBlock $item) =>
                ($item->court_id === null || (int) $item->court_id === (int) $court->id)
                && CarbonImmutable::parse($item->start_time)->format('H:i:s') <= $nowTime
                && CarbonImmutable::parse($item->end_time)->format('H:i:s') > $nowTime
            );
            $booking = $currentBookings->first(fn (Booking $item) => (int) $item->court_id === (int) $court->id);
            $status = match (true) {
                $court->status === 'maintenance' => 'Maintenance',
                $court->status !== 'active' => 'Offline',
                $closure !== null => 'Closed',
                $block?->type === 'maintenance' => 'Maintenance',
                $block?->type === 'closed' => 'Closed',
                $block?->type === 'open_play' => 'Open play',
                $booking !== null => 'Booked',
                default => 'Available',
            };

            return ['name' => $court->name, 'status' => $status];
        })->values();

        return [
            'date' => $now->format('l, F j, Y'),
            'booking_count' => $todayBookings->count(),
            'court_count' => $courts->where('status', 'Booked')->count(),
            'courts' => $courts,
            'upcoming' => $upcoming,
            'needs_attention' => $needsAttention,
            'open_play' => $openPlay,
            'equipment_prep' => $equipmentPrep,
            'synced_at' => 'just now',
        ];
    }
}
