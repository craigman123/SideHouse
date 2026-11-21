<div class="customers-page is-loading" id="customersPage" data-customers-url="{{ route('admin.customers.data') }}" data-show-financials="{{ $showFinancials ? 'true' : 'false' }}">

        <div class="customers-header">
            <div>
                <p class="customers-subtitle">Ranked by number of bookings, most to least.</p>
            </div>
            <div class="customers-toolbar">
                <div class="customers-type-filter" id="customerTypeFilter" role="radiogroup" aria-label="Filter customers by type">
                    <button type="button" class="customers-type-option" role="radio" aria-checked="true" tabindex="0" data-customer-type="all">
                        <span>All</span><span class="customers-type-count" data-customer-type-count="all">(0)</span>
                    </button>
                    <button type="button" class="customers-type-option" role="radio" aria-checked="false" tabindex="-1" data-customer-type="registered">
                        <span>Registered</span><span class="customers-type-count" data-customer-type-count="registered">(0)</span>
                    </button>
                    <button type="button" class="customers-type-option" role="radio" aria-checked="false" tabindex="-1" data-customer-type="guest">
                        <span>Guest</span><span class="customers-type-count" data-customer-type-count="guest">(0)</span>
                    </button>
                </div>
                <div class="customers-search-wrap">
                    <input
                        type="search"
                        id="customerSearch"
                        class="customers-search-input"
                        placeholder="Search by name, email, or phone..."
                        autocomplete="off"
                    />
                </div>
            </div>
        </div>

        <div class="customers-table-wrap">
            <table class="customers-table">
                <thead>
                    <tr>
                        <th class="col-rank">#</th>
                        <th>Customer</th>
                        <th>Contact</th>
                        <th class="col-num">Bookings</th>
                        @if ($showFinancials)<th class="col-num">Total Spent</th>@endif
                        <th>Last Booking</th>
                        <th class="col-type">Type</th>
                    </tr>
                </thead>
                <tbody id="customersTableBody">
                    <tr class="customers-skeleton-row"><td colspan="{{ $showFinancials ? 7 : 6 }}"><span class="skeleton"></span></td></tr>
                    <tr class="customers-skeleton-row"><td colspan="{{ $showFinancials ? 7 : 6 }}"><span class="skeleton"></span></td></tr>
                    <tr class="customers-skeleton-row"><td colspan="{{ $showFinancials ? 7 : 6 }}"><span class="skeleton"></span></td></tr>
                    <tr class="customers-skeleton-row"><td colspan="{{ $showFinancials ? 7 : 6 }}"><span class="skeleton"></span></td></tr>
                </tbody>
            </table>

            <div class="customers-empty" id="customersEmpty" role="status" aria-live="polite" aria-atomic="true" hidden>
                <p id="customersEmptyMessage">No customers match your search.</p>
                <button type="button" class="customers-clear-filters" id="customerFiltersClear">Clear filters</button>
            </div>
            <p class="customers-error" id="customersError" hidden>Couldn't load customers. Please refresh the page.</p>
        </div>
    </div>

    {{-- Booking history modal for a single customer --}}
    <div class="modal-overlay" id="customerBookingsModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="customerBookingsModalLabel" hidden>
        <div class="modal-box" role="document">
            <div class="modal-header">
                <h3 id="customerBookingsModalLabel">Bookings</h3>
                <button type="button" class="modal-close" id="customerBookingsModalClose" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div id="customerBookingsList" class="customer-bookings-list"></div>
            </div>
        </div>
    </div>
