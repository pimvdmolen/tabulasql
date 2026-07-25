<?php

namespace App\Services;

use App\Exceptions\PacketTooLargeException;
use App\Models\Connection;
use Throwable;

/**
 * Copies tables and views between connections/databases: structure via
 * SHOW CREATE, data in chunked batched INSERTs with FK checks disabled on
 * the target session. Errors are reported per object without stopping the
 * run.
 */
class TableCopier
{
    public const CHUNK_ROWS = 1000;

    /** Copy order: tables/views first, then routines, triggers last (they need their table to already exist). */
    private const TYPE_RANK = ['table' => 0, 'view' => 1, 'procedure' => 2, 'function' => 2, 'event' => 2, 'trigger' => 3];

    private const DROP_KEYWORDS = [
        'table' => 'TABLE', 'view' => 'VIEW', 'procedure' => 'PROCEDURE',
        'function' => 'FUNCTION', 'trigger' => 'TRIGGER', 'event' => 'EVENT',
    ];

    public function __construct(
        private ConnectionManager $manager,
        private SchemaExplorer $explorer,
    ) {}

    /**
     * @param  array<int, array{name: string, type: 'table'|'view'|'procedure'|'function'|'trigger'|'event'}>  $objects
     * @param  'skip'|'drop'  $conflict
     * @param  ?callable(string $message): void  $progress
     * @return array{copied: int, skipped: int, failed: int, rows: int, errors: array<string, string>, packetTooLarge: array<string, array{current: int, suggested: int}>}
     */
    public function copy(
        Connection $source,
        string $sourceDatabase,
        array $objects,
        Connection $target,
        string $targetDatabase,
        bool $withData,
        string $conflict = 'skip',
        ?callable $progress = null,
    ): array {
        $report = fn (string $message) => $progress === null ? null : $progress($message);

        $sourceDb = $this->manager->db($source, $sourceDatabase);
        $targetDb = $this->manager->db($target, $targetDatabase);

        $targetDb->statement('SET FOREIGN_KEY_CHECKS = 0');

        $summary = ['copied' => 0, 'skipped' => 0, 'failed' => 0, 'rows' => 0, 'errors' => [], 'packetTooLarge' => []];

        usort($objects, fn ($a, $b) => (self::TYPE_RANK[$a['type']] ?? 0) <=> (self::TYPE_RANK[$b['type']] ?? 0));

        $existing = $this->existingNames($target, $targetDatabase);

        foreach ($objects as $object) {
            $name = $object['name'];
            $type = $object['type'];
            $label = "$type `$name`";

            try {
                if (isset($existing[$type][$name])) {
                    if ($conflict === 'skip') {
                        $summary['skipped']++;
                        $report("Skipped $label, already exists on target.");

                        continue;
                    }

                    $targetDb->statement(sprintf(
                        'DROP %s IF EXISTS %s',
                        self::DROP_KEYWORDS[$type] ?? 'TABLE',
                        $this->explorer->quote($name)
                    ));
                }

                $ddl = $this->createStatement($source, $sourceDatabase, $name, $type);
                $targetDb->statement($ddl);
                $report("Created $label.");

                if ($withData && $type === 'table') {
                    $rows = $this->copyData($sourceDb, $targetDb, $sourceDatabase, $name, $report);
                    $summary['rows'] += $rows;
                }

                $summary['copied']++;
            } catch (PacketTooLargeException $e) {
                $summary['failed']++;
                $summary['errors'][$name] = $e->getMessage();
                $summary['packetTooLarge'][$name] = [
                    'current' => $e->currentMaxAllowedPacket,
                    'suggested' => $e->suggestedMaxAllowedPacket,
                ];
                $report("FAILED $label. ".$e->getMessage());
            } catch (Throwable $e) {
                $summary['failed']++;
                $summary['errors'][$name] = $e->getMessage();
                $report("FAILED $label. ".$e->getMessage());
            }
        }

        $targetDb->statement('SET FOREIGN_KEY_CHECKS = 1');

        if ($summary['copied'] > 0) {
            $this->explorer->forgetDatabase($target, $targetDatabase);
        }

        return $summary;
    }

    /**
     * Object names already on the target, one set per type, so a table and
     * a same-named procedure don't collide when checking for conflicts.
     *
     * @return array<string, array<string, true>>
     */
    private function existingNames(Connection $target, string $targetDatabase): array
    {
        $names = fn (array $items) => array_fill_keys($items, true);
        $tables = $this->explorer->tables($target, $targetDatabase);

        return [
            'table' => $names(array_column(array_filter($tables, fn ($t) => $t['type'] === 'table'), 'name')),
            'view' => $names(array_column(array_filter($tables, fn ($t) => $t['type'] === 'view'), 'name')),
            'procedure' => $names($this->explorer->procedures($target, $targetDatabase)),
            'function' => $names($this->explorer->functions($target, $targetDatabase)),
            'trigger' => $names(array_column($this->explorer->triggers($target, $targetDatabase), 'name')),
            'event' => $names($this->explorer->events($target, $targetDatabase)),
        ];
    }

