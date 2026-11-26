<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingEquipment;
use App\Models\Court;
use App\Models\CourtBlock;
use App\Models\CourtClosure;
use App\Services\AdminBookingsCalendarData;
use App\Services\UserAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OverviewController extends Controller
{
    public function index(Request $request, UserAccessService $access, AdminBookingsCalendarData $calendarData): View
    {
        $canViewBookings = $access->allows($request->user(), 'bookings.view');

        return view('staff.overview', [
            'user' => $request->user(),
            'canViewBookings' => $canViewBookings,
            'canCreateOpenPlay' => $access->allows($request->user(), 'calendar_events.open_play'),
            'overviewData' => $canViewBookings ? $this->todayData($calendarData) : [],
        ]);
    }

    public function data(Request $request, UserAccessService $access, AdminBookingsCalendarData $calendarData): JsonResponse
    {
        if (! $access->allows($request->user(), 'bookings.view')) {
            return response()->json(['message' => 'You are not allowed to view booking data.'], 403);
        }

        return response()->json($this->todayData($calendarData));
    }

    private function todayData(AdminBookingsCalendarData $calendarData): array
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

        $openPlay = $this->nextOpenPlay($now, $calendarData);

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

    private function nextOpenPlay(CarbonImmutable $now, AdminBookingsCalendarData $calendarData): ?array
    {
        $sessions = CourtBlock::query()
            ->select(['id', 'court_id', 'type', 'title', 'block_date', 'start_time', 'end_time'])
            ->with('court:id,name')
            ->where('type', 'open_play')
            ->whereDate('block_date', '>=', $now->subDay()->toDateString())
            ->orderBy('block_date')
            ->orderBy('start_time')
            ->get()
            ->map(function (CourtBlock $block): array {
                $date = $block->block_date->toDateString();
                $start = CarbonImmutable::parse($date.' '.substr((string) $block->start_time, 0, 8), 'Asia/Manila');
                $end = CarbonImmutable::parse($date.' '.substr((string) $block->end_time, 0, 8), 'Asia/Manila');
                if ($end->lessThanOrEqualTo($start)) {
                    $end = $end->addDay();
                }

                return ['block' => $block, 'start' => $start, 'end' => $end];
            });

        $active = $sessions->first(fn (array $session) => $session['start']->lessThanOrEqualTo($now)
            && $session['end']->greaterThan($now));
        $selected = $active ?? $sessions->first(fn (array $session) => $session['start']->greaterThan($now));
        if ($selected === null) {
            return null;
        }

        $block = $selected['block'];
        $date = $block->block_date->toDateString();
        $calendarDate = CarbonImmutable::parse($date, 'Asia/Manila');
        $calendarDataForDate = $calendarData->forRange($calendarDate, $calendarDate);
        $calendarBlock = collect($calendarDataForDate['blocks'])
            ->first(fn (array $item) => (string) $item['id'] === (string) $block->id);
        $participants = collect($calendarBlock['participants'] ?? [])
            ->pluck('name')
            ->values();
        $isToday = $date === $now->toDateString();

        return [
            'id' => $block->id,
            'title' => $block->title ?: 'Open play',
            'date' => $date,
            'date_label' => $calendarDate->format('l, F j, Y'),
            'time' => $selected['start']->format('g:i A').' – '.$selected['end']->format('g:i A'),
            'court' => $block->court?->name ?? ($block->court_id === null ? 'All courts' : 'Court '.$block->court_id),
            'status' => $active !== null ? 'active' : ($isToday ? 'upcoming' : 'soon'),
            'is_today' => $isToday,
            'participant_count' => $participants->count(),
            'participants' => $participants->take(3)->all(),
        ];
    }
}
