@extends('layouts.app')

@section('title', 'Courts | Court Booking')
@section('page-title', 'Courts')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/courts.css') }}">
@endpush

@section('content')

    {{-- Flash message data attributes, read by courts.js to trigger a toast --}}
    <div id="flash-data"
         data-success="{{ session('success') }}"
         data-error="{{ session('error') }}"
         data-errors="{{ $errors->any() ? $errors->toJson() : '' }}"
         data-old="{{ old() ? json_encode(old()) : '' }}"
         data-old-court-id="{{ old('court_id') }}"
         style="display:none;"></div>

    <div class="panel">
        <div class="panel-header">
            <h2>Courts</h2>
            <button type="button" class="btn btn-primary-sm" onclick="openAddCourtModal()">
                + Add Court
            </button>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Dimensions (W x L)</th>
                    <th>Area</th>
                    <th>Surface</th>
                    <th>Hourly Rate</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($courts as $court)
                    <tr>
                        <td>{{ $court->name }}</td>
                        <td>{{ number_format($court->width, 2) }}m x {{ number_format($court->length, 2) }}m</td>
                        <td>{{ number_format($court->area, 2) }} m&sup2;</td>
                        <td>{{ $court->surface_type ?: '—' }}</td>
                        <td>₱{{ number_format($court->hourly_rate, 2) }}</td>
                        <td>
                            <span class="status status-{{ $court->status }}">
                                {{ ucfirst($court->status) }}
                            </span>
                        </td>
                        <td class="actions-cell">
                            <button
                                type="button"
                                class="btn-icon btn-edit"
                                title="Edit court"
                                onclick='openEditCourtModal(@json($court))'
                            >Edit</button>

                            <button
                                type="button"
                                class="btn-icon btn-delete"
                                title="Delete court"
                                onclick="openDeleteCourtModal({{ $court->id }}, {{ json_encode($court->name) }})"
                            >Delete</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="empty-row">No courts added yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Add / Edit Court Modal --}}
    <div class="modal-overlay" id="court-modal">
        <div class="modal-box">
            <div class="modal-header">
                <h3 id="court-modal-title">Add Court</h3>
                <button type="button" class="modal-close" onclick="closeCourtModal()">&times;</button>
            </div>

            <form id="court-form" method="POST" action="{{ route('courts.store') }}">
                @csrf
                <input type="hidden" name="_method" id="court-form-method" value="POST">
                <input type="hidden" name="court_id" id="court-form-id" value="">

                <div class="form-group">
                    <label for="name">Court Name</label>
                    <input type="text" id="name" name="name" placeholder="e.g. Court 1" required>
                    <span class="error-text field-error" data-field="name"></span>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="width">Width (m)</label>
                        <input type="number" id="width" name="width" step="0.01" min="0" placeholder="6.10" required>
                        <span class="error-text field-error" data-field="width"></span>
                    </div>

                    <div class="form-group">
                        <label for="length">Length (m)</label>
                        <input type="number" id="length" name="length" step="0.01" min="0" placeholder="13.41" required>
                        <span class="error-text field-error" data-field="length"></span>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="surface_type">Surface Type</label>
                        <input type="text" id="surface_type" name="surface_type" placeholder="e.g. Acrylic">
                        <span class="error-text field-error" data-field="surface_type"></span>
                    </div>

                    <div class="form-group">
                        <label for="hourly_rate">Hourly Rate (₱)</label>
                        <input type="number" id="hourly_rate" name="hourly_rate" step="0.01" min="0" placeholder="300.00" required>
                        <span class="error-text field-error" data-field="hourly_rate"></span>
                    </div>
                </div>

                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" required>
                        <option value="active">Active</option>
                        <option value="maintenance">Maintenance</option>
                        <option value="inactive">Inactive</option>
                    </select>
                    <span class="error-text field-error" data-field="status"></span>
                </div>

                <div class="form-group">
                    <label for="notes">Notes (optional)</label>
                    <textarea id="notes" name="notes" rows="3" placeholder="Any additional details..."></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeCourtModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary-sm" id="court-form-submit">Save Court</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Delete Confirmation Modal --}}
    <div class="modal-overlay" id="delete-modal">
        <div class="modal-box modal-box-sm">
            <div class="modal-header">
                <h3>Delete Court</h3>
                <button type="button" class="modal-close" onclick="closeDeleteModal()">&times;</button>
            </div>

            <p class="modal-text">
                Are you sure you want to delete <strong id="delete-court-name"></strong>?
                This action cannot be undone.
            </p>

            <form id="delete-form" method="POST" action="">
                @csrf
                @method('DELETE')

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete</button>
                </div>
            </form>
        </div>
    </div>

@endsection

@push('scripts')
    <script>
        window.COURTS_STORE_URL = "{{ route('courts.store') }}";
        window.COURTS_UPDATE_URL_TEMPLATE = "{{ route('courts.update', ':id') }}";
        window.COURTS_DESTROY_URL_TEMPLATE = "{{ route('courts.destroy', ':id') }}";

        // Fallback toast helper — only defines itself if app.js doesn't already provide one
        if (typeof showToast !== 'function') {
            window.showToast = function (message, type = 'success') {
                let container = document.querySelector('.toast-container');
                if (!container) {
                    container = document.createElement('div');
                    container.className = 'toast-container';
                    document.body.appendChild(container);
                }

                const toast = document.createElement('div');
                toast.className = `toast toast-${type}`;
                toast.innerHTML = `<span class="toast-message">${message}</span>
                    <button type="button" class="toast-close" onclick="this.parentElement.remove()">&times;</button>`;

                container.appendChild(toast);

                setTimeout(() => {
                    toast.classList.add('toast-out');
                    setTimeout(() => toast.remove(), 300);
                }, 4000);
            };
        }
    </script>
    <script src="{{ asset('js/courts.js') }}" defer></script>
@endpush