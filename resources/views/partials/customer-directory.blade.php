@php
    $creditGiver = false;
    if (auth()->check()) {
        $access = app(\App\Services\UserAccessService::class);
        $realRole = $access->realRole(auth()->user());
        $creditGiver = in_array($realRole, ['admin', 'owner'], true);
    }
@endphp
<div class="customers-page is-loading" id="customersPage" data-customers-url="{{ route('admin.customers.data') }}" data-credit-lookup-url="{{ route('admin.customer-credits.lookup') }}" data-credit-store-url="{{ route('admin.customer-credits.store') }}" data-credit-giver="{{ $creditGiver ? 'true' : 'false' }}" data-credit-max="{{ (int) config('credit.max_amount', 10000) }}" data-show-financials="{{ $showFinancials ? 'true' : 'false' }}">

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
                    <button type="button" class="btn-primary customers-send-credit-button" id="sendCreditOpen">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="black" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="Given Credit" xmlns:c2pa="http://c2pa.org/manifest">
                            <path d="M16.5 8.5 A6.5 6.5 0 1 0 16.5 15.5"/>
                            <path d="M11 12 H18"/>
                            <path d="M11 2.5 V5.5"/>
                            <path d="M11 18.5 V21.5"/><metadata><c2pa:manifest>AAAWgmp1bWIAAAAeanVtZGMycGEAEQAQgAAAqgA4m3EDYzJwYQAAABZcanVtYgAAAEdqdW1kYzJtYQARABCAAACqADibcQN1cm46YzJwYTo3MmQyZjZjOC0yMWRkLTRhMjMtYjcwYS05NzgyODdkNTJiMDEAAAADl2p1bWIAAAApanVtZGMyYXMAEQAQgAAAqgA4m3EDYzJwYS5hc3NlcnRpb25zAAAAALxqdW1iAAAARGp1bWRjYm9yABEAEIAAAKoAOJtxE2MycGEuaW5ncmVkaWVudC52MwAAAAAYYzJzaLo2oVUXSu3D5j+hsfnWAi8AAABwY2JvcqNpZGM6Zm9ybWF0bWltYWdlL3N2Zyt4bWxqaW5zdGFuY2VJRHgseG1wOmlpZDpjZjhhMTI2NC1kNzg5LTQ5N2MtYWU3Yi1jYzAzNDkzNzU0NzZscmVsYXRpb25zaGlwaHBhcmVudE9mAAAB4mp1bWIAAABBanVtZGNib3IAEQAQgAAAqgA4m3ETYzJwYS5hY3Rpb25zLnYyAAAAABhjMnNosEGv9KUlM1etU8mmRA3i+wAAAZljYm9yomdhY3Rpb25zgqJmYWN0aW9ua2MycGEub3BlbmVkanBhcmFtZXRlcnOha2luZ3JlZGllbnRzgaJjdXJseC1zZWxmI2p1bWJmPWMycGEuYXNzZXJ0aW9ucy9jMnBhLmluZ3JlZGllbnQudjNkaGFzaFgggIzzpc5z7jFyufuQjje+A49LBbmu+oYfKrU9225UPk+kZmFjdGlvbngdY29tLmFudGhyb3BpYy5jbGF1ZGUucHJvdmlkZWRqcGFyYW1ldGVyc6F4H2NvbS5hbnRocm9waWMub3JpZ2luLWNvbmZpZGVuY2VndW5rbm93bmtkZXNjcmlwdGlvbnhmQ2xhdWRlIHByb3ZpZGVkIHRoaXMgZmlsZSBhdCB0aGUgcmVxdWVzdCBvZiBhIHVzZXIgYW5kIG1heSBoYXZlIGNyZWF0ZWQgb3IgbW9kaWZpZWQgdGhlIGZpbGUgY29udGVudHMubXNvZnR3YXJlQWdlbnShZG5hbWVmQ2xhdWRlcmFsbEFjdGlvbnNJbmNsdWRlZPUAAADIanVtYgAAAEBqdW1kY2JvcgARABCAAACqADibcRNjMnBhLmhhc2guZGF0YQAAAAAYYzJzaJYv95Ggqeg7hiBCn7x5pDcAAACAY2JvcqVjYWxnZnNoYTI1NmNwYWRMAAAAAAAAAAAAAAAAZGhhc2hYIFUQ4aRtyMoX/ftLWqRaoEG0M8yHsh2TD1ycVijyTxMkZG5hbWVuanVtYmYgbWFuaWZlc3RqZXhjbHVzaW9uc4GiZXN0YXJ0GQGcZmxlbmd0aBkeBAAAAj5qdW1iAAAAJ2p1bWRjMmNsABEAEIAAAKoAOJtxA2MycGEuY2xhaW0udjIAAAACD2Nib3KlY2FsZ2ZzaGEyNTZpc2lnbmF0dXJleE1zZWxmI2p1bWJmPS9jMnBhL3VybjpjMnBhOjcyZDJmNmM4LTIxZGQtNGEyMy1iNzBhLTk3ODI4N2Q1MmIwMS9jMnBhLnNpZ25hdHVyZWppbnN0YW5jZUlEeCx4bXA6aWlkOjcwZWE4MjNlLWE0ZmYtNDg0Ny05ZDBmLWYzNmMwNTc3OTU5OHJjcmVhdGVkX2Fzc2VydGlvbnODomN1cmx4LXNlbGYjanVtYmY9YzJwYS5hc3NlcnRpb25zL2MycGEuaW5ncmVkaWVudC52M2RoYXNoWCCAjPOlznPuMXK5+5CON74Dj0sFua76hh8qtT3bblQ+T6JjdXJseCpzZWxmI2p1bWJmPWMycGEuYXNzZXJ0aW9ucy9jMnBhLmFjdGlvbnMudjJkaGFzaFggwtX2bJCEb0Bf8sSTPCzRzCcSEPOa6UbRfLKDpIYGp2iiY3VybHgpc2VsZiNqdW1iZj1jMnBhLmFzc2VydGlvbnMvYzJwYS5oYXNoLmRhdGFkaGFzaFggNfvEvnQVySbkyVUOjAtBE/6MKp1DAVYM1+jdd1xUdDl0Y2xhaW1fZ2VuZXJhdG9yX2luZm+jZG5hbWVvQW50aHJvcGljIEZpbGVzZ3ZlcnNpb25lMS4wLjBrc3BlY1ZlcnNpb25lMi40LjAAABA4anVtYgAAAChqdW1kYzJjcwARABCAAACqADibcQNjMnBhLnNpZ25hdHVyZQAAABAIY2JvctKEWQISogEmGCFZAgowggIGMIIBjaADAgECAhRA5aAK7sI50L64g/oGQgU9Z1UTADAKBggqhkjOPQQDAzBJMRcwFQYDVQQKEw5BbnRocm9waWMsIFBCQzEuMCwGA1UEAxMlQW50aHJvcGljIENvbnRlbnQgQ3JlZGVudGlhbHMgUm9vdCBDQTAeFw0yNjA4MDcxODQzNTZaFw0yODA4MDYxOTQzNTZaMEQxFzAVBgNVBAoTDkFudGhyb3BpYywgUEJDMSkwJwYDVQQDEyBBbnRocm9waWMgQ2xhdWRlIENvbnRlbnQgU2lnbmluZzBZMBMGByqGSM49AgEGCCqGSM49AwEHA0IABJh6CmvLUBgFFNU0vUKlOVtE6djd17L5SuwX0LemFisBM3dkd/3cyjxFA3Qo5S46fX0/ihY0VZ7mfb9KF703t5OjWDBWMA4GA1UdDwEB/wQEAwIHgDAVBgNVHSUEDjAMBgorBgEEAYPoXgIBMAwGA1UdEwEB/wQCMAAwHwYDVR0jBBgwFoAUzlHiBIFOZFsj+OPEz5o+nMHXXMIwCgYIKoZIzj0EAwMDZwAwZAIwMXMdFJ4BetLLVY7ORuE9noqbbAZOZn/aArXyTwFAZfKrPzxF2vPoJNf1+UCdg1XGAjBwX1zd9WGqYkqmL5SFqw1QySjr1zJfpJM9+1rdDwSPLMOPOjKuiXjoU/pUUeG9RwmhY3BhZFkNngAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAPZYQHR+OUt+CzDBZUU7/wqWVnPlaHguvWOqLU/djD4Va1BNYBDWU82jFXlTFNk7KAS//RJEvgGmYzfI/uERE5PTqcc=</c2pa:manifest></metadata></svg>
                        <span>Send Credit</span>
                    </button>
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
                <button type="button" class="customers-clear-filters btn-ghost-danger" id="customerFiltersClear">Clear filters</button>
            </div>
            <p class="customers-error" id="customersError" hidden>Couldn't load customers. Please refresh the page.</p>
        </div>
    </div>

    {{-- Booking history modal for a single customer --}}
    <div class="modal-overlay" id="customerBookingsModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="customerBookingsModalLabel" hidden>
        <div class="modal-box" role="document">
            <div class="modal-header">
                <h3 id="customerBookingsModalLabel">Bookings</h3>
                <button type="button" class="modal-close btn-ghost-danger" id="customerBookingsModalClose" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div id="customerBookingsList" class="customer-bookings-list"></div>
            </div>
        </div>
    </div>

    @if ($creditGiver)
        <div class="modal-overlay" id="sendCreditModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="sendCreditModalLabel" hidden>
            <div class="modal-box send-credit-modal-box" role="document">
                <div class="modal-header credit-modal">
                    <h3 id="sendCreditModalLabel">Send Credit</h3>
                </div>
                <form id="sendCreditForm" class="send-credit-form" novalidate>
                    <div class="send-credit-form-view" id="sendCreditFormView">
                        <input type="hidden" id="sendCreditIdempotency" name="idempotency_token">
                        <div class="send-credit-field">
                            <label for="sendCreditEmail">Recipient email</label>
                            <input type="email" id="sendCreditEmail" name="email" maxlength="255" required autocomplete="email" placeholder="recipient@example.com">
                            <p class="send-credit-lookup" id="sendCreditLookup" aria-live="polite"></p>
                        </div>

                        <div class="send-credit-field">
                            <label for="sendCreditAmount">Amount (pesos)</label>
                            <input type="text" id="sendCreditAmount" name="amount" inputmode="numeric" maxlength="5" required autocomplete="off" placeholder="100">
                            <p class="send-credit-field-error" id="sendCreditAmountError" role="alert"></p>
                        </div>

                        <div class="send-credit-preview" aria-live="polite">
                            <svg class="send-credit-preview-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M16.5 8.5 A6.5 6.5 0 1 0 16.5 15.5"/>
                                <path d="M11 12 H18"/>
                                <path d="M11 2.5 V5.5"/>
                                <path d="M11 18.5 V21.5"/>
                            </svg>
                            <strong id="sendCreditPreviewAmount">₱0 Given Credit</strong>
                        </div>

                        <p class="send-credit-modal-error" id="sendCreditError" role="alert" hidden></p>
                        <div class="send-credit-form-actions">
                            <button type="button" class="btn-secondary btn-ghost-danger" id="sendCreditCancel">Cancel</button>
                            <button type="submit" class="btn-primary send-credit-submit" id="sendCreditSubmit" disabled>Review Send</button>
                        </div>
                    </div>

                    <div class="send-credit-confirm-view" id="sendCreditConfirmView" hidden>
                        <div class="send-credit-confirm-copy">
                            <strong id="sendCreditConfirmation"></strong>
                            <p>Given Credit cannot be refunded to cash.</p>
                        </div>
                        <p class="send-credit-modal-error" id="sendCreditConfirmError" role="alert" hidden></p>
                        <div class="send-credit-confirm-actions">
                            <button type="button" class="btn-secondary btn-ghost-danger" id="sendCreditBack">Back</button>
                            <button type="button" class="btn-primary" id="sendCreditConfirm">Send Credit</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endif
