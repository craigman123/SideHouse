@extends('layouts.app')

@section('title', 'Database Query | Admin')
@section('page-title', 'Database Query')

@section('content')
<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-lg-3 col-xl-3">
            <!-- Tables -->
            <div class="sidebar-card">
                <div class="card-header">
                    <h3><i class="fas fa-database mr-2"></i> Tables</h3>
                    <button type="button" class="btn-tool" data-card-widget="collapse">
                        <i class="fas fa-minus"></i>
                    </button>
                </div>
                <div class="card-body">
                    <ul class="nav nav-pills flex-column" id="table-list">
                        @foreach($tables as $table)
                            <li class="nav-item">
                                <a href="#" class="table-item" data-table="{{ $table }}">
                                    <span><i class="fas fa-table mr-2"></i> {{ $table }}</span>
                                    <span class="badge">{{ count($tableSchemas[$table] ?? []) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="card-footer">
                    <i class="fas fa-list mr-1"></i> {{ count($tables) }} tables
                </div>
            </div>

            <!-- Snippets -->
            <div class="sidebar-card">
                <div class="card-header">
                    <h3><i class="fas fa-code mr-2"></i> Snippets</h3>
                    <button type="button" class="btn-tool" data-card-widget="collapse">
                        <i class="fas fa-minus"></i>
                    </button>
                </div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        <li class="snippet-item" data-query="SELECT pg_size_pretty(pg_total_relation_size(relid)) AS size, relname AS table_name FROM pg_catalog.pg_statio_user_tables ORDER BY pg_total_relation_size(relid) DESC;">
                            <i class="fas fa-chart-pie mr-2"></i> Table sizes
                        </li>
                        <li class="snippet-item" data-query="SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' ORDER BY tablename;">
                            <i class="fas fa-list-ol mr-2"></i> All indexes
                        </li>
                        <li class="snippet-item" data-query="SELECT pid, now() - query_start AS duration, query FROM pg_stat_activity WHERE state = 'active' ORDER BY duration DESC;">
                            <i class="fas fa-hourglass-half mr-2"></i> Active queries
                        </li>
                        <li class="snippet-item" data-query="SELECT schemaname, tablename, seq_scan, seq_tup_read, idx_scan, idx_tup_fetch FROM pg_stat_user_tables ORDER BY seq_scan DESC LIMIT 10;">
                            <i class="fas fa-search mr-2"></i> Table usage stats
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Recent Queries -->
            <div class="sidebar-card">
                <div class="card-header">
                    <h3><i class="fas fa-clock mr-2"></i> Recent</h3>
                    <div>
                        @if(count($recentQueries) > 0)
                            <button type="button" class="btn-tool" id="clear-recent-queries" title="Clear all">
                                <i class="fas fa-trash"></i>
                            </button>
                        @endif
                        <button type="button" class="btn-tool" data-card-widget="collapse">
                            <i class="fas fa-minus"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    @if(count($recentQueries) > 0)
                        <ul class="list-group list-group-flush">
                            @foreach($recentQueries as $query)
                                <li class="recent-query-item" data-query="{{ $query['query'] }}">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <small class="text-muted">{{ $query['time'] }}</small>
                                        @if(!empty($query['type']))
                                            <span class="query-type-badge type-{{ strtolower($query['type']) }}">{{ $query['type'] }}</span>
                                        @endif
                                    </div>
                                    <code class="d-block text-truncate">{{ $query['query'] }}</code>
                                    <small class="text-muted">{{ $query['rows'] ?? 0 }} row(s)</small>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-muted text-center py-3 mb-0">
                            <i class="fas fa-inbox fa-2x d-block mb-2"></i>
                            No queries yet
                        </p>
                    @endif
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="col-lg-9 col-xl-9">
            <div class="query-editor-card">
                <div class="card-header">
                    <h3><i class="fas fa-terminal mr-2"></i> Query Editor</h3>
                    <div>
                        <span class="badge query-type-badge" id="query-type-badge" style="display:none;"></span>
                    </div>
                </div>
                <div class="card-body">
                    <form id="query-form">
                        @csrf
                        <div class="form-group">
                            <label for="query-input" class="sr-only">SQL Query</label>
                            <textarea
                                class="form-control"
                                id="query-input"
                                name="query"
                                rows="7"
                                placeholder="SELECT * FROM bookings WHERE status = 'pending' LIMIT 10;"
                                spellcheck="false"
                            ></textarea>
                            <div class="d-flex justify-content-between mt-2">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle"></i> 
                                    SELECT / SHOW / DESCRIBE / EXPLAIN run instantly. Destructive queries ask for confirmation.
                                </small>
                                <small class="text-muted">
                                    <kbd>Ctrl</kbd> + <kbd>Enter</kbd> to execute
                                </small>
                            </div>
                        </div>
                        
                        <div class="btn-group-flex">
                            <button type="submit" class="btn btn-primary-query" id="execute-query">
                                <i class="fas fa-play"></i> Execute
                            </button>
                            <button type="button" class="btn btn-success-query" id="export-query">
                                <i class="fas fa-file-export"></i> CSV
                            </button>
                            <button type="button" class="btn btn-info-query" id="format-query">
                                <i class="fas fa-magic"></i> Format
                            </button>
                            <button type="button" class="btn btn-outline-query" id="copy-json" title="Copy results as JSON">
                                <i class="far fa-copy"></i> JSON
                            </button>
                            <button type="button" class="btn btn-outline-query" id="copy-csv" title="Copy results as CSV">
                                <i class="far fa-copy"></i> CSV
                            </button>
                            <button type="button" class="btn btn-secondary-query" id="clear-query">
                                <i class="fas fa-eraser"></i> Clear
                            </button>
                        </div>
                    </form>

                    <hr>

                    <!-- Table Preview -->
                    <div id="table-preview" style="display: none;">
                        <h5><i class="fas fa-eye mr-2"></i> Table Preview: <span id="preview-table-name" class="text-primary"></span></h5>
                        <div id="preview-results"></div>
                    </div>

                    <!-- Results -->
                    <div id="query-results" style="display: none;">
                        <div class="results-header">
                            <h5><i class="fas fa-table mr-2"></i> Results</h5>
                            <div class="results-stats">
                                <span class="badge badge-count" id="result-count"></span>
                                <span class="badge badge-time" id="execution-time"></span>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table" id="results-table">
                                <thead id="results-header"><tr></tr></thead>
                                <tbody id="results-body"></tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Error -->
                    <div id="query-error" class="alert alert-danger" style="display: none;"></div>
                </div>
            </div>

            <!-- Schema -->
            <div class="card card-secondary card-outline" id="schema-card" style="display: none;">
                <div class="card-header">
                    <h3><i class="fas fa-sitemap mr-2"></i> Schema: <span id="schema-table-name" class="text-primary"></span></h3>
                    <button type="button" class="btn-tool" data-card-widget="collapse">
                        <i class="fas fa-minus"></i>
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered table-striped mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>Column</th>
                                    <th>Type</th>
                                    <th>Nullable</th>
                                    <th>Default</th>
                                </tr>
                            </thead>
                            <tbody id="schema-body"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Confirmation Modal -->
<div class="confirmation-query-modal fade" id="confirm-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-danger">
                <h5 class="modal-title text-white">
                    <i class="fas fa-exclamation-triangle"></i> Confirm <span id="confirm-query-type"></span>
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p id="confirm-message"></p>
                <div class="form-group mb-0">
                    <label>Type <strong><code id="confirm-phrase-display">EXECUTE</code></strong> to confirm</label>
                    <input type="text" class="form-control" id="confirm-phrase-input" placeholder="Type EXECUTE..." autocomplete="off">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirm-execute-btn" disabled>
                    <i class="fas fa-skull-crossbones"></i> Execute
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('styles')
<link rel="stylesheet" href="{{ asset('css/database-query.css') }}">
@endpush

@push('scripts')
<script>
    window.DBQueryRoutes = {
        execute: "{{ route('admin.database.execute') }}",
        describe: "{{ route('admin.database.describe') }}",
        preview: "{{ route('admin.database.preview') }}",
        export: "{{ route('admin.database.export') }}",
        clearRecent: "{{ route('admin.database.clear-recent') }}",
    };
</script>
<script src="{{ asset('js/database-query.js') }}" defer></script>
@endpush