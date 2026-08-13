<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingEquipment;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ReportController extends Controller
{
    /**
     * Payment methods currently supported by the booking flow.
     * Kept here so labels stay in sync with GuestBookingController/guest-book.js.
     */
    private const PAYMENT_LABELS = [
        'gcash' => 'GCash',
        'landbank' => 'Landbank',
    ];

    /**
     * Renders the report page shell. All chart data is fetched
     * client-side from data() so the period filter can refresh
     * the charts without a full page reload.
     */
    public function index(): View
    {
        return view('admin.reports.index');
    }

    /**
     * JSON data for the report page — summary cards + everything the
     * pie/bar charts need. ?period= controls the trend range only;
     * the lifetime/today/this-month summary cards are unaffected by it.
     */
    public function data(Request $request): JsonResponse
    {
        $period = $request->query('period', 'month');
        if (!in_array($period, ['today', 'week', 'month', 'year'], true)) {
            $period = 'month';
        }

        $paidQuery = fn () => Booking::where('status', 'paid');

        $totalIncome = $paidQuery()->sum('amount');
        $todayIncome = $paidQuery()->whereDate('date', today())->sum('amount');
        $monthIncome = $paidQuery()
            ->whereYear('date', today()->year)
            ->whereMonth('date', today()->month)
            ->sum('amount');
        $paidBookingsCount = $paidQuery()->count();

        $equipmentIncome = (float) BookingEquipment::whereHas('booking', function ($q) {
            $q->where('status', 'paid');
        })->selectRaw('COALESCE(SUM(quantity * price_each), 0) as total')->value('total');

        $courtIncome = max(0, (float) $totalIncome - $equipmentIncome);

        return response()->json([
            'summary' => [
                'total_income' => (float) $totalIncome,
                'today_income' => (float) $todayIncome,
                'month_income' => (float) $monthIncome,
                'paid_bookings' => $paidBookingsCount,
            ],
            'income_split' => [
                'court' => round($courtIncome, 2),
                'equipment' => round($equipmentIncome, 2),
            ],
            'by_payment_method' => $this->incomeByPaymentMethod(),
            'by_court' => $this->incomeByCourt(),
            'trend' => $this->incomeTrend($period),
        ]);
    }

    /**
     * Pie chart: income share per payment method (GCash vs Landbank —
     * extend PAYMENT_LABELS above if a new method is added later).
     */
    private function incomeByPaymentMethod(): array
    {
        $rows = Booking::where('status', 'paid')
            ->select('payment_method', DB::raw('SUM(amount) as total'))
            ->groupBy('payment_method')
            ->get();

        return $rows->map(fn ($row) => [
            'method' => $row->payment_method,
            'label' => self::PAYMENT_LABELS[$row->payment_method] ?? ucfirst((string) $row->payment_method),
            'total' => (float) $row->total,
        ])->values()->all();
    }

    /**
     * Pie chart: income share per court. Only one court exists today,
     * but CourtController already supports adding more, so this stays
     * grouped by court rather than hardcoded to a single slice.
     */
    private function incomeByCourt(): array
    {
        $rows = Booking::where('bookings.status', 'paid')
            ->join('courts', 'courts.id', '=', 'bookings.court_id')
            ->select('courts.name', DB::raw('SUM(bookings.amount) as total'))
            ->groupBy('courts.id', 'courts.name')
            ->orderByDesc('total')
            ->get();

        return $rows->map(fn ($row) => [
            'name' => $row->name,
            'total' => (float) $row->total,
        ])->values()->all();
    }

    /**
     * Bar chart: income over time. 'today' = hour-by-hour for today,
     * 'week' = last 7 days, 'month' = last 30 days, 'year' = last 12
     * months. Missing hours/dates/months are filled with 0 so the bars
     * stay evenly spaced instead of skipping gaps.
     */
    private function incomeTrend(string $period): array
    {
        if ($period === 'today') {
            return $this->hourlyTrendToday();
        }

        if ($period === 'year') {
            return $this->monthlyTrend();
        }

        $days = $period === 'week' ? 7 : 30;

        return $this->dailyTrend($days);
    }

    /**
     * Hourly breakdown of today's paid income, bucketed by each
     * booking's slot start_time (not payment confirmation time) so it
     * lines up with how "today" is defined elsewhere in this
     * controller — bookings scheduled for today, same as $todayIncome
     * above and Admin_DashboardController's $todayBookings.
     *
     * Grouped in PHP rather than SQL's HOUR() — that function is
     * MySQL-only and throws on SQLite/Postgres, which is what was
     * actually breaking this period. A day's worth of paid bookings is
     * small, so pulling the rows and bucketing here is cheap and works
     * on any DB driver.
     */
    private function hourlyTrendToday(): array
    {
        $bookings = Booking::where('status', 'paid')
            ->whereDate('date', today())
            ->select('start_time', 'amount')
            ->get();

        $totals = array_fill(0, 24, 0.0);
        foreach ($bookings as $booking) {
            $hour = (int) Carbon::parse($booking->start_time)->format('G');
            $totals[$hour] += (float) $booking->amount;
        }

        $out = [];
        for ($h = 0; $h < 24; $h++) {
            $out[] = [
                'label' => Carbon::createFromTime($h, 0)->format('g A'),
                'total' => $totals[$h],
            ];
        }

        return $out;
    }

    private function dailyTrend(int $days): array
    {
        $start = today()->subDays($days - 1);

        $rows = Booking::where('status', 'paid')
            ->whereDate('date', '>=', $start)
            ->select('date', DB::raw('SUM(amount) as total'))
            ->groupBy('date')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->date)->toDateString());

        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i);
            $key = $date->toDateString();
            $out[] = [
                'label' => $date->format('M j'),
                'total' => isset($rows[$key]) ? (float) $rows[$key]->total : 0.0,
            ];
        }

        return $out;
    }

    /**
     * Same driver-agnostic approach as hourlyTrendToday() above —
     * DATE_FORMAT() is MySQL-only and would throw the same way on
     * SQLite/Postgres, so this groups by month in PHP instead.
     */
    private function monthlyTrend(): array
    {
        $start = today()->startOfMonth()->subMonths(11);

        $bookings = Booking::where('status', 'paid')
            ->whereDate('date', '>=', $start)
            ->select('date', 'amount')
            ->get();

        $totals = [];
        foreach ($bookings as $booking) {
            $key = Carbon::parse($booking->date)->format('Y-m');
            $totals[$key] = ($totals[$key] ?? 0.0) + (float) $booking->amount;
        }

        $out = [];
        for ($i = 0; $i < 12; $i++) {
            $month = $start->copy()->addMonths($i);
            $key = $month->format('Y-m');
            $out[] = [
                'label' => $month->format('M Y'),
                'total' => $totals[$key] ?? 0.0,
            ];
        }

        return $out;
    }
}