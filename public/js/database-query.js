/**
 * Database Query Tool - Modern UI
 * Admin interface for executing SQL queries with enhanced UX
 */

(function($) {
    'use strict';

    class DatabaseQueryTool {
        constructor() {
            // DOM Elements
            this.$queryInput = $('#query-input');
            this.$results = $('#query-results');
            this.$error = $('#query-error');
            this.$resultsHeader = $('#results-header');
            this.$resultsBody = $('#results-body');
            this.$resultCount = $('#result-count');
            this.$executionTime = $('#execution-time');
            this.$tablePreview = $('#table-preview');
            this.$schemaCard = $('#schema-card');
            this.$schemaBody = $('#schema-body');
            this.$schemaTableName = $('#schema-table-name');
            this.$previewTableName = $('#preview-table-name');
            this.$queryTypeBadge = $('#query-type-badge');
            this.$confirmModal = $('#confirm-modal');
            this.$confirmMessage = $('#confirm-message');
            this.$confirmQueryType = $('#confirm-query-type');
            this.$confirmPhraseDisplay = $('#confirm-phrase-display');
            this.$confirmPhraseInput = $('#confirm-phrase-input');
            this.$confirmExecuteBtn = $('#confirm-execute-btn');
            
            // State
            this.pendingQuery = null;
            this.pendingConfirmData = null;
            this.currentResults = null;
            
            this.init();
        }

        init() {
            this.bindEvents();
            this.setupCodeHighlight();
        }

        bindEvents() {
            // Form submission
            $('#query-form').on('submit', (e) => {
                e.preventDefault();
                this.executeQuery(this.$queryInput.val());
            });

            // Table click
            $(document).on('click', '.table-item', (e) => {
                e.preventDefault();
                const $item = $(e.currentTarget);
                const table = $item.data('table');
                
                // Highlight active
                $('.table-item').removeClass('active');
                $item.addClass('active');
                
                this.$queryInput.val(`SELECT * FROM "${table}" LIMIT 10;`);
                this.executeQuery(this.$queryInput.val());
                this.showTableSchema(table);
                this.showTablePreview(table);
            });

            // Snippet click
            $(document).on('click', '.snippet-item', (e) => {
                const query = $(e.currentTarget).data('query');
                this.$queryInput.val(query);
                this.executeQuery(query);
            });

            // Recent query click
            $(document).on('click', '.recent-query-item', (e) => {
                const query = $(e.currentTarget).data('query');
                this.$queryInput.val(query);
                this.executeQuery(query);
            });

            // Clear query
            $('#clear-query').on('click', () => {
                this.clearAll();
            });

            // Format query
            $('#format-query').on('click', () => {
                this.formatQuery();
            });

            // Export CSV
            $('#export-query').on('click', () => {
                this.exportQuery();
            });

            // Copy as JSON
            $('#copy-json').on('click', () => {
                this.copyResults('json');
            });

            // Copy as CSV
            $('#copy-csv').on('click', () => {
                this.copyResults('csv');
            });

            // Clear recent queries
            $('#clear-recent-queries').on('click', () => {
                this.clearRecentQueries();
            });

            // Confirm modal
            this.$confirmPhraseInput.on('input', () => {
                const phrase = this.$confirmPhraseInput.val().trim();
                const expected = 'EXECUTE';
                this.$confirmExecuteBtn.prop('disabled', phrase !== expected);
                this.$confirmPhraseInput.toggleClass('is-valid', phrase === expected);
            });

            $('#confirm-execute-btn').on('click', () => {
                this.executeConfirmedQuery();
            });

            // Keyboard shortcut: Ctrl+Enter
            this.$queryInput.on('keydown', (e) => {
                if (e.ctrlKey && e.key === 'Enter') {
                    e.preventDefault();
                    this.executeQuery(this.$queryInput.val());
                }
            });

            // Auto-hide error on click
            this.$error.on('click', function() {
                $(this).fadeOut(300);
            });

            // Detect query type on input
            this.$queryInput.on('input', () => {
                this.detectAndShowQueryType();
            });
        }

        setupCodeHighlight() {
            // Add line numbers or syntax highlighting if needed
        }

        detectAndShowQueryType() {
            const query = this.$queryInput.val().trim();
            if (!query) {
                this.$queryTypeBadge.hide();
                return;
            }

            const type = this.detectQueryType(query);
            const destructive = ['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'DROP', 'ALTER', 'CREATE'].includes(type);
            
            this.$queryTypeBadge
                .text(type)
                .removeClass('type-select type-insert type-update type-delete type-truncate type-drop type-alter type-create')
                .addClass(`type-${type.toLowerCase()}`)
                .show();
        }

        detectQueryType(query) {
            const match = query.trim().match(/^\s*([a-zA-Z]+)/);
            return match ? match[1].toUpperCase() : 'UNKNOWN';
        }

        executeQuery(query) {
            if (!query || !query.trim()) {
                this.showError('Please enter a SQL query.');
                return;
            }

            this.hideAllResults();
            this.$error.hide();

            const $executeBtn = $('#execute-query');
            const originalHtml = $executeBtn.html();
            $executeBtn
                .prop('disabled', true)
                .addClass('btn-loading')
                .html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Running...');

            $.ajax({
                url: window.DBQueryRoutes.execute,
                method: 'POST',
                data: {
                    _token: $('meta[name="csrf-token"]').attr('content'),
                    query: query
                },
                success: (data) => {
                    if (data.requires_confirmation) {
                        this.showConfirmationModal(data, query);
                        return;
                    }
                    this.displayResults(data);
                    this.$results.show();
                },
                error: (xhr) => {
                    const errorMsg = xhr.responseJSON?.error || 'An error occurred executing the query.';
                    this.showError(errorMsg);
                    this.$results.hide();
                },
                complete: () => {
                    $executeBtn
                        .prop('disabled', false)
                        .removeClass('btn-loading')
                        .html(originalHtml);
                }
            });
        }

        showConfirmationModal(data, query) {
            this.pendingQuery = query;
            this.pendingConfirmData = data;
            
            this.$confirmQueryType.text(data.query_type);
            this.$confirmMessage.text(data.message);
            this.$confirmPhraseDisplay.text('EXECUTE');
            this.$confirmPhraseInput.val('');
            this.$confirmPhraseInput.removeClass('is-valid');
            this.$confirmExecuteBtn.prop('disabled', true);
            
            this.$confirmModal.modal('show');
        }

        executeConfirmedQuery() {
            this.$confirmModal.modal('hide');

            const $executeBtn = $('#execute-query');
            const originalHtml = $executeBtn.html();
            $executeBtn
                .prop('disabled', true)
                .addClass('btn-loading')
                .html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Executing...');

            $.ajax({
                url: window.DBQueryRoutes.execute,
                method: 'POST',
                data: {
                    _token: $('meta[name="csrf-token"]').attr('content'),
                    query: this.pendingQuery,
                    confirm: true
                },
                success: (data) => {
                    if (data.success) {
                        this.displayResults(data, true);
                        this.$results.show();
                        
                        // Show success toast
                        this.showToast(
                            `Query executed successfully. ${data.affected_rows || ''} row(s) affected.`,
                            'success'
                        );
                    }
                },
                error: (xhr) => {
                    const errorMsg = xhr.responseJSON?.error || 'An error occurred executing the query.';
                    this.showError(errorMsg);
                    this.$results.hide();
                },
                complete: () => {
                    $executeBtn
                        .prop('disabled', false)
                        .removeClass('btn-loading')
                        .html(originalHtml);
                }
            });
        }

        displayResults(data, destructive = false) {
            if (!data.results || data.results.length === 0) {
                this.$resultsHeader.html('<tr></tr>');
                this.$resultsBody.html(`
                    <tr>
                        <td colspan="100" class="text-center text-muted py-4">
                            <i class="fas fa-info-circle"></i> No results found.
                            ${destructive ? `<br><small>${data.affected_rows || 0} row(s) affected.</small>` : ''}
                        </td>
                    </tr>
                `);
                this.$resultCount.text('0 rows');
                this.$executionTime.text(data.execution_time + 'ms');
                return;
            }

            this.currentResults = data.results;
            const columns = Object.keys(data.results[0]);
            
            // Build header with sortable columns
            let headerHtml = '';
            columns.forEach(col => {
                headerHtml += `<th data-column="${col}">${this.escapeHtml(col)}</th>`;
            });
            this.$resultsHeader.html('<tr>' + headerHtml + '</tr>');

            // Build body
            let bodyHtml = '';
            data.results.forEach((row, index) => {
                bodyHtml += `<tr data-row="${index}">`;
                columns.forEach(col => {
                    let value = row[col];
                    let displayValue;
                    
                    if (value === null) {
                        displayValue = '<span class="null-value">NULL</span>';
                    } else if (typeof value === 'object') {
                        displayValue = `<code class="text-primary">${this.escapeHtml(JSON.stringify(value))}</code>`;
                    } else if (typeof value === 'boolean') {
                        displayValue = value ? '✓' : '✗';
                    } else {
                        displayValue = this.escapeHtml(String(value));
                    }
                    
                    bodyHtml += `<td data-column="${col}">${displayValue}</td>`;
                });
                bodyHtml += '</tr>';
            });
            this.$resultsBody.html(bodyHtml);

            this.$resultCount.text(data.results.length + ' rows');
            this.$executionTime.text(data.execution_time + 'ms');
            
            // Add destructive indicator if applicable
            if (destructive) {
                this.$resultCount.append(` <span class="badge badge-destructive">DESTRUCTIVE</span>`);
            }
        }

        showTableSchema(table) {
            $.ajax({
                url: window.DBQueryRoutes.describe,
                method: 'POST',
                data: {
                    _token: $('meta[name="csrf-token"]').attr('content'),
                    table: table
                },
                success: (data) => {
                    if (data.success) {
                        this.$schemaTableName.text(table);
                        this.$schemaBody.empty();
                        
                        data.columns.forEach(col => {
                            const columnName = col.column_name || col.Field || '';
                            const dataType = col.data_type || col.Type || '';
                            const isNullable = col.is_nullable === 'YES' || col.Null === 'YES';
                            const defaultValue = col.column_default || col.Default || null;
                            
                            const nullableBadge = isNullable 
                                ? '<span class="badge badge-success">Yes</span>' 
                                : '<span class="badge badge-danger">No</span>';
                            
                            const defaultDisplay = defaultValue !== null && defaultValue !== undefined
                                ? this.escapeHtml(String(defaultValue))
                                : '<span class="text-muted">NULL</span>';
                            
                            this.$schemaBody.append(`
                                <tr>
                                    <td><code>${this.escapeHtml(columnName)}</code></td>
                                    <td><code>${this.escapeHtml(dataType)}</code></td>
                                    <td>${nullableBadge}</td>
                                    <td>${defaultDisplay}</td>
                                </tr>
                            `);
                        });
                        
                        this.$schemaCard.slideDown(300);
                    }
                },
                error: (xhr) => {
                    console.error('Error fetching schema:', xhr.responseText);
                }
            });
        }

        showTablePreview(table) {
            $.ajax({
                url: window.DBQueryRoutes.preview,
                method: 'POST',
                data: {
                    _token: $('meta[name="csrf-token"]').attr('content'),
                    table: table
                },
                success: (data) => {
                    if (data.success) {
                        this.$previewTableName.text(table);
                        
                        if (!data.results || data.results.length === 0) {
                            $('#preview-results').html('<p class="text-muted py-3">No data in this table.</p>');
                            this.$tablePreview.slideDown(300);
                            return;
                        }

                        const columns = Object.keys(data.results[0]);
                        let html = `
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered table-striped">
                                    <thead>
                                        <tr>
                        `;
                        
                        columns.forEach(col => {
                            html += `<th>${this.escapeHtml(col)}</th>`;
                        });
                        
                        html += `
                                        </tr>
                                    </thead>
                                    <tbody>
                        `;
                        
                        data.results.forEach(row => {
                            html += '<tr>';
                            columns.forEach(col => {
                                let value = row[col] !== null ? row[col] : 'NULL';
                                if (typeof value === 'object') {
                                    value = JSON.stringify(value);
                                }
                                html += `<td>${this.escapeHtml(String(value))}</td>`;
                            });
                            html += '</tr>';
                        });
                        
                        html += `
                                    </tbody>
                                </table>
                            </div>
                        `;
                        
                        $('#preview-results').html(html);
                        this.$tablePreview.slideDown(300);
                    }
                },
                error: (xhr) => {
                    console.error('Error fetching preview:', xhr.responseText);
                }
            });
        }

        formatQuery() {
            let query = this.$queryInput.val();
            
            // Clean up whitespace
            query = query.replace(/\s+/g, ' ').trim();
            
            // Format SQL
            query = query
                .replace(/SELECT /gi, 'SELECT\n  ')
                .replace(/FROM /gi, '\nFROM ')
                .replace(/WHERE /gi, '\nWHERE ')
                .replace(/AND /gi, '\n  AND ')
                .replace(/OR /gi, '\n  OR ')
                .replace(/ORDER BY /gi, '\nORDER BY ')
                .replace(/GROUP BY /gi, '\nGROUP BY ')
                .replace(/HAVING /gi, '\nHAVING ')
                .replace(/LIMIT /gi, '\nLIMIT ')
                .replace(/JOIN /gi, '\nJOIN ')
                .replace(/LEFT JOIN /gi, '\nLEFT JOIN ')
                .replace(/RIGHT JOIN /gi, '\nRIGHT JOIN ')
                .replace(/INNER JOIN /gi, '\nINNER JOIN ')
                .replace(/OUTER JOIN /gi, '\nOUTER JOIN ')
                .replace(/UNION /gi, '\nUNION ')
                .replace(/CROSS JOIN /gi, '\nCROSS JOIN ')
                .replace(/,\s*/g, ',\n  ');
            
            this.$queryInput.val(query);
            this.showToast('Query formatted!', 'info');
        }

        exportQuery() {
            const query = this.$queryInput.val();
            
            if (!query || !query.trim()) {
                this.showError('Please enter a query first.');
                return;
            }

            const $btn = $('#export-query');
            const originalHtml = $btn.html();
            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Exporting...');

            $.ajax({
                url: window.DBQueryRoutes.export,
                method: 'POST',
                data: {
                    _token: $('meta[name="csrf-token"]').attr('content'),
                    query: query
                },
                xhrFields: {
                    responseType: 'blob'
                },
                success: (data, status, xhr) => {
                    const filename = xhr.getResponseHeader('Content-Disposition')
                        ?.split('filename="')[1]?.slice(0, -1) || 'query_export.csv';
                    
                    const blob = new Blob([data], { type: 'text/csv' });
                    const link = document.createElement('a');
                    link.href = window.URL.createObjectURL(blob);
                    link.download = filename;
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    
                    this.showToast('Export completed!', 'success');
                },
                error: (xhr) => {
                    const errorMsg = xhr.responseJSON?.error || 'Export failed.';
                    this.showError(errorMsg);
                },
                complete: () => {
                    $btn.prop('disabled', false).html(originalHtml);
                }
            });
        }

        copyResults(format) {
            if (!this.currentResults || this.currentResults.length === 0) {
                this.showError('No results to copy. Run a query first.');
                return;
            }

            let text = '';
            const columns = Object.keys(this.currentResults[0]);

            if (format === 'json') {
                text = JSON.stringify(this.currentResults, null, 2);
            } else {
                // CSV
                text = columns.join(',') + '\n';
                this.currentResults.forEach(row => {
                    text += columns.map(col => {
                        let val = row[col];
                        if (val === null) return '';
                        if (typeof val === 'string' && (val.includes(',') || val.includes('"'))) {
                            return `"${val.replace(/"/g, '""')}"`;
                        }
                        return val;
                    }).join(',') + '\n';
                });
            }

            navigator.clipboard.writeText(text).then(() => {
                this.showToast(`Copied as ${format.toUpperCase()}!`, 'success');
            }).catch(() => {
                // Fallback
                const textarea = document.createElement('textarea');
                textarea.value = text;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                this.showToast(`Copied as ${format.toUpperCase()}!`, 'success');
            });
        }

        clearRecentQueries() {
            if (!confirm('Clear all recent queries?')) return;
            
            $.ajax({
                url: window.DBQueryRoutes.clearRecent,
                method: 'DELETE',
                data: { _token: $('meta[name="csrf-token"]').attr('content') },
                success: () => {
                    location.reload();
                },
                error: (xhr) => {
                    console.error('Error clearing recent queries:', xhr.responseText);
                }
            });
        }

        clearAll() {
            this.$queryInput.val('');
            this.$queryTypeBadge.hide();
            this.hideAllResults();
            this.$error.hide();
        }

        hideAllResults() {
            this.$results.slideUp(300);
            this.$tablePreview.slideUp(300);
            this.$schemaCard.slideUp(300);
        }

        showError(message) {
            this.$error.html(`<i class="fas fa-exclamation-circle"></i> ${message}`).slideDown(300);
            
            clearTimeout(this.errorTimeout);
            this.errorTimeout = setTimeout(() => {
                this.$error.slideUp(500);
            }, 8000);
        }

        showToast(message, type = 'info') {
            // Simple toast notification
            const toast = $(`
                <div class="toast-notification toast-${type}">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                    ${message}
                </div>
            `);
            
            const styles = {
                position: 'fixed',
                bottom: '20px',
                right: '20px',
                padding: '12px 20px',
                borderRadius: '8px',
                background: type === 'success' ? '#22c55e' : type === 'error' ? '#ef4444' : '#3b82f6',
                color: 'white',
                boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
                zIndex: 9999,
                animation: 'slideUp 0.3s ease',
                maxWidth: '400px',
                fontSize: '0.9rem',
                fontWeight: '500'
            };
            
            toast.css(styles);
            toast.find('i').css('marginRight', '8px');
            
            $('body').append(toast);
            
            setTimeout(() => {
                toast.fadeOut(300, () => toast.remove());
            }, 3000);
        }

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    }

    // Initialize when document is ready
    $(document).ready(() => {
        new DatabaseQueryTool();
    });

})(jQuery);