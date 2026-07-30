<?php

namespace App\Http\Controllers\Admin;

use App\Models\Court;
use App\Http\Controllers\Controller;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CourtController extends Controller
{
    /**
     * Display a listing of the courts.
     */
    public function index(): View
    {
        $courts = Court::orderBy('name')->get();

        return view('admin.courts.index', compact('courts'));
    }

    /**
     * Store a newly created court.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateCourt($request);

        $court = Court::create($validated);

        ActivityLogger::log(
            'court.created',
            auth()->user()->name . " added court \"{$court->name}\".",
            subject: $court,
            properties: $validated,
        );

        return redirect()
            ->route('courts.index')
            ->with('success', 'Court added successfully.');
    }

    /**
     * Update the specified court.
     */
    public function update(Request $request, Court $court): RedirectResponse
    {
        $validated = $this->validateCourt($request);

        $court->update($validated);

        ActivityLogger::log(
            'court.updated',
            auth()->user()->name . " updated court \"{$court->name}\".",
            subject: $court,
            properties: ['changed_fields' => array_keys($court->getChanges())],
        );

        return redirect()
            ->route('courts.index')
            ->with('success', 'Court updated successfully.');
    }

    /**
     * Remove the specified court.
     */
    public function destroy(Court $court): RedirectResponse
    {
        $courtName = $court->name;

        $court->delete();

        ActivityLogger::log(
            'court.deleted',
            auth()->user()->name . " deleted court \"{$courtName}\".",
            subject: $court,
        );

        return redirect()
            ->route('courts.index')
            ->with('success', 'Court deleted successfully.');
    }

    /**
     * Shared validation rules for store/update.
     */
    private function validateCourt(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'width' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'length' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'surface_type' => ['nullable', 'string', 'max:255'],
            'hourly_rate' => ['required', 'numeric', 'min:0'],
            'status' => ['required', 'in:active,maintenance,inactive'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}