<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Admin\BookingController as SharedBookingController;
use App\Http\Controllers\Controller;
use App\Services\AdminBookingsCalendarData;
use App\Services\UserAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;

class BookingsController extends Controller
{
    public function index(Request $request, SharedBookingController $shared, AdminBookingsCalendarData $calendarData, UserAccessService $access): View
    {
        $data = $shared->index($request, $calendarData)->getData();
        $data['permissions'] = $access->flagsFor($request->user());
        $data['showFinancials'] = false;
        $data['courtBoardBookings'] = $data['courtBoardBookings']->map(
            fn (array $booking) => Arr::except($booking, ['amount'])
        );
        if (! $data['permissions']['bookings.create']) {
            $data['courts'] = $data['courts']->map(fn ($court) => ['id' => $court->id, 'name' => $court->name]);
            foreach (['peak_adjustment_type', 'peak_adjustment_value'] as $field) {
                $data['businessSettings']->{$field} = null;
            }
        }

        return view('staff.bookings', $data);
    }
}
