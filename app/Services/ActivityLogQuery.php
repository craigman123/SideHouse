<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class ActivityLogQuery
{
    private const STAFF_BOOKING_ACTIONS = [
        'booking.created',
        'booking.walk_in_created',
        'booking.admin_updated',
        'booking.cancelled',
        'booking.cancelled_all',
        'booking.admin_refund_review',
        'booking.admin_extension_overlap',
        'schedule.manual_booking_created',
    ];

    private const STAFF_CALENDAR_ACTIONS = [
        'court_block.created',
        'court_block.updated',
        'court_block.deleted',
    ];

    private const STAFF_CUSTOMER_ACTIONS = [
        'customer.created',
        'customer.updated',
        'customer.deleted',
    ];

    public function forAdmin(Request $request): LengthAwarePaginator
    {
        return ActivityLog::query()
            ->when($request->filled('user'), fn ($query) => $query->where('user_name', 'like', '%'.$request->user.'%'))
            ->when($request->filled('action'), fn ($query) => $query->where('action', 'like', '%'.$request->action.'%'))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();
    }

    public function forStaff(Request $request): LengthAwarePaginator
    {
        $filters = $request->validate([
            'user' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = ActivityLog::query()
            ->select(['id', 'user_name', 'action', 'subject_type', 'subject_id', 'description', 'created_at'])
            ->where(function (Builder $visible) {
                $visible->where(function (Builder $bookingLogs) {
                    $bookingLogs->whereIn('action', self::STAFF_BOOKING_ACTIONS)
                        ->where('subject_type', 'Booking');
                    $this->whereBookingBelongsToCustomerOrGuest($bookingLogs);
                })->orWhere(function (Builder $calendarLogs) {
                    $calendarLogs->whereIn('action', self::STAFF_CALENDAR_ACTIONS)
                        ->where('subject_type', 'CourtBlock');
                    $this->whereCourtBlockIsStaffVisible($calendarLogs);
                })->orWhere(function (Builder $customerLogs) {
                    $customerLogs->whereIn('action', self::STAFF_CUSTOMER_ACTIONS)
                        ->where('subject_type', 'User');
                    $this->whereSubjectIsRegisteredCustomer($customerLogs);
                })->orWhere(function (Builder $overrideLogs) {
                    $overrideLogs->where('action', 'calendar.admin_override')
                        ->where(function (Builder $subject) {
                            $subject->where(function (Builder $booking) {
                                $booking->where('subject_type', 'Booking');
                                $this->whereBookingBelongsToCustomerOrGuest($booking);
                            })->orWhere(function (Builder $block) {
                                $block->where('subject_type', 'CourtBlock');
                                $this->whereCourtBlockIsStaffVisible($block);
                            });
                        });
                });
            })
            ->when(trim((string) ($filters['user'] ?? '')) !== '', function (Builder $query) use ($filters) {
                $query->whereRaw("LOWER(user_name) LIKE ? ESCAPE '!'", [$this->likePattern($filters['user'])]);
            })
            ->when(trim((string) ($filters['action'] ?? '')) !== '', function (Builder $query) use ($filters) {
                $query->whereRaw("LOWER(action) LIKE ? ESCAPE '!'", [$this->likePattern($filters['action'])]);
            })
            ->orderByDesc('created_at');

        return $query->paginate(25)->withQueryString();
    }

    private function whereBookingBelongsToCustomerOrGuest(Builder $query): void
    {
        $query->whereExists(function ($booking) {
            $booking->selectRaw('1')
                ->from('bookings as staff_visible_booking')
                ->leftJoin('users as staff_visible_customer', 'staff_visible_customer.user_id', '=', 'staff_visible_booking.user_id')
                ->whereRaw('CAST(staff_visible_booking.id AS TEXT) = activity_logs.subject_id')
                ->where(function ($identity) {
                    $identity->whereNull('staff_visible_booking.user_id')
                        ->orWhere('staff_visible_customer.role', 'user');
                });
        });
    }

    private function whereCourtBlockIsStaffVisible(Builder $query): void
    {
        $query->whereExists(function ($block) {
            $block->selectRaw('1')
                ->from('court_blocks as staff_visible_block')
                ->whereRaw('CAST(staff_visible_block.id AS TEXT) = activity_logs.subject_id')
                ->whereIn('staff_visible_block.type', ['closed', 'maintenance', 'open_play']);
        });
    }

    private function whereSubjectIsRegisteredCustomer(Builder $query): void
    {
        $query->whereExists(function ($customer) {
            $customer->selectRaw('1')
                ->from('users as staff_visible_customer')
                ->whereRaw('CAST(staff_visible_customer.user_id AS TEXT) = activity_logs.subject_id')
                ->where('staff_visible_customer.role', 'user');
        });
    }

    private function likePattern(string $value): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($value))).'%';
    }
}
