<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(): View
    {
        return view('admin.customers.index');
    }

    /**
     * Ranked customer list, most bookings first. A "customer" here is
     * an identity, not a booking row:
     *  - Registered users are grouped by their account (user_id).
     *  - Guests (no account — the guest-booking flow doesn't require
     *    login) have nothing to group by except what they typed in, so
     *    their bookings are clustered by shared email OR phone number,
     *    transitively: if booking A and B share an email, and B and C
     *    share a phone, A/B/C all count as the same guest.
     */
    public function data(): JsonResponse
    {
        $bookings = Booking::select([
                'id', 'user_id', 'customer_name', 'contact_number', 'email',
                'court_id', 'date', 'start_time', 'end_time', 'amount',
                'status', 'payment_method',
            ])
            ->with('court:id,name')
            ->get();

        $registered = $bookings->whereNotNull('user_id');
        $guests = $bookings->whereNull('user_id');

        $customers = collect();

        foreach ($registered->groupBy('user_id') as $userId => $rows) {
            $customers->push($this->buildCustomer('user-' . $userId, 'registered', $rows));
        }

        foreach ($this->clusterGuestBookings($guests) as $rows) {
            $customers->push($this->buildCustomer('guest-' . $rows->first()->id, 'guest', $rows));
        }

        $sorted = $customers
            ->sort(fn ($a, $b) => [$b['bookings_count'], $b['last_booking_date']] <=> [$a['bookings_count'], $a['last_booking_date']])
            ->values()
            ->map(function ($c, $i) {
                $c['rank'] = $i + 1;
                return $c;
            });

        return response()->json(['customers' => $sorted]);
    }

    private function normalizeEmail(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_strtolower($value);
    }

    private function normalizePhone(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);
        return $digits === '' ? null : $digits;
    }

    /**
     * Union-find clustering of guest bookings by shared (normalized)
     * email or phone. Returns a list of booking collections, one per
     * distinct guest identity.
     */
    private function clusterGuestBookings(Collection $guests): array
    {
        $ids = $guests->pluck('id')->all();
        if (empty($ids)) {
            return [];
        }

        $parent = array_combine($ids, $ids);

        $find = function ($x) use (&$parent, &$find) {
            if ($parent[$x] !== $x) {
                $parent[$x] = $find($parent[$x]);
            }
            return $parent[$x];
        };
        $union = function ($a, $b) use (&$parent, $find) {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$ra] = $rb;
            }
        };

        $seenByEmail = [];
        $seenByPhone = [];

        foreach ($guests as $booking) {
            $email = $this->normalizeEmail($booking->email);
            $phone = $this->normalizePhone($booking->contact_number);

            if ($email !== null) {
                if (isset($seenByEmail[$email])) {
                    $union($booking->id, $seenByEmail[$email]);
                } else {
                    $seenByEmail[$email] = $booking->id;
                }
            }

            if ($phone !== null) {
                if (isset($seenByPhone[$phone])) {
                    $union($booking->id, $seenByPhone[$phone]);
                } else {
                    $seenByPhone[$phone] = $booking->id;
                }
            }
        }

        $groups = [];
        foreach ($guests as $booking) {
            $groups[$find($booking->id)][] = $booking;
        }

        return array_map(fn ($rows) => collect($rows), array_values($groups));
    }

    private function buildCustomer(string $key, string $type, Collection $rows): array
    {
        $rows = $rows->sortByDesc(fn ($b) => $b->date->toDateString() . ' ' . $b->start_time)->values();
        $latest = $rows->first();
        $paidRows = $rows->where('status', 'paid');

        return [
            'key' => $key,
            'type' => $type,
            'name' => $latest->customer_name ?: ($type === 'registered' ? 'Registered User' : 'Guest'),
            'email' => $latest->email,
            'phone' => $latest->contact_number,
            'bookings_count' => $rows->count(),
            'total_spent' => round((float) $paidRows->sum('amount'), 2),
            'last_booking_date' => optional($latest->date)->toDateString(),
            'bookings' => $rows->map(fn ($b) => [
                'id' => $b->id,
                'court' => optional($b->court)->name ?? 'Court',
                'date' => optional($b->date)->format('M d, Y'),
                'time' => Carbon::parse($b->start_time)->format('g:i A') . ' – ' . Carbon::parse($b->end_time)->format('g:i A'),
                'amount' => (float) $b->amount,
                'status' => $b->status,
                'payment_method' => $b->payment_method,
            ])->all(),
        ];
    }
}
