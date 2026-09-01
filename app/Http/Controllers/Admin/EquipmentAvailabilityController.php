<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Support\ActivityLogger;
use App\Support\BookingHours;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EquipmentAvailabilityController extends Controller
{
    public function index(): View
    {
        return view('admin.equipment.equipment_availability');
    }

    /**
     * Adds a new rentable item. stock_total is the fixed owned count —
     * see Equipment::availableStock() for how "currently available" is
     * actually computed per date/time; this never writes to that logic,
     * only to how many are owned in the first place.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'stock_total' => ['required', 'integer', 'min:0'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $item = Equipment::create($validated);

        ActivityLogger::log(
            'equipment.created',
            auth()->user()->name . " added equipment \"{$item->name}\" (qty {$item->stock_total}).",
            subject: $item,
            properties: $validated,
        );

        return redirect()
            ->route('admin.equipment.availability')
            ->with('success', "\"{$item->name}\" added.");
    }

    /**
     * Edits an existing item. Returns JSON rather than redirecting —
     * unlike store() above (a plain page form), this is called from the
     * edit modal via fetch since the table itself is already fully
     * AJAX-rendered (see admin-equipment-availability.js); a redirect
     * would just be a wasted round trip before the JS re-fetches anyway.
     */
    public function update(Request $request, Equipment $equipment): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'stock_total' => ['required', 'integer', 'min:0'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $equipment->update($validated);

        ActivityLogger::log(
            'equipment.updated',
            auth()->user()->name . " updated equipment \"{$equipment->name}\".",
            subject: $equipment,
            properties: ['changed_fields' => array_keys($equipment->getChanges())],
        );

        return response()->json([
            'message' => "\"{$equipment->name}\" updated.",
            'equipment' => $equipment,
        ]);
    }

    /**
     * Removes an item entirely. Called from the delete modal via fetch,
     * same as update() — JSON response, JS re-fetches the table after.
     */
    public function destroy(Equipment $equipment): JsonResponse
    {
        $name = $equipment->name;

        ActivityLogger::log(
            'equipment.deleted',
            auth()->user()->name . " deleted equipment \"{$name}\".",
            subject: $equipment,
        );

        $equipment->delete();

        return response()->json([
            'message' => "\"{$name}\" deleted.",
        ]);
    }

    /**
     * Per-item availability for one date: how many are owned, the worst-
     * case (peak-reserved) point during that day's operating hours, and
     * what's left at that peak. "Available today" is intentionally the
     * *minimum* across the day's slots, not a single snapshot — the same
     * physical item can be rented 9-10 AM and again 3-4 PM by different
     * customers, so there's no single "reserved count" for the whole day,
     * only a worst point admin actually cares about (would I have run out
     * at some point today?).
     */
    public function data(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
        ]);

        $date = $validated['date'];
        $windows = $this->dayWindows($date);

        $equipment = Equipment::orderBy('category')->orderBy('name')->get();

        $rows = $equipment->map(function (Equipment $item) use ($date, $windows) {
            $availableToday = $windows->isEmpty()
                ? $item->stock_total
                : $windows->map(fn ($w) => $item->availableStock($date, $w[0], $w[1]))->min();

            return [
                'id' => $item->id,
                'name' => $item->name,
                'category' => $item->category,
                'status' => $item->status,
                'price' => (float) $item->price,
                'stock_total' => $item->stock_total,
                'available_today' => $availableToday,
                'reserved_peak' => max(0, $item->stock_total - $availableToday),
            ];
        })->values();

        return response()->json([
            'date' => $date,
            'equipment' => $rows,
        ]);
    }

    /**
     * The date's operating-hour windows in BookingHours::stepMinutes()
     * increments — same open/close/step config the booking flow itself
     * uses, so "availability" here means the same thing it means to a
     * guest picking that date.
     */
    private function dayWindows(string $date): \Illuminate\Support\Collection
    {
        $openHour = BookingHours::openHour();
        $closeHour = BookingHours::closeHour();
        $stepMinutes = BookingHours::stepMinutes();
        $overnight = $closeHour <= $openHour;

        $start = Carbon::parse($date)->setTime($openHour, 0);
        $end = Carbon::parse($date)->setTime($closeHour, 0);
        if ($overnight) {
            $end->addDay();
        }

        $windows = collect();
        $cursor = $start->copy();
        while ($cursor->lt($end)) {
            $slotEnd = $cursor->copy()->addMinutes($stepMinutes);
            if ($slotEnd->gt($end)) {
                $slotEnd = $end->copy();
            }
            $windows->push([$cursor->format('H:i:s'), $slotEnd->format('H:i:s')]);
            $cursor = $slotEnd;
        }

        return $windows;
    }
}