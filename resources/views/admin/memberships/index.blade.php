@extends($membershipLayout)

@section('title', 'Memberships | Side House')
@section('page-title', 'Memberships')

@push('styles')
    <link rel="stylesheet" href="{{ asset($membershipModalStylesheet) }}?v={{ filemtime(public_path($membershipModalStylesheet)) }}">
    <link rel="stylesheet" href="{{ asset('css/admin/memberships.css') }}?v={{ filemtime(public_path('css/admin/memberships.css')) }}">
@endpush

@php
    $membershipModalHasErrors = $errors->hasAny([
        'title',
        'description',
        'price',
        'discount_type',
        'discount_value',
        'players',
        'expiry_date',
    ]);
@endphp

@section('content')
    <section class="memberships-page" aria-label="Memberships">
        <div id="flash-data" data-success="{{ session('success') }}"></div>

        <header class="memberships-header">
            <button type="button" class="memberships-button memberships-button-primary" id="openMembershipModal" aria-haspopup="dialog" aria-controls="membershipModal">
                Add membership
            </button>
        </header>

        @if ($membershipPlans->isEmpty())
            <p class="memberships-empty">No memberships have been created yet.</p>
        @else
            <div class="memberships-grid">
                @foreach ($membershipPlans as $membershipPlan)
                    @php
                        $currentMembersCount = (int) $membershipPlan->current_members_count;
                    @endphp
                    <article class="membership-card">
                        <div class="membership-card-top-row">
                            <svg class="membership-card-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                                <path d="M3 10h18M7 15h3"></path>
                            </svg>
                            <span
                                class="membership-card-status membership-card-status--{{ $membershipPlan->expiry_status }}"
                                role="img"
                                aria-label="{{ $membershipPlan->expiry_status_label }}"
                                title="{{ $membershipPlan->expiry_status_label }}"
                            ></span>
                        </div>
                        @if ($membershipPlan->expiry_status === 'expired')
                            <div class="membership-card-expired-overlay" role="img" aria-label="EXPIRED">
                                <span aria-hidden="true">EXPIRED</span>
                            </div>
                        @endif
                        <div class="membership-card-qr">
                            <img
                                src="data:image/svg+xml;base64,{{ base64_encode($membershipPlan->qr_svg) }}"
                                alt="QR code for {{ $membershipPlan->name }}"
                                width="256"
                                height="256"
                            >
                        </div>

                        <div class="membership-card-heading">
                            <h2 class="membership-card-title">{{ $membershipPlan->name }}</h2>
                            <p class="membership-card-description">{{ $membershipPlan->description ?: 'No description' }}</p>
                        </div>

                        <dl class="membership-card-details">
                            <div>
                                <dt>Price</dt>
                                <dd>₱{{ number_format((float) $membershipPlan->price, 2) }}</dd>
                            </div>
                            <div>
                                <dt>Discount</dt>
                                <dd>
                                    @if ($membershipPlan->discount_type === 'percentage')
                                        {{ number_format((float) $membershipPlan->discount_value, 2) }}%
                                    @else
                                        <span>Fixed price:</span> ₱{{ number_format((float) $membershipPlan->discount_value, 2) }}
                                    @endif
                                </dd>
                            </div>
                            <div>
                                <dt>Players</dt>
                                <dd>{{ $membershipPlan->players }}</dd>
                            </div>
                            <div>
                                <dt>Expiry</dt>
                                <dd>{{ $membershipPlan->expiry_date?->format('M j, Y') ?? 'No expiry' }}</dd>
                            </div>
                        </dl>

                        <section class="membership-card-members" aria-label="Membership members">
                            <h3>Members <span>({{ $currentMembersCount }})</span></h3>
                            @if ($currentMembersCount > 0)
                                <ul>
                                    @foreach ($membershipPlan->memberships as $membership)
                                        @if ($membership->user)
                                            <li>{{ $membership->user->name }}</li>
                                        @endif
                                    @endforeach
                                    @if ($currentMembersCount > $membershipPlan->memberships->count())
                                        <li class="membership-card-more">+{{ $currentMembersCount - $membershipPlan->memberships->count() }} more</li>
                                    @endif
                                </ul>
                            @else
                                <p>No members yet</p>
                            @endif
                        </section>
                    </article>
                @endforeach
            </div>
        @endif

        <div class="modal-overlay memberships-modal{{ $membershipModalHasErrors ? ' open' : '' }}" id="membershipModal" role="presentation" aria-hidden="{{ $membershipModalHasErrors ? 'false' : 'true' }}" @unless ($membershipModalHasErrors) hidden @endunless>
            <section class="modal-box memberships-modal-box" role="dialog" aria-modal="true" aria-labelledby="membershipModalTitle">
                <header class="modal-header">
                    <h2 id="membershipModalTitle">Add membership</h2>
                    <button type="button" class="modal-close" id="membershipModalClose" aria-label="Close Add membership dialog">&times;</button>
                </header>

                <form method="POST" action="{{ route($membershipStoreRoute) }}" id="membershipForm" data-summary-url="{{ route($membershipSummaryRoute) }}">
                    @csrf

                    <div class="memberships-form-grid">
                        <div class="memberships-field">
                            <label for="membershipTitle">Title</label>
                            <input type="text" id="membershipTitle" name="title" value="{{ old('title') }}" maxlength="255" required aria-invalid="{{ $errors->has('title') ? 'true' : 'false' }}">
                            <span class="memberships-field-error" data-membership-error="title" role="alert">@error('title'){{ $message }}@enderror</span>
                        </div>

                        <div class="memberships-field">
                            <label for="membershipDescription">Description</label>
                            <textarea id="membershipDescription" name="description" rows="3" maxlength="5000" aria-invalid="{{ $errors->has('description') ? 'true' : 'false' }}">{{ old('description') }}</textarea>
                            <span class="memberships-field-error" data-membership-error="description" role="alert">@error('description'){{ $message }}@enderror</span>
                        </div>

                        <div class="memberships-field">
                            <label for="membershipPrice">Price in PHP</label>
                            <input type="number" id="membershipPrice" name="price" value="{{ old('price') }}" min="0" max="99999999.99" step="0.01" required aria-invalid="{{ $errors->has('price') ? 'true' : 'false' }}">
                            <span class="memberships-field-error" data-membership-error="price" role="alert">@error('price'){{ $message }}@enderror</span>
                        </div>

                        <div class="memberships-field memberships-discount-fields">
                            <div class="memberships-field">
                                <label for="membershipDiscountType">Discount type</label>
                                <select id="membershipDiscountType" name="discount_type" required aria-invalid="{{ $errors->has('discount_type') ? 'true' : 'false' }}">
                                    <option value="percentage" @selected(old('discount_type', 'percentage') === 'percentage')>Percentage</option>
                                    <option value="fixed" @selected(old('discount_type') === 'fixed')>Fixed price</option>
                                </select>
                                <span class="memberships-field-error" data-membership-error="discount_type" role="alert">@error('discount_type'){{ $message }}@enderror</span>
                            </div>

                            <div class="memberships-field">
                                <label for="membershipDiscountValue">Discount value</label>
                                <div class="memberships-discount-value">
                                    <input type="number" id="membershipDiscountValue" name="discount_value" value="{{ old('discount_value') }}" min="1" max="100" step="0.01" required aria-invalid="{{ $errors->has('discount_value') ? 'true' : 'false' }}">
                                    <span id="membershipDiscountSuffix" aria-hidden="true">%</span>
                                </div>
                                <span class="memberships-field-error" data-membership-error="discount_value" role="alert">@error('discount_value'){{ $message }}@enderror</span>
                            </div>
                        </div>

                        <div class="memberships-field">
                            <label for="membershipPlayers">Players</label>
                            <input type="number" id="membershipPlayers" name="players" value="{{ old('players') }}" min="1" max="2147483647" step="1" required aria-invalid="{{ $errors->has('players') ? 'true' : 'false' }}">
                            <span class="memberships-field-error" data-membership-error="players" role="alert">@error('players'){{ $message }}@enderror</span>
                        </div>

                        <div class="memberships-field">
                            <label for="membershipExpiry">Expiry</label>
                            <input type="date" id="membershipExpiry" name="expiry_date" value="{{ old('expiry_date') }}" min="{{ now()->addDay()->toDateString() }}" aria-invalid="{{ $errors->has('expiry_date') ? 'true' : 'false' }}">
                            <span class="memberships-field-hint">Leave empty for no expiry.</span>
                            <span class="memberships-field-error" data-membership-error="expiry_date" role="alert">@error('expiry_date'){{ $message }}@enderror</span>
                        </div>
                    </div>

                    <div class="memberships-form-actions">
                        <button type="button" class="memberships-button memberships-button-secondary" id="cancelMembershipModal">Cancel</button>
                        <button type="button" class="memberships-button memberships-button-primary" id="previewMembershipButton">Summary</button>
                    </div>
                </form>
            </section>
        </div>

        <div class="modal-overlay memberships-modal" id="membershipSummaryModal" role="presentation" aria-hidden="true" hidden>
            <section class="modal-box memberships-modal-box memberships-summary-box" role="dialog" aria-modal="true" aria-labelledby="membershipSummaryTitle" tabindex="-1">
                <header class="modal-header">
                    <h2 id="membershipSummaryTitle">Membership summary</h2>
                    <button type="button" class="modal-close" id="membershipSummaryClose" aria-label="Close membership summary">&times;</button>
                </header>

                <article class="membership-receipt" aria-label="Membership receipt">
                    <div class="membership-receipt-heading">
                        <h3 id="membershipSummaryName"></h3>
                        <p id="membershipSummaryDescription"></p>
                    </div>
                    <dl class="membership-receipt-details">
                        <div>
                            <dt>Price</dt>
                            <dd id="membershipSummaryPrice"></dd>
                        </div>
                        <div>
                            <dt>Discount</dt>
                            <dd id="membershipSummaryDiscount"></dd>
                        </div>
                        <div>
                            <dt>Players</dt>
                            <dd id="membershipSummaryPlayers"></dd>
                        </div>
                        <div>
                            <dt>Expiry</dt>
                            <dd id="membershipSummaryExpiry">No expiry</dd>
                        </div>
                        <div>
                            <dt>Date created</dt>
                            <dd id="membershipSummaryCreated"></dd>
                        </div>
                    </dl>
                    <div class="membership-summary-qr">
                        <img id="membershipSummaryQr" alt="QR code encoding the membership information" width="256" height="256" hidden>
                    </div>
                </article>

                <div class="memberships-form-actions">
                    <button type="button" class="memberships-button memberships-button-secondary" id="backToMembershipForm">Back</button>
                    <button type="button" class="memberships-button memberships-button-primary" id="confirmMembershipButton">Confirm and save</button>
                </div>
            </section>
        </div>

        <div class="toast-container" id="toastContainer" aria-live="polite"></div>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('js/admin/memberships.js') }}?v={{ filemtime(public_path('js/admin/memberships.js')) }}" defer></script>
@endpush
