<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseQueryController extends Controller
{
    /**
     * Query types that are always safe to run directly (read-only).
     */
    private const SAFE_TYPES = ['SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN'];

    /**
     * Query types that mutate data/schema and require explicit confirmation.
     */
    private const DESTRUCTIVE_TYPES = ['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'DROP', 'ALTER', 'CREATE'];

    /**
     * Display the database query interface.
     */
    public function index()
    {
        $tables = $this->getAllTables();

        $tableSchemas = [];
        foreach ($tables as $table) {
            $tableSchemas[$table] = Schema::getColumnListing($table);
        }

        return view('admin.configuration.query', [
            'tables' => $tables,
            'tableSchemas' => $tableSchemas,
            'recentQueries' => session()->get('recent_queries', []),
        ]);
    }

    /**
     * Execute a query and return results.
     *
     * Safe (read-only) statements run immediately.
     * Destructive statements run inside a transaction first so we can report
     * exactly what would happen; unless `confirm=1` is sent, we roll back
     * and ask the client to confirm before committing.
     */
    public function execute(Request $request)
    {
        $request->validate([
            'query' => ['required', 'string'],
            'confirm' => ['sometimes'],
        ]);

        $rawQuery = trim($request->input('query'));
        $confirm = $request->boolean('confirm');

        // Strip a single trailing semicolon/whitespace, then make sure there
        // isn't a second statement hiding in there (stacked queries).
        $query = rtrim($rawQuery, "; \t\n\r");
        if (str_contains($query, ';')) {
            return response()->json([
                'error' => 'Only one statement may be executed at a time. Remove the extra semicolon(s).',
            ], 422);
        }

        $queryType = $this->detectQueryType($query);

        if (!in_array($queryType, array_merge(self::SAFE_TYPES, self::DESTRUCTIVE_TYPES), true)) {
            return response()->json([
                'error' => "Query type '{$queryType}' is not allowed.",
            ], 422);
        }

        $isDestructive = in_array($queryType, self::DESTRUCTIVE_TYPES, true);

        \Log::{$isDestructive ? 'warning' : 'info'}('Admin database query executed', [
            'user' => auth()->user()->email,
            'query' => $query,
            'type' => $queryType,
            'destructive' => $isDestructive,
            'confirmed' => $confirm,
            'ip' => $request->ip(),
        ]);

        try {
            $startTime = microtime(true);

            if (!$isDestructive) {
                $results = DB::select($query);
                $executionTime = round((microtime(true) - $startTime) * 1000, 2);

                $this->pushRecentQuery($query, count($results), $queryType, false);

                return response()->json([
                    'success' => true,
                    'results' => $results,
                    'count' => count($results),
                    'execution_time' => $executionTime,
                    'query' => $query,
                    'query_type' => $queryType,
                ]);
            }

            // Destructive statement: run inside a transaction so we can
            // preview the effect and roll back if it isn't confirmed yet.
            // Postgres supports transactional DDL, so this also works for
            // TRUNCATE / DROP / ALTER / CREATE, not just DML.
            DB::beginTransaction();

            $affected = null;
            if (in_array($queryType, ['INSERT', 'UPDATE', 'DELETE'], true)) {
                $affected = DB::affectingStatement($query);
            } else {
                DB::statement($query);
            }

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            if (!$confirm) {
                DB::rollBack();

                return response()->json([
                    'requires_confirmation' => true,
                    'query_type' => $queryType,
                    'affected_rows' => $affected,
                    'message' => $this->confirmationMessage($queryType, $affected),
                ]);
            }

            DB::commit();

            $this->pushRecentQuery($query, $affected, $queryType, true);

            return response()->json([
                'success' => true,
                'destructive' => true,
                'affected_rows' => $affected,
                'execution_time' => $executionTime,
                'query' => $query,
                'query_type' => $queryType,
            ]);
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get table structure/schema.
     */
    public function describe(Request $request)
    {
        $request->validate([
            'table' => ['required', 'string'],
        ]);

        $table = $request->input('table');

        try {
            if (config('database.default') === 'pgsql') {
                $columns = DB::select("
                    SELECT
                        column_name,
                        data_type,
                        is_nullable,
                        column_default
                    FROM information_schema.columns
                    WHERE table_name = ?
                    ORDER BY ordinal_position
                ", [$table]);
            } else {
                $columns = DB::select("DESCRIBE {$table}");
            }

            return response()->json([
                'success' => true,
                'columns' => $columns,
                'table' => $table,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get all tables in the database.
     */
    private function getAllTables()
    {
        if (config('database.default') === 'pgsql') {
            $tables = DB::select("
                SELECT table_name
                FROM information_schema.tables
                WHERE table_schema = 'public'
                AND table_type = 'BASE TABLE'
                ORDER BY table_name
            ");
            return array_map(fn ($table) => $table->table_name, $tables);
        }

        $tables = DB::select('SHOW TABLES');
        $tableKey = 'Tables_in_' . config('database.connections.mysql.database');
        return array_map(fn ($table) => $table->$tableKey, $tables);
    }

    /**
     * Get a quick preview of table data (first 10 rows).
     */
    public function preview(Request $request)
    {
        $request->validate([
            'table' => ['required', 'string'],
        ]);

        $table = $request->input('table');

        try {
            $results = DB::table($table)->limit(10)->get();

            return response()->json([
                'success' => true,
                'results' => $results,
                'count' => count($results),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Clear recent queries from session.
     */
    public function clearRecentQueries()
    {
        session()->forget('recent_queries');
        return response()->json(['success' => true]);
    }

    /**
     * Export query results as CSV. Read-only queries only.
     */
    public function export(Request $request)
    {
        $request->validate([
            'query' => ['required', 'string'],
        ]);

        $query = trim($request->input('query'));
        $queryType = $this->detectQueryType($query);

        if ($queryType !== 'SELECT') {
            return response()->json([
                'error' => 'Only SELECT queries can be exported.',
            ], 422);
        }

        try {
            $results = DB::select($query);

            if (empty($results)) {
                return response()->json([
                    'error' => 'No data to export.',
                ], 422);
            }

            $headers = array_keys((array) $results[0]);

            $csvContent = implode(',', $headers) . "\n";
            foreach ($results as $row) {
                $rowArray = (array) $row;
                $csvContent .= implode(',', array_map(function ($value) {
                    if (is_array($value) || is_object($value)) {
                        $value = json_encode($value);
                    }
                    return '"' . str_replace('"', '""', (string) $value) . '"';
                }, $rowArray)) . "\n";
            }

            $filename = 'query_export_' . date('Y-m-d_H-i-s') . '.csv';

            return response($csvContent, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Pull the leading SQL keyword out of a query string reliably.
     * (Str::words($query, 1) is NOT safe for this - it appends "..." on
     * truncation, so "SELECT * FROM x" becomes "SELECT..." not "SELECT".)
     */
    private function detectQueryType(string $query): string
    {
        preg_match('/^\s*([a-zA-Z]+)/', $query, $matches);
        return strtoupper($matches[1] ?? '');
    }

    private function confirmationMessage(string $queryType, ?int $affected): string
    {
        return match ($queryType) {
            'DELETE' => "This will permanently delete {$affected} row(s). This cannot be undone.",
            'UPDATE' => "This will modify {$affected} row(s).",
            'INSERT' => "This will insert {$affected} row(s).",
            'TRUNCATE' => 'This will permanently delete ALL rows in the table. This cannot be undone.',
            'DROP' => 'This will permanently delete the table/object and all its data. This cannot be undone.',
            'ALTER' => 'This will change the table structure. Make sure you have a backup.',
            'CREATE' => 'This will create a new table/object.',
            default => 'This is a destructive operation.',
        };
    }

    private function pushRecentQuery(string $query, ?int $rows, string $queryType, bool $destructive): void
    {
        $recentQueries = session()->get('recent_queries', []);
        array_unshift($recentQueries, [
            'query' => $query,
            'time' => now()->format('Y-m-d H:i:s'),
            'rows' => $rows,
            'type' => $queryType,
            'destructive' => $destructive,
        ]);
        session()->put('recent_queries', array_slice($recentQueries, 0, 20));
    }
}