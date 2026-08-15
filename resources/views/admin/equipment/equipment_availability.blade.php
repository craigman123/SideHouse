@extends('layouts.app')

@section('page-title', 'Equipment Availability')
@section('title', 'Equipment Availability | Side House')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/admin-equipment-availability.css') }}">
@endpush

@section('content')
    <div class="equip-page is-loading" id="equipPage"
         data-equip-url="{{ route('admin.equipment.availability.data') }}"
         data-equip-update-base="{{ url('/equipment') }}"
         data-csrf="{{ csrf_token() }}">

        <div class="equip-header">
            <div>
                <h1 class="equip-title">Equipment Availability</h1>
                <p class="equip-subtitle">How much of each item is actually free on a given date — not just what you own.</p>
            </div>

            <div class="equip-date-wrap">
                <label for="equipDate" class="equip-date-label">Date</label>
                <input type="date" id="equipDate" class="equip-date-input">
            </div>
        </div>

        @if (session('success'))
            <div class="equip-flash equip-flash-success">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="equip-flash equip-flash-error">{{ $errors->first() }}</div>
        @endif

        <details class="equip-add-panel" @if ($errors->any()) open @endif>
            <summary>+ Add Equipment</summary>

            <form method="POST" action="{{ route('admin.equipment.store') }}" class="equip-add-form">
                @csrf

                <div class="equip-field-grid">
                    <div class="equip-field">
                        <label for="eq_name">Name</label>
                        <input type="text" name="name" id="eq_name" maxlength="255" value="{{ old('name') }}" required>
                        @error('name') <span class="equip-field-error">{{ $message }}</span> @enderror
                    </div>

                    <div class="equip-field">
                        <label for="eq_category">Category</label>
                        <input type="text" name="category" id="eq_category" maxlength="255" placeholder="e.g. Racket" value="{{ old('category') }}">
                        @error('category') <span class="equip-field-error">{{ $message }}</span> @enderror
                    </div>

                    <div class="equip-field">
                        <label for="eq_price">Rental Price</label>
                        <input type="number" name="price" id="eq_price" min="0" step="0.01" value="{{ old('price') }}" required>
                        @error('price') <span class="equip-field-error">{{ $message }}</span> @enderror
                    </div>

                    <div class="equip-field">
                        <label for="eq_stock">Quantity Owned</label>
                        <input type="number" name="stock_total" id="eq_stock" min="0" step="1" value="{{ old('stock_total', 1) }}" required>
                        @error('stock_total') <span class="equip-field-error">{{ $message }}</span> @enderror
                    </div>

                    <div class="equip-field">
                        <label for="eq_status">Status</label>
                        <select name="status" id="eq_status">
                            <option value="active" @selected(old('status', 'active') === 'active')>Active</option>
                            <option value="inactive" @selected(old('status') === 'inactive')>Inactive</option>
                        </select>
                        @error('status') <span class="equip-field-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="equip-form-actions">
                    <button type="submit" class="btn btn-primary">Add Equipment</button>
                </div>
            </form>
        </details>

        <div class="equip-table-wrap">
            <table class="equip-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Owned</th>
                        <th>Reserved (peak)</th>
                        <th>Available Today</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="equipTableBody">
                    {{-- Skeleton rows shown while is-loading is present; real rows replace this on load --}}
                    @for ($i = 0; $i < 4; $i++)
                        <tr class="equip-skeleton-row">
                            <td><span class="skeleton"></span></td>
                            <td><span class="skeleton"></span></td>
                            <td><span class="skeleton"></span></td>
                            <td><span class="skeleton"></span></td>
                            <td><span class="skeleton"></span></td>
                            <td><span class="skeleton"></span></td>
                            <td><span class="skeleton"></span></td>
                        </tr>
                    @endfor
                </tbody>
            </table>

            <p class="equip-empty-note" id="equipEmpty" hidden>No equipment items yet.</p>
            <p class="equip-error" id="equipError" hidden>Couldn't load availability. Please try again.</p>
        </div>

        {{-- Edit modal — one shared instance, populated by JS per row clicked.
             Uses the same open/close-class convention as guest-book.js's
             modals for consistency. --}}
        <div class="modal-overlay" id="equipEditModal">
            <div class="equip-modal">
                <button type="button" class="equip-modal-close" id="equipEditClose" aria-label="Close">&times;</button>
                <h3 class="equip-modal-title">Edit Equipment</h3>

                <form id="equipEditForm" class="equip-add-form">
                    <input type="hidden" id="edit_id">

                    <div class="equip-field-grid">
                        <div class="equip-field">
                            <label for="edit_name">Name</label>
                            <input type="text" id="edit_name" maxlength="255" required>
                        </div>

                        <div class="equip-field">
                            <label for="edit_category">Category</label>
                            <input type="text" id="edit_category" maxlength="255">
                        </div>

                        <div class="equip-field">
                            <label for="edit_price">Rental Price</label>
                            <input type="number" id="edit_price" min="0" step="0.01" required>
                        </div>

                        <div class="equip-field">
                            <label for="edit_stock">Quantity Owned</label>
                            <input type="number" id="edit_stock" min="0" step="1" required>
                        </div>

                        <div class="equip-field">
                            <label for="edit_status">Status</label>
                            <select id="edit_status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>

                    <p class="equip-field-error" id="equipEditError" hidden></p>

                    <div class="equip-form-actions">
                        <button type="submit" class="btn btn-primary" id="equipEditSubmit">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal-overlay" id="equipDeleteModal">
            <div class="equip-modal">
                <button type="button" class="equip-modal-close" id="equipDeleteClose">&times;</button>
                <h3 class="equip-modal-title">Delete Equipment</h3>
                <p>Delete "<strong id="equipDeleteName"></strong>"? This can't be undone.</p>
                <p class="equip-field-error" id="equipDeleteError" hidden></p>
                <form id="equipDeleteForm">
                    <div class="equip-form-actions">
                        <button type="submit" class="btn btn-primary" id="equipDeleteSubmit" style="background:#ef4444;">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/admin-equipment-availability.js') }}" defer></script>
@endpush