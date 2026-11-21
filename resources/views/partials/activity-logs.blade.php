<div class="admin-activity-logs-page" @if ($isStaff) data-staff-activity-logs data-data-url="{{ $dataUrl }}" @endif>
    <div class="panel filters-panel activity-filters-panel">
        <form method="GET" action="{{ route($filterRoute) }}" class="filters-form" data-activity-filters>
            <div class="filter-group">
                <label for="user">User</label>
                <input type="text" id="user" name="user" placeholder="Search by user..." value="{{ request('user') }}">
            </div>
            <div class="filter-group">
                <label for="action">Action</label>
                <input type="text" id="action" name="action" placeholder="e.g. booking.created" value="{{ request('action') }}">
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn-filter">Filter</button>
                <a href="{{ route($filterRoute) }}" class="btn-clear" data-activity-clear>Clear</a>
            </div>
        </form>
    </div>

    <div class="panel activity-table-panel">
        <h2>Activity Logs <span class="count-badge" data-activity-total>{{ $logs->total() }}</span></h2>
        <div class="activity-log-error" data-activity-error role="alert" hidden>
            <span>Activity logs could not be loaded.</span>
            <button type="button" data-activity-retry>Retry</button>
        </div>
        <div class="table-responsive">
            <table>
                <thead><tr>
                    <th>When</th><th>User</th><th>Action</th><th>Description</th><th>Subject</th>
                    @unless ($isStaff)<th>IP Address</th>@endunless
                </tr></thead>
                <tbody data-activity-rows>
                    @forelse ($logs as $log)
                        <tr>
                            <td class="cell-muted" data-activity-when>{{ $log->created_at?->format('d M Y, g:i A') ?? '—' }}</td>
                            <td class="cell-name" data-activity-user>{{ $log->user_name ?? 'Guest' }}</td>
                            <td><code data-activity-action>{{ $log->action }}</code></td>
                            <td data-activity-description>{{ $log->description }}</td>
                            <td class="cell-muted" data-activity-subject>{{ $isStaff ? ($log->subject_label ?? '—') : ($log->subject_type ?? '—') }}</td>
                            @unless ($isStaff)<td class="cell-muted">{{ $log->ip_address ?? '—' }}</td>@endunless
                        </tr>
                    @empty
                        <tr><td colspan="{{ $isStaff ? 5 : 6 }}" class="empty-row">No activity recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="activity-results-count" data-activity-count>Showing {{ $logs->firstItem() }} to {{ $logs->lastItem() }} of {{ $logs->total() }} results</p>
        @if ($logs->hasPages())
            <div class="pagination-wrapper" data-activity-pagination>{{ $logs->links() }}</div>
        @else
            <div class="pagination-wrapper" data-activity-pagination></div>
        @endif
    </div>
</div>
