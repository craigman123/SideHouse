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

        <div class="memberships-table-wrap">
            @if ($membershipPlans->isEmpty())
                <p class="memberships-empty">No memberships have been created yet.</p>
            @else
                <div class="memberships-table-scroll">
                    <table class="memberships-table">
                        <thead>
                            <tr>
                                <th scope="col">Title</th>
                                <th scope="col">Price</th>
                                <th scope="col">Discount</th>
                                <th scope="col">Players</th>
                                <th scope="col">Expiry</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($membershipPlans as $membershipPlan)
                                <tr>
                                    <td>{{ $membershipPlan->name }}</td>
                                    <td class="memberships-money">₱{{ number_format((float) $membershipPlan->price, 2) }}</td>
                                    <td class="memberships-money">
                                        @if ($membershipPlan->discount_type === 'percentage')
                                            {{ number_format((float) $membershipPlan->discount_value, 2) }}%
                                        @else
                                            ₱{{ number_format((float) $membershipPlan->discount_value, 2) }}
                                        @endif
                                    </td>
                                    <td>{{ $membershipPlan->players }}</td>
                                    <td>{{ $membershipPlan->expiry_date?->format('M j, Y') ?? 'Not set' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="modal-overlay memberships-modal{{ $membershipModalHasErrors ? ' open' : '' }}" id="membershipModal" role="presentation" aria-hidden="{{ $membershipModalHasErrors ? 'false' : 'true' }}" @unless ($membershipModalHasErrors) hidden @endunless>
            <section class="modal-box memberships-modal-box" role="dialog" aria-modal="true" aria-labelledby="membershipModalTitle">
                <header class="modal-header">
                    <h2 id="membershipModalTitle">Add membership</h2>
                    <button type="button" class="modal-close" id="membershipModalClose" aria-label="Close Add membership dialog">&times;</button>
                </header>

                <form method="POST" action="{{ route($membershipStoreRoute) }}" id="membershipForm">
                    @csrf

                    <div class="memberships-form-grid">
                        <div class="memberships-field">
                            <label for="membershipTitle">Title</label>
                            <input type="text" id="membershipTitle" name="title" value="{{ old('title') }}" maxlength="255" required aria-invalid="{{ $errors->has('title') ? 'true' : 'false' }}">
                            @error('title')<span class="memberships-field-error" role="alert">{{ $message }}</span>@enderror
                        </div>

                        <div class="memberships-field">
                            <label for="membershipDescription">Description</label>
                            <textarea id="membershipDescription" name="description" rows="3" maxlength="5000" aria-invalid="{{ $errors->has('description') ? 'true' : 'false' }}">{{ old('description') }}</textarea>
                            @error('description')<span class="memberships-field-error" role="alert">{{ $message }}</span>@enderror
                        </div>

                        <div class="memberships-field">
                            <label for="membershipPrice">Price in PHP</label>
                            <input type="number" id="membershipPrice" name="price" value="{{ old('price') }}" min="0" max="99999999.99" step="0.01" required aria-invalid="{{ $errors->has('price') ? 'true' : 'false' }}">
                            @error('price')<span class="memberships-field-error" role="alert">{{ $message }}</span>@enderror
                        </div>

                        <div class="memberships-field memberships-discount-fields">
                            <div class="memberships-field">
                                <label for="membershipDiscountType">Discount type</label>
                                <select id="membershipDiscountType" name="discount_type" required aria-invalid="{{ $errors->has('discount_type') ? 'true' : 'false' }}">
                                    <option value="percentage" @selected(old('discount_type', 'percentage') === 'percentage')>Percentage</option>
                                    <option value="fixed" @selected(old('discount_type') === 'fixed')>Fixed price</option>
                                </select>
                                @error('discount_type')<span class="memberships-field-error" role="alert">{{ $message }}</span>@enderror
                            </div>

                            <div class="memberships-field">
                                <label for="membershipDiscountValue">Discount value</label>
                                <div class="memberships-discount-value">
                                    <input type="number" id="membershipDiscountValue" name="discount_value" value="{{ old('discount_value') }}" min="1" max="100" step="0.01" required aria-invalid="{{ $errors->has('discount_value') ? 'true' : 'false' }}">
                                    <span id="membershipDiscountSuffix" aria-hidden="true">%</span>
                                </div>
                                @error('discount_value')<span class="memberships-field-error" role="alert">{{ $message }}</span>@enderror
                            </div>
                        </div>

                        <div class="memberships-field">
                            <label for="membershipPlayers">Players</label>
                            <input type="number" id="membershipPlayers" name="players" value="{{ old('players') }}" min="1" max="2147483647" step="1" required aria-invalid="{{ $errors->has('players') ? 'true' : 'false' }}">
                            @error('players')<span class="memberships-field-error" role="alert">{{ $message }}</span>@enderror
                        </div>

                        <div class="memberships-field">
                            <label for="membershipExpiry">Expiry</label>
                            <input type="date" id="membershipExpiry" name="expiry_date" value="{{ old('expiry_date') }}" min="{{ now()->addDay()->toDateString() }}" required aria-invalid="{{ $errors->has('expiry_date') ? 'true' : 'false' }}">
                            @error('expiry_date')<span class="memberships-field-error" role="alert">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    <div class="memberships-form-actions">
                        <button type="button" class="memberships-button memberships-button-secondary" id="cancelMembershipModal">Cancel</button>
                        <button type="submit" class="memberships-button memberships-button-primary" id="saveMembershipButton">Save membership</button>
                    </div>
                </form>
            </section>
        </div>

        <div class="toast-container" id="toastContainer" aria-live="polite"></div>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('js/admin/memberships.js') }}?v={{ filemtime(public_path('js/admin/memberships.js')) }}" defer></script>
@endpush
