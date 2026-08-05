<?php

namespace App\Services;

use App\Models\Connection;
use App\Models\QueryHistory;
use Throwable;

/**
 * Executes SQL against a stored connection, measures duration, formats
 * result values for display, and records query history.
 */
class QueryRunner
{
    /** Strings longer than this are collapsed in the grid. */
    public const INLINE_LIMIT = 256;

    /** Hard cap on the value payload shipped to the UI per cell. */
    public const FULL_LIMIT = 65536;

    public function __construct(private ConnectionManager $manager) {}

    /**
     * Run a single statement and return a structured result.
     *
     * Long/binary cell bodies are stashed under `_fulls` (rowIndex:column =>
     * payload) so the Livewire HTML grid only receives previews. Callers that
     * need the body (viewer, copy, export) read `_fulls` or re-fetch.
     *
     * @return array{
     *     ok: bool, error: ?string, columns: string[],
     *     rows: array<int, array<string, mixed>>, row_count: int,
     *     affected: int, duration_ms: int, is_select: bool,
     *     _fulls: array<string, string>
     * }
     */
    public function run(Connection $connection, ?string $database, string $sql, array $bindings = [], bool $log = true): array
    {
        $db = $this->manager->db($connection, $database);
        $start = hrtime(true);

        try {
            $isSelect = $this->returnsRows($sql);

            if ($isSelect) {
                $rows = $db->select($sql, $bindings);
                $affected = 0;
            } else {
                $affected = $db->affectingStatement($sql, $bindings);
                $rows = [];
            }

            $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);

            $columns = $rows === [] ? [] : array_keys((array) $rows[0]);
            $fulls = [];
            $formatted = [];

            foreach ($rows as $rowIndex => $row) {
                $formattedRow = [];

                foreach ((array) $row as $column => $value) {
                    $cell = $this->formatValue($value, includeFull: true);

                    if (is_array($cell)) {
                        if (($cell['full'] ?? null) !== null) {
                            $fulls["$rowIndex:$column"] = $cell['full'];
                        }
                        // Keep the grid HTML tiny — bodies live in `_fulls`.
                        $cell['full'] = null;
                        $cell['lazy'] = true;
                    }

                    $formattedRow[$column] = $cell;
                }

                $formatted[] = $formattedRow;
            }

            if ($log) {
                $this->record($connection, $database, $sql, $durationMs, $isSelect ? count($rows) : $affected);
            }

            return [
                'ok' => true,
                'error' => null,
                'columns' => $columns,
                'rows' => $formatted,
                'row_count' => count($formatted),
                'affected' => $affected,
                'duration_ms' => $durationMs,
                'is_select' => $isSelect,
                '_fulls' => $fulls,
            ];
        } catch (Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);

            return [
                'ok' => false,
                'error' => $this->cleanError($e),
                'columns' => [],
                'rows' => [],
                'row_count' => 0,
                'affected' => 0,
                'duration_ms' => $durationMs,
                'is_select' => false,
                '_fulls' => [],
            ];
        }
    }

    /**
     * Convert a raw column value into a UI-safe representation.
     *
     * Scalars pass through; NULL stays null; binary or oversized values
     * become ['blob' => true|false, 'size' => int, 'preview' => string,
     * 'full' => ?string, 'lazy' => bool, 'truncated' => bool].
     *
     * When $includeFull is false (grid path), `full` is omitted from the
     * payload so Livewire/HTML stays small; the caller can stash bodies
     * separately or re-fetch on demand.
     */
    public function formatValue(mixed $value, bool $includeFull = true): mixed
    {
        if ($value === null || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        $value = (string) $value;
        $size = strlen($value);
        $isBinary = ! preg_match('//u', $value);

        if (! $isBinary && $size <= self::INLINE_LIMIT) {
            return $value;
        }

        $truncated = $size > self::FULL_LIMIT;
        $slice = substr($value, 0, self::FULL_LIMIT);

        return [
            'blob' => $isBinary,
            'size' => $size,
            'preview' => $isBinary
                ? strtoupper(bin2hex(substr($value, 0, 16)))
                : mb_substr($value, 0, 60, 'UTF-8'),
            'full' => $includeFull ? ($isBinary ? strtoupper(bin2hex($slice)) : $slice) : null,
            'lazy' => ! $includeFull,
            'truncated' => $truncated,
        ];
    }

    /**
     * True for column types that are expensive to transfer in a browse grid
     * (TEXT / BLOB / JSON). Those should be truncated at the SQL layer.
     */
    public static function isHeavyColumnType(string $type): bool
    {
        return (bool) preg_match('/\b(tiny|medium|long)?(blob|text)\b|\bjson\b/i', $type);
    }

    /**
     * True for binary BLOB types (as opposed to TEXT/JSON).
     */
    public static function isBlobColumnType(string $type): bool
    {
        return (bool) preg_match('/\b(tiny|medium|long)?blob\b/i', $type);
    }

    /**
     * Append a LIMIT to an unlimited SELECT. Returns the (possibly
     * rewritten) SQL and whether a limit was injected.
     *
     * @return array{sql: string, injected: bool}
     */
    public function injectLimit(string $sql, int $limit = 500): array
    {
        $isSelect = (bool) preg_match('/^\s*(SELECT|WITH)\b/i', $sql);
        $hasLimit = (bool) preg_match('/\bLIMIT\s+\d+/i', $sql);
        $isLocking = (bool) preg_match('/\b(FOR\s+UPDATE|LOCK\s+IN\s+SHARE\s+MODE|INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i', $sql);

        if (! $isSelect || $hasLimit || $isLocking) {
            return ['sql' => $sql, 'injected' => false];
        }

        return ['sql' => rtrim($sql).' LIMIT '.$limit, 'injected' => true];
    }

    private function returnsRows(string $sql): bool
    {
        return (bool) preg_match(
            '/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|WITH|ANALYZE|CHECK|CHECKSUM|OPTIMIZE|REPAIR)\b/i',
            $sql
        );
    }

    private function record(Connection $connection, ?string $database, string $sql, int $durationMs, int $rows): void
    {
        QueryHistory::create([
            'connection_id' => $connection->id,
            'database' => $database,
            'query' => mb_substr($sql, 0, 65536),
            'duration_ms' => $durationMs,
            'rows_affected' => $rows,
            'executed_at' => now(),
        ]);
    }

    private function cleanError(Throwable $e): string
    {
        // Strip Laravel's "(Connection: conn_1, SQL: ...)" suffix; the UI
        // already shows the statement that failed.
        return preg_replace('/\s*\((Connection|SQL):.*\)\s*$/s', '', $e->getMessage()) ?? $e->getMessage();
    }
}
