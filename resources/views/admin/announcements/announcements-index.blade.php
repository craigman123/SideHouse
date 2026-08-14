@extends('layouts.app')

@section('title', 'Announcements | Side House')
@section('page-title', 'Announcements')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/announcements.css') }}">
    <script src="{{ asset('js/announcement.js') }}" defer></script>
@endpush

@section('content')

    <div
        id="flash-data"
        data-success="{{ session('success') }}"
    ></div>

    <div class="panel" style="max-width: 900px;">
        <div class="panel-header">
            <p class="modal-text">
                Everything sent to your users' bell icon, most recent first.
            </p>
            <button type="button" class="btn btn-filter" id="openAnnouncementModal" style="white-space: nowrap;">
                New Announcement
            </button>
        </div>
    </div>

    {{-- ---------- Compose modal ---------- --}}
    <div class="modal-overlay" id="announcementModal">
        <div class="modal-box">
            <div class="modal-header">
                <h2>New Announcement</h2>
                <button type="button" class="modal-close" id="closeAnnouncementModal" aria-label="Close">&times;</button>
            </div>

            <p class="modal-text" style="margin-bottom: 18px;">
                Sends a notification to every registered user's bell icon and
                notifications page. There's no "draft" or "undo" — it goes out
                the moment you hit send, so give it a read-through first.
            </p>

            <form id="announcementForm" data-store-url="{{ route('admin.announcements.store') }}">
                @csrf

                <div class="filter-group" style="margin-bottom: 16px;">
                    <label for="title">Title</label>
                    <input type="text" id="title" name="title" maxlength="150" required
                           placeholder="e.g. Court closed this Saturday"
                           style="width: 100%;">
                    <p id="titleError" style="color: #f85149; font-size: 12px; margin-top: 4px; display: none;"></p>
                </div>

                <div class="filter-group" style="margin-bottom: 20px;">
                    <label for="body">Message</label>
                    <textarea id="body" name="body" maxlength="2000" rows="5" required
                              placeholder="What do your users need to know?"
                              style="width: 100%; resize: vertical;"></textarea>
                    <p id="bodyError" style="color: #f85149; font-size: 12px; margin-top: 4px; display: none;"></p>
                </div>

                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-filter" id="sendAnnouncementBtn">Send to All Users</button>
                    <button type="button" class="btn" id="cancelAnnouncementModal">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ---------- Confirm-send modal ---------- --}}
    <div class="modal-overlay" id="confirmSendModal">
        <div class="modal-box" style="max-width: 420px;">
            <div class="modal-header">
                <h2>Send this announcement?</h2>
            </div>

            <p class="modal-text" style="margin-bottom: 20px;">
                This will notify every registered user immediately. There's no "draft" or "undo," so make sure it's ready.
            </p>

            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn" id="cancelSendConfirm">Cancel</button>
                <button type="button" class="btn btn-filter" id="confirmSendBtn">Send to All Users</button>
            </div>
        </div>
    </div>

    {{-- ---------- Confirm-delete modal ----------
         Deliberately spells out that this only removes the admin-side
         record — the notification itself already landed in users' bells
         and can't be un-sent. That distinction matters here specifically
         because "delete" right next to "send to all users" could easily
         read as "unsend" if it isn't said outright. --}}
    <div class="modal-overlay" id="confirmDeleteModal">
        <div class="modal-box" style="max-width: 420px;">
            <div class="modal-header">
                <h2>Delete this announcement?</h2>
            </div>

            <p class="modal-text" style="margin-bottom: 20px;">
                This only removes it from your list here — it does not un-send
                the notification, which every user already received.
            </p>

            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn" id="cancelDeleteConfirm">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
            </div>
        </div>
    </div>

    <div id="announcementsListWrap">
            @if($announcements->isEmpty())
                <p class="modal-text" id="noAnnouncementsMsg">No announcements have been sent yet.</p>
            @else
                <table class="data-table" style="width: 100%;" id="announcementsTable">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Message</th>
                            <th>Sent</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="announcementsTableBody">
                        @foreach($announcements as $announcement)
                            <tr data-announcement-id="{{ $announcement->id }}">
                                <td>{{ $announcement->title }}</td>
                                <td>{{ Str::limit($announcement->body, 80) }}</td>
                                <td>{{ $announcement->created_at->format('M d, Y g:i A') }}</td>
                                <td>
                                    <button
                                        type="button"
                                        class="row-delete-btn"
                                        data-delete-url="{{ route('admin.announcements.destroy', $announcement) }}"
                                        aria-label="Delete announcement"
                                    >Delete</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div style="margin-top: 16px;">
                    {{ $announcements->links() }}
                </div>
            @endif
        </div>
    

    <div class="toast-container" id="toastContainer"></div>

@endsection