    /**
     * SHOW CREATE, cleaned up for cross-server use: DEFINER clauses are
     * stripped (the definer user may not exist on the target) and the source
     * database qualifier is removed from view definitions.
     */
    private function createStatement(Connection $source, string $database, string $name, string $type): string
    {
        $ddl = match ($type) {
            'procedure' => $this->explorer->procedureDdl($source, $database, $name),
            'function' => $this->explorer->functionDdl($source, $database, $name),
            'trigger' => $this->explorer->triggerDdl($source, $database, $name),
            'event' => $this->explorer->eventDdl($source, $database, $name),
            default => $this->explorer->ddl($source, $database, $name),
        };

        if ($ddl === '') {
            throw new \RuntimeException('Could not read the CREATE statement.');
        }

        if ($type !== 'table') {
            $ddl = preg_replace('/DEFINER=\S+\s+/', '', $ddl);
        }

        // Strip the source database qualifier so the object (and, for
        // triggers, its "ON schema.table" clause) targets the destination
        // database instead of recreating itself back in the source. Views
        // always carry it; SHOW CREATE TRIGGER's "SQL Original Statement" is
        // the literal text as submitted, so it does too whenever the
        // trigger/table were originally qualified — backtick-quoted or bare,
        // hence the regex rather than a plain str_replace on the quoted form.
        if (in_array($type, ['view', 'trigger'], true)) {
            $ddl = preg_replace('/`?'.preg_quote($database, '/').'`?\./', '', $ddl);
        }

        return $ddl;
    }

    private function copyData($sourceDb, $targetDb, string $sourceDatabase, string $table, callable $report): int
    {
        $quotedSource = $this->explorer->quote($sourceDatabase).'.'.$this->explorer->quote($table);
        $quotedTable = $this->explorer->quote($table);
        $offset = 0;
        $total = 0;

        while (true) {
            $rows = $sourceDb->select(sprintf('SELECT * FROM %s LIMIT %d, %d', $quotedSource, $offset, self::CHUNK_ROWS));

            if ($rows === []) {
                break;
            }

            $columns = array_keys((array) $rows[0]);
            $quotedColumns = implode(', ', array_map($this->explorer->quote(...), $columns));

            // Stay well under MySQL's 65k placeholder limit.
            $chunkSize = max(1, intdiv(60000, max(1, count($columns))));

            foreach (array_chunk($rows, $chunkSize) as $chunk) {
                $this->insertAdaptive($targetDb, $quotedTable, $quotedColumns, $columns, $chunk);
            }

            $total += count($rows);
            $report("  `$table`: $total row(s) copied…");

            if (count($rows) < self::CHUNK_ROWS) {
                break;
            }

            $offset += self::CHUNK_ROWS;
        }

        return $total;
    }

    /**
     * Inserts a chunk of rows, halving it and retrying on a
     * max_allowed_packet failure so one oversized batch doesn't fail rows
     * that would otherwise fit. If even a single row is too big for the
     * server's current limit, throws a PacketTooLargeException describing
     * the shortfall instead of letting MySQL's raw error surface.
     *
     * @param  string[]  $columns
     * @param  array<int, object>  $chunk
     */
    private function insertAdaptive($targetDb, string $quotedTable, string $quotedColumns, array $columns, array $chunk): void
    {
        if ($chunk === []) {
            return;
        }

        $placeholderRow = '('.implode(', ', array_fill(0, count($columns), '?')).')';
        $bindings = [];

        foreach ($chunk as $row) {
            foreach ((array) $row as $value) {
                $bindings[] = $value;
            }
        }

        try {
            $targetDb->insert(sprintf(
                'INSERT INTO %s (%s) VALUES %s',
                $quotedTable, $quotedColumns,
                implode(', ', array_fill(0, count($chunk), $placeholderRow))
            ), $bindings);
        } catch (Throwable $e) {
            if (! $this->isPacketTooLarge($e)) {
                throw $e;
            }

            if (count($chunk) === 1) {
                throw $this->packetTooLargeException($targetDb, (array) $chunk[0], $e);
            }

            $half = intdiv(count($chunk), 2);
            $this->insertAdaptive($targetDb, $quotedTable, $quotedColumns, $columns, array_slice($chunk, 0, $half));
            $this->insertAdaptive($targetDb, $quotedTable, $quotedColumns, $columns, array_slice($chunk, $half));
        }
    }

    private function isPacketTooLarge(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'max_allowed_packet')
            || str_contains($message, 'server has gone away')
            || str_contains($message, 'Lost connection to MySQL server during query');
    }

    private function packetTooLargeException($targetDb, array $row, Throwable $previous): PacketTooLargeException
    {
        $needed = 0;

        foreach ($row as $value) {
            $needed += is_string($value) ? strlen($value) : 8;
        }

        $current = (int) ($targetDb->selectOne('SELECT @@max_allowed_packet AS v')->v ?? 1_048_576);

        return new PacketTooLargeException($current, $needed, $previous);
    }
}
