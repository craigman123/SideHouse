@php
    $creditGiver = false;
    if (auth()->check()) {
        $access = app(\App\Services\UserAccessService::class);
        $realRole = $access->realRole(auth()->user());
        $creditGiver = in_array($realRole, ['admin', 'owner'], true);
    }
@endphp
<div class="customers-page is-loading" id="customersPage" data-customers-url="{{ route('admin.customers.data') }}" data-credit-lookup-url="{{ route('admin.customer-credits.lookup') }}" data-credit-store-url="{{ route('admin.customer-credits.store') }}" data-credit-giver="{{ $creditGiver ? 'true' : 'false' }}" data-show-financials="{{ $showFinancials ? 'true' : 'false' }}">

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
                @if ($creditGiver)
                    <button type="button" class="btn-primary customers-send-credit-button" id="sendCreditOpen">Send Credit</button>
                @endif
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

    @if ($creditGiver)
        <div class="modal-overlay" id="sendCreditModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="sendCreditModalLabel" hidden>
            <div class="modal-box send-credit-modal-box" role="document">
                <div class="modal-header">
                    <h3 id="sendCreditModalLabel">Send Given Credit</h3>
                    <button type="button" class="modal-close" id="sendCreditClose" aria-label="Close">&times;</button>
                </div>
                <form id="sendCreditForm" class="send-credit-form" novalidate>
                    <div class="send-credit-form-view" id="sendCreditFormView">
                        <input type="hidden" id="sendCreditIdempotency" name="idempotency_token">
                        <label for="sendCreditEmail">Recipient email</label>
                        <input type="email" id="sendCreditEmail" name="email" maxlength="255" required autocomplete="email" placeholder="recipient@example.com">
                        <p class="send-credit-lookup" id="sendCreditLookup" aria-live="polite"></p>

                        <label for="sendCreditAmount">Amount in pesos</label>
                        <input type="number" id="sendCreditAmount" name="amount" min="1" max="10000" step="1" required inputmode="numeric" placeholder="100">

                        <label for="sendCreditNote">Note <span>(optional)</span></label>
                        <textarea id="sendCreditNote" name="note" maxlength="255" rows="3" placeholder="Reason or short note"></textarea>

                        <div class="given-credit-preview">
                            <img src="{{ asset('images/credits/given-credit-symbol.svg') }}" alt="Given Credit" width="480" height="240">
                            <strong id="sendCreditPreviewAmount">₱0 Given Credit</strong>
                        </div>

                        <p class="send-credit-modal-error" id="sendCreditError" role="alert" hidden></p>
                        <button type="submit" class="btn-primary send-credit-submit" id="sendCreditSubmit" disabled>Review Send</button>
                    </div>

                    <div class="send-credit-confirm-view" id="sendCreditConfirmView" hidden>
                        <div class="send-credit-confirm-copy">
                            <strong id="sendCreditConfirmation"></strong>
                            <p>Given Credit is not refundable to real money.</p>
                        </div>
                        <p class="send-credit-modal-error" id="sendCreditConfirmError" role="alert" hidden></p>
                        <div class="send-credit-confirm-actions">
                            <button type="button" class="btn-secondary" id="sendCreditBack">Back</button>
                            <button type="button" class="btn-primary" id="sendCreditConfirm">Send Credit</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endif
