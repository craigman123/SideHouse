<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\CourtBlock;
use App\Models\CourtClosure;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OverviewController extends Controller
{
    public function index(Request $request): View
    {
        return view('staff.overview', [
            'user' => $request->user(),
            'overviewData' => $this->todayData(),
        ]);
    }

    public function data(): JsonResponse
    {
        return response()->json($this->todayData());
    }

    private function todayData(): array
    {
        $now = CarbonImmutable::now('Asia/Manila');
        $today = $now->toDateString();
        $todayBookings = Booking::with('court')
            ->whereDate('date', $today)
            ->orderBy('start_time')
            ->get();

        $upcoming = $todayBookings
            ->filter(function (Booking $booking) use ($now) {
                if (in_array($booking->status, ['cancelled', 'expired'], true)) {
                    return false;
                }

                return CarbonImmutable::parse($booking->date->toDateString().' '.$booking->start_time, 'Asia/Manila')
                    ->greaterThanOrEqualTo($now);
            })
            ->take(5)
            ->map(fn (Booking $booking) => [
                'customer' => $booking->customer_name ?: 'Customer',
                'time' => CarbonImmutable::parse($booking->start_time)->format('g:i A')
                    .' – '.CarbonImmutable::parse($booking->end_time)->format('g:i A'),
                'court' => $booking->court?->name ?? ('Court '.$booking->court_id),
                'status' => ucfirst($booking->status),
            ])->values();

        $events = CourtBlock::with('court')
            ->whereDate('block_date', $today)
            ->whereIn('type', ['open_play', 'closed', 'maintenance'])
            ->orderBy('start_time')
            ->get()
            ->map(fn (CourtBlock $block) => [
                'type' => $block->type,
                'title' => $block->title ?: ucfirst(str_replace('_', ' ', $block->type)),
                'time' => CarbonImmutable::parse($block->start_time)->format('g:i A')
                    .' – '.CarbonImmutable::parse($block->end_time)->format('g:i A'),
                'court' => $block->court?->name ?? 'All courts',
                'joined' => $block->getAttribute('participant_count'),
                'capacity' => $block->getAttribute('capacity'),
            ])->values();

        $closures = CourtClosure::with('court')->whereDate('date', $today)->get()->map(fn (CourtClosure $closure) => [
            'type' => 'closed',
            'title' => $closure->reason ?: 'Closed',
            'time' => 'All day',
            'court' => $closure->court?->name ?? 'All courts',
        ])->values();

        return [
            'date' => $now->format('l, F j, Y'),
            'booking_count' => $todayBookings->count(),
            'upcoming' => $upcoming,
            'events' => $events->concat($closures)->values(),
            'synced_at' => $now->format('g:i:s A'),
        ];
    }
}
