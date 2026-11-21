@extends('layouts.app')

@section('title', 'Bookings | Side House')
@section('page-title', 'Bookings')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/user/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/booking/bookings.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin/admin-bookings-board.css') . '?v=' . filemtime(public_path('css/admin/admin-bookings-board.css')) }}">
@endpush

@section('content')

    <div class="admin-bookings-page">
        <header class="admin-bookings-heading">
            <h2>Manage <span>bookings</span></h2>
            <p>Review reservations, payment status, and court schedules.</p>
        </header>

    @php
        $bookingListQueryKeys = ['search', 'date', 'status', 'page'];
        $hasBookingListState = collect($bookingListQueryKeys)->contains(fn ($key) => request()->query->has($key));
        $requestedBookingsView = request()->query('view');
        $initialBookingsView = in_array($requestedBookingsView, ['list', 'board', 'month', 'week'], true)
            ? $requestedBookingsView
            : ($hasBookingListState ? 'list' : 'month');
    @endphp

    <div class="bookings-workspace" id="bookingsWorkspace">
        <div class="bookings-view-tabs" role="tablist" aria-label="Bookings views">
            <button type="button" class="bookings-view-tab {{ $initialBookingsView === 'list' ? 'is-active' : '' }}" role="tab" aria-selected="{{ $initialBookingsView === 'list' ? 'true' : 'false' }}" aria-controls="bookingListView" data-bookings-view="list">
                Booking list
            </button>
            <button type="button" class="bookings-view-tab {{ $initialBookingsView === 'board' ? 'is-active' : '' }}" role="tab" aria-selected="{{ $initialBookingsView === 'board' ? 'true' : 'false' }}" aria-controls="courtBoardView" data-bookings-view="board">
                Daily court board
            </button>
            <button type="button" class="bookings-view-tab {{ $initialBookingsView === 'month' ? 'is-active' : '' }}" role="tab" aria-selected="{{ $initialBookingsView === 'month' ? 'true' : 'false' }}" aria-controls="courtMonthView" data-bookings-view="month">
                Month view
            </button>
            <button type="button" class="bookings-view-tab {{ $initialBookingsView === 'week' ? 'is-active' : '' }}" role="tab" aria-selected="{{ $initialBookingsView === 'week' ? 'true' : 'false' }}" aria-controls="courtWeekView" data-bookings-view="week">
                Week view
            </button>
        </div>

        <section id="bookingListView" class="bookings-view-panel" role="tabpanel" @if ($initialBookingsView !== 'list') hidden @endif>
            {{-- Existing filters and table are intentionally kept in this view. --}}
            <div class="panel filters-panel">
                <form method="GET" action="{{ route('bookings.index') }}" class="filters-form">

            <div class="filter-group">
                <label for="search">Customer</label>
                <input type="text" id="search" name="search" placeholder="Search by name..." value="{{ request('search') }}">
            </div>

            <div class="filter-group">
                <label for="date">Date</label>
                <input type="date" id="date" name="date" value="{{ request('date') }}">
            </div>

            <div class="filter-group">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="all" {{ request('status', 'all') === 'all' ? 'selected' : '' }}>All</option>
                    <option value="paid" {{ request('status') === 'paid' ? 'selected' : '' }}>Paid</option>
                    <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                </select>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn-filter">Filter</button>
                <a href="{{ route('bookings.index') }}" class="btn-clear">Clear</a>
            </div>

                </form>
            </div>

            <div class="panel bookings-table-panel">
                <h2>All Bookings <span class="count-badge">{{ $bookings->total() }}</span></h2>

                <div class="table-responsive">
                    <table>
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Court</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th class="booking-amount-heading">Amount</th>
                        <th>Payment method</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($bookings as $booking)
                        <tr>
                            <td class="cell-name" data-label="Customer">{{ $booking->customer_name }}</td>
                            <td data-label="Court">{{ $booking->court_id ? 'Court ' . $booking->court_id : '—' }}</td>
                            <td data-label="Date">{{ \Carbon\Carbon::parse($booking->date)->format('M d, Y') }}</td>
                            <td data-label="Time">{{ \Carbon\Carbon::parse($booking->start_time)->format('g:i A') }} - {{ \Carbon\Carbon::parse($booking->end_time)->format('g:i A') }}</td>
                            <td class="booking-amount" data-label="Amount">₱{{ number_format($booking->amount, 2) }}</td>
                            <td data-label="Payment method"><span class="payment-method-badge">{{ $booking->payment_method ?: 'Not recorded' }}</span></td>
                            <td data-label="Status">
                                <span class="status status-{{ $booking->status }}">
                                    {{ ucfirst($booking->status) }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="empty-row">No bookings found. <a href="{{ route('bookings.index') }}" class="reset-booking-filters">Reset filters</a></td>
                        </tr>
                    @endforelse
                </tbody>
                    </table>
                </div>

                <p style="text-align: center">Showing {{ $bookings->firstItem() }} to {{ $bookings->lastItem() }} of {{ $bookings->total() }} results</p>
                @if ($bookings->hasPages())
                    <div class="pagination-wrapper">
                        {{ $bookings->links() }}
                    </div>
                @endif
            </div>
        </section>

        <section id="courtBoardView" class="bookings-view-panel" role="tabpanel" @if ($initialBookingsView !== 'board') hidden @endif>
            <div class="panel court-board-panel">
                <div class="court-board-toolbar">
                    <div>
                        <p class="court-board-eyebrow">Operations view</p>
                        <h2>Daily court board</h2>
                        <p class="court-board-subtitle">Live view of bookings, closures, and rented equipment.</p>
                    </div>
                    <div class="court-board-controls" aria-label="Choose board date">
                        <button type="button" class="court-board-nav" data-board-date="previous" aria-label="Previous day">‹</button>
                        <input type="date" id="courtBoardDate" value="{{ request('date', $businessSettings->today) }}" min="{{ \Carbon\CarbonImmutable::parse($businessSettings->today, 'Asia/Manila')->subDay()->toDateString() }}" max="{{ \Carbon\CarbonImmutable::parse($businessSettings->today, 'Asia/Manila')->addMonths(6)->toDateString() }}">
                        <button type="button" class="court-board-nav" data-board-date="next" aria-label="Next day">›</button>
                        <button type="button" class="btn-clear court-board-today" data-board-date="today">Today</button>
                    </div>
                </div>

                <div class="court-board-legend" aria-label="Calendar item legend">
                    <span class="legend-booking">Booking</span><span data-calendar-legend-icon="lock" class="legend-closed">Closed</span><span data-calendar-legend-icon="wrench" class="legend-maintenance">Maintenance</span><span data-calendar-legend-icon="padel" class="legend-open-play">Open play</span>
                </div>

                <div id="courtBoardSummary" class="court-board-summary" aria-live="polite"></div>
                <div id="courtBoard" class="court-board" aria-live="polite"></div>
            </div>
        </section>
        <section id="courtMonthView" class="bookings-view-panel" role="tabpanel" @if ($initialBookingsView !== 'month') hidden @endif>
            <div class="panel court-board-panel">
                <div class="court-board-toolbar">
                    <div>
                        <p class="court-board-eyebrow">Operations view</p>
                        <h2>Month calendar</h2>
                        <p class="court-board-subtitle">Every booking for the month, laid out by date and time.</p>
                    </div>
                    <div class="court-board-controls" aria-label="Choose calendar month">
                        <button type="button" class="court-board-nav" data-month-nav="previous" aria-label="Previous month">‹</button>
                        <input type="month" id="courtMonthInput" value="{{ \Carbon\CarbonImmutable::parse($businessSettings->today, 'Asia/Manila')->format('Y-m') }}">
                        <button type="button" class="court-board-nav" data-month-nav="next" aria-label="Next month">›</button>
                        <button type="button" class="btn-clear court-board-today" data-month-nav="today">This month</button>
                    </div>
                </div>

                <div class="court-board-legend" aria-label="Calendar item legend">
                    <span class="legend-booking">Booking</span><span data-calendar-legend-icon="lock" class="legend-closed">Closed</span><span data-calendar-legend-icon="wrench" class="legend-maintenance">Maintenance</span><span data-calendar-legend-icon="padel" class="legend-open-play">Open play</span>
                </div>

                <div id="courtMonthSummary" class="court-board-summary" aria-live="polite"></div>
                <div class="court-month-scroll">
                    <div id="courtMonthGrid" class="court-month-grid" aria-live="polite"></div>
                </div>
            </div>
        </section>
        <section id="courtWeekView" class="bookings-view-panel" role="tabpanel" @if ($initialBookingsView !== 'week') hidden @endif>
            <div class="panel court-board-panel">
                <div class="court-board-toolbar">
                    <div>
                        <p class="court-board-eyebrow">Operations view</p>
                        <h2 id="courtWeekRangeHeading">Week calendar</h2>
                        <p class="court-board-subtitle">Bookings and court events for the selected week.</p>
                    </div>
                    <div class="court-board-controls" aria-label="Choose calendar week">
                        <button type="button" class="court-board-nav" data-week-nav="previous" aria-label="Previous week">‹</button>
                        <input type="date" id="courtWeekInput" value="{{ request('date', $businessSettings->today) }}" aria-label="Choose a date in the week">
                        <button type="button" class="court-board-nav" data-week-nav="next" aria-label="Next week">›</button>
                        <button type="button" class="btn-clear court-board-today" data-week-nav="today">This week</button>
                    </div>
                </div>
                <div class="court-board-legend" aria-label="Calendar item legend">
                    <span class="legend-booking">Booking</span><span data-calendar-legend-icon="lock" class="legend-closed">Closed</span><span data-calendar-legend-icon="wrench" class="legend-maintenance">Maintenance</span><span data-calendar-legend-icon="padel" class="legend-open-play">Open play</span>
                </div>
                <div id="courtWeekSummary" class="court-board-summary" aria-live="polite"></div>
                <div class="court-week-scroll court-month-scroll">
                    <div id="courtWeekGrid" class="court-month-grid court-week-grid" aria-live="polite"></div>
                </div>
            </div>
        </section>
    </div>

    <div class="court-block-modal-overlay" id="courtBlockModal" hidden>
        <section class="court-block-modal" role="dialog" aria-modal="true" aria-labelledby="courtBlockModalTitle">
            <button type="button" class="court-block-modal-close" id="courtBlockModalClose" aria-label="Close dialog">&times;</button>

            <div id="courtBlockFormPanel">
                <h2 id="courtBlockModalTitle">Add calendar event</h2>
                <p class="court-block-modal-intro">Choose what to apply to this time range.</p>
                <form id="courtBlockForm">
                    <div class="court-block-form-grid">
                        <label>Type
                            <select name="type" id="courtBlockType" required>
                                <option value="closed">Closed</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="open_play">Open play</option>
                            <option value="walk_in">Walk-in</option>
                            </select>
                        </label>
                        <label>Court
                            <select name="court_id" id="courtBlockCourt" required></select>
                        </label>
                        <label>Title
                            <input name="title" id="courtBlockTitle" type="text" maxlength="255" required>
                        </label>
                        <label class="walk-in-field" hidden>Customer name
                            <input name="customer_name" id="courtWalkInCustomer" type="text" maxlength="255">
                        </label>
                        <label class="walk-in-field" hidden>Contact number
                            <input name="contact_number" id="courtWalkInContact" type="tel" maxlength="30">
                        </label>
                        <label class="walk-in-field" hidden>Email (optional)
                            <input name="email" id="courtWalkInEmail" type="email" maxlength="255">
                        </label>
                        <label>Date
                            <input name="block_date" id="courtBlockDate" type="date" required>
                        </label>
                        <label>Start time
                            <input name="start_time" id="courtBlockStart" type="time" required>
                        </label>
                        <label>End time
                            <input name="end_time" id="courtBlockEnd" type="time" required>
                        </label>
                        <label class="walk-in-field" hidden>Amount
                            <input name="amount" id="courtWalkInAmount" type="number" min="0" step="0.01">
                        </label>
                        <label class="walk-in-field" hidden>Payment method
                            <select name="payment_method" id="courtWalkInPayment"><option value="cash">Cash</option><option value="gcash">GCash</option></select>
                        </label>
                        <label class="court-block-notes-field" data-block-field>Notes
                            <textarea name="notes" id="courtBlockNotes" rows="3" maxlength="5000"></textarea>
                        </label>
                        <label class="walk-in-field" hidden>Notes
                            <textarea name="walk_in_notes" id="courtWalkInNotes" rows="3" maxlength="5000"></textarea>
                        </label>
                    </div>
                    <p class="court-block-modal-message" id="courtBlockFormMessage" role="status" aria-live="polite"></p>
                    <div class="court-block-modal-actions">
                        <button type="button" class="court-block-secondary" id="courtBlockCancel">Cancel</button>
                        <button type="submit" class="court-block-primary" id="courtBlockSave">Save event</button>
                    </div>
                </form>
            </div>

            <div id="courtBlockDetailsPanel" hidden>
                <h2 id="courtBlockDetailsTitle"></h2>
                <p id="courtBlockDetailsMeta" class="court-block-modal-intro"></p>
                <p id="courtBlockDetailsNotes"></p>
                <p class="court-block-modal-message" id="courtBlockDeleteMessage" role="status" aria-live="polite"></p>
                <p class="court-past-edit-note" id="courtBlockPastNote" hidden>Past calendar events are read-only except for notes.</p>
                <div class="court-block-modal-actions">
                    <button type="button" class="court-block-secondary" id="courtBlockDetailsClose">Close</button>
                    <button type="button" class="court-block-secondary" id="courtBlockEditOpen">Edit event</button>
                    <button type="button" class="court-block-danger" id="courtBlockDelete">Delete event</button>
                </div>
            </div>

            <div id="courtBlockEditPanel" hidden>
                <h2 id="courtBlockEditTitle">Edit calendar event</h2>
                <form id="courtBlockEditForm">
                    <p class="court-past-edit-note" data-past-edit-note hidden>Past calendar events are read-only except for notes.</p>
                    <div class="court-block-form-grid">
                        <label>Type<select name="type" required><option value="closed">Closed</option><option value="maintenance">Maintenance</option><option value="open_play">Open play</option></select></label>
                        <label>Court<select name="court_id" required></select></label>
                        <label>Title<input name="title" type="text" maxlength="255" required></label>
                        <label>Date<input name="block_date" type="date" required></label>
                        <label>Start time<input name="start_time" type="time" required></label>
                        <label>End time<input name="end_time" type="time" required></label>
                        <label class="court-block-notes-field">Notes<textarea name="notes" rows="3" maxlength="5000"></textarea></label>
                    </div>
                    <p class="court-block-modal-message" id="courtBlockEditMessage" role="status" aria-live="polite"></p>
                    <div class="court-block-modal-actions">
                        <button type="button" class="court-block-secondary" id="courtBlockEditCancel">Back</button>
                        <button type="submit" class="court-block-primary">Save event</button>
                    </div>
                </form>
            </div>

            <div id="courtBookingDetailsPanel" hidden>
                <h2 id="courtBookingDetailsTitle">Booking details</h2>
                <p id="courtBookingDetailsMeta" class="court-block-modal-intro"></p>
                <p id="courtBookingDetailsNotes"></p>
                <p class="court-block-modal-message" id="courtBookingDetailsMessage" role="status" aria-live="polite"></p>
                <p class="court-past-edit-note" id="courtBookingPastNote" hidden>Past bookings can't be edited.</p>
                <div class="court-block-modal-actions">
                    <button type="button" class="court-block-secondary" id="courtBookingDetailsClose">Close</button>
                    <button type="button" class="court-block-primary" id="courtBookingEditOpen">Edit booking</button>
                </div>
            </div>

            <div id="courtBookingEditPanel" hidden>
                <h2 id="courtBookingEditTitle">Edit booking</h2>
                <form id="courtBookingEditForm">
                    <p class="court-past-edit-note" data-past-edit-note hidden>Past bookings can't be edited.</p>
                    <div class="court-block-form-grid">
                        <label>Customer name<input name="customer_name" type="text" maxlength="255" required></label>
                        <label>Contact number<input name="contact_number" type="tel" maxlength="30" required></label>
                        <label>Email<input name="email" type="email" maxlength="255"></label>
                        <label>Court<select name="court_id" required></select></label>
                        <label>Date<input name="date" type="date" required></label>
                        <label>Start time<input name="start_time" type="time" required></label>
                        <label>End time<input name="end_time" type="time" required></label>
                        <label>Amount<input name="amount" type="number" min="0" step="0.01" required></label>
                        <label class="court-booking-price-ack-field" hidden><span><input name="acknowledge_price_change" type="checkbox" value="1"> I acknowledge the booking price change</span></label>
                        <label class="court-block-notes-field">Notes<textarea name="notes" rows="3" maxlength="5000"></textarea></label>
                    </div>
                    <p class="court-block-modal-message" id="courtBookingEditMessage" role="status" aria-live="polite"></p>
                    <div class="court-block-modal-actions">
                        <button type="button" class="court-block-secondary" id="courtBookingEditCancel">Back</button>
                        <button type="submit" class="court-block-primary">Save booking</button>
                    </div>
                </form>
            </div>
        </section>
    </div>
    </div>

@endsection

@push('scripts')
    @php
        $courtBoardData = [
            'bookings'         => $courtBoardBookings,
            'closures'         => $courtBoardClosures,
            'blocks'           => $courtBoardBlocks,
            'closingCutoffs'   => $courtBoardClosingCutoffs,
            'courts'           => $courts,
            'businessSettings' => [
                'open_hour'       => $businessSettings->open_hour,
                'close_hour'      => $businessSettings->close_hour,
                'closed_weekdays' => $businessSettings->closed_weekdays,
                'step_minutes'    => $businessSettings->step_minutes,
                'peak_start_hour' => $businessSettings->peak_start_hour,
                'peak_end_hour' => $businessSettings->peak_end_hour,
                'peak_adjustment_type' => $businessSettings->peak_adjustment_type,
                'peak_adjustment_value' => $businessSettings->peak_adjustment_value,
                'today' => $businessSettings->today,
                'server_time' => $businessSettings->server_time,
            ],
        ];
    @endphp
    <script id="courtBoardData" type="application/json">{!! json_encode($courtBoardData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}</script>
    <script src="{{ asset('js/admin/admin-bookings-board.js') . '?v=' . filemtime(public_path('js/admin/admin-bookings-board.js')) }}" defer></script>
@endpush
