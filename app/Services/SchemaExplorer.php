<?php

namespace App\Services;

use App\Models\Connection;
use Illuminate\Support\Facades\Cache;

/**
 * Reads MySQL/MariaDB metadata through information_schema and SHOW statements.
 *
 * Every read here is a network round trip (possibly over an SSH tunnel), and
 * schema metadata rarely changes between one table click and the next, so
 * results are cached briefly. Callers that just changed the schema (created
 * a table, added an index/FK, ran DDL) should call forgetTable()/forgetDatabase()
 * so the UI doesn't show stale metadata; the TTL is a safety net for anything
 * that doesn't, and the "Refresh" buttons already in the UI use it too.
 */
class SchemaExplorer
{
    private const TTL = 300;

    public function __construct(private ConnectionManager $manager) {}

    /**
     * Databases visible through this connection. Restricted connections
     * (Connection::$database set) only ever see that one database. The
     * server is never asked to list the others.
     *
     * @return string[]
     */
    public function databases(Connection $connection): array
    {
        if ($connection->database !== null) {
            return [$connection->database];
        }

        return array_column(
            $this->manager->db($connection)->select('SHOW DATABASES'),
            'Database'
        );
    }

    /**
     * Tables and views with row estimate, engine and size.
     *
     * Not cached: TABLE_ROWS is already only an InnoDB estimate, and unlike
     * columns/indexes/DDL it drifts on plain INSERT/DELETE, not just DDL, so
     * a time-based cache would go stale in ways forgetTable()/forgetDatabase()
     * (called after schema changes, not data changes) wouldn't catch.
     *
     * @return array<int, array{name: string, type: string, engine: ?string, rows: ?int, size: ?int}>
     */
    public function tables(Connection $connection, string $database): array
    {
        $rows = $this->manager->db($connection)->select(
            'SELECT TABLE_NAME AS name, TABLE_TYPE AS type, ENGINE AS engine,
                    TABLE_ROWS AS `rows`, (DATA_LENGTH + INDEX_LENGTH) AS size
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ?
             ORDER BY TABLE_NAME',
            [$database]
        );

        return array_map(fn ($row) => [
            'name' => $row->name,
            'type' => $row->type === 'VIEW' ? 'view' : 'table',
            'engine' => $row->engine,
            'rows' => $row->rows === null ? null : (int) $row->rows,
            'size' => $row->size === null ? null : (int) $row->size,
        ], $rows);
    }

    /**
     * Table/view names only. Cached — used for FK convention matching where
     * row estimates are irrelevant and a full TABLES scan on every grid paint
     * is too expensive over SSH.
     *
     * @return string[]
     */
    public function tableNames(Connection $connection, string $database): array
    {
        return Cache::remember($this->key($connection, $database, null, 'tableNames'), self::TTL, function () use ($connection, $database) {
            return array_column(
                $this->manager->db($connection)->select(
                    'SELECT TABLE_NAME AS name FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
                    [$database]
                ),
                'name'
            );
        });
    }

    /**
     * Stored procedure names in a database. Not cached, same reasoning as
     * tables(): this backs the tree listing and should reflect a
     * just-created/dropped procedure immediately.
     *
     * @return string[]
     */
    public function procedures(Connection $connection, string $database): array
    {
        return array_column($this->manager->db($connection)->select(
            "SELECT ROUTINE_NAME AS name FROM information_schema.ROUTINES
             WHERE ROUTINE_SCHEMA = ? AND ROUTINE_TYPE = 'PROCEDURE' ORDER BY ROUTINE_NAME",
            [$database]
        ), 'name');
    }

    /**
     * @return string[]
     */
    public function functions(Connection $connection, string $database): array
    {
        return array_column($this->manager->db($connection)->select(
            "SELECT ROUTINE_NAME AS name FROM information_schema.ROUTINES
             WHERE ROUTINE_SCHEMA = ? AND ROUTINE_TYPE = 'FUNCTION' ORDER BY ROUTINE_NAME",
            [$database]
        ), 'name');
    }

    /**
     * @return array<int, array{name: string, table: string}>
     */
    public function triggers(Connection $connection, string $database): array
    {
        $rows = $this->manager->db($connection)->select(
            'SELECT TRIGGER_NAME AS name, EVENT_OBJECT_TABLE AS tbl FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = ? ORDER BY TRIGGER_NAME',
            [$database]
        );

        return array_map(fn ($row) => ['name' => $row->name, 'table' => $row->tbl], $rows);
    }

    /**
     * @return string[]
     */
    public function events(Connection $connection, string $database): array
    {
        return array_column($this->manager->db($connection)->select(
            'SELECT EVENT_NAME AS name FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ? ORDER BY EVENT_NAME',
            [$database]
        ), 'name');
    }

    public function procedureDdl(Connection $connection, string $database, string $name): string
    {
        $target = sprintf('%s.%s', $this->quote($database), $this->quote($name));
        $row = (array) $this->manager->db($connection)->selectOne("SHOW CREATE PROCEDURE $target");

        return $row['Create Procedure'] ?? '';
    }

    public function functionDdl(Connection $connection, string $database, string $name): string
    {
        $target = sprintf('%s.%s', $this->quote($database), $this->quote($name));
        $row = (array) $this->manager->db($connection)->selectOne("SHOW CREATE FUNCTION $target");

        return $row['Create Function'] ?? '';
    }

    /**
     * The trigger's own CREATE statement, not the parent table's.
     */
    public function triggerDdl(Connection $connection, string $database, string $name): string
    {
        $target = sprintf('%s.%s', $this->quote($database), $this->quote($name));
        $row = (array) $this->manager->db($connection)->selectOne("SHOW CREATE TRIGGER $target");

        return $row['SQL Original Statement'] ?? '';
    }

    public function eventDdl(Connection $connection, string $database, string $name): string
    {
        $target = sprintf('%s.%s', $this->quote($database), $this->quote($name));
        $row = (array) $this->manager->db($connection)->selectOne("SHOW CREATE EVENT $target");

        return $row['Create Event'] ?? '';
    }

    /**
     * @return array<int, array{name: string, type: string, nullable: bool, key: string, default: ?string, extra: string, comment: string}>
     */
    public function columns(Connection $connection, string $database, string $table): array
    {
        return Cache::remember($this->key($connection, $database, $table, 'columns'), self::TTL, function () use ($connection, $database, $table) {
            $rows = $this->manager->db($connection)->select(
                'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable,
                        COLUMN_KEY AS `key`, COLUMN_DEFAULT AS `default`, EXTRA AS extra,
                        COLUMN_COMMENT AS comment
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                 ORDER BY ORDINAL_POSITION',
                [$database, $table]
            );

            return array_map(fn ($row) => [
                'name' => $row->name,
                'type' => $row->type,
                'nullable' => $row->nullable === 'YES',
                'key' => $row->key,
                'default' => $row->default,
                'extra' => $row->extra,
                'comment' => $row->comment,
            ], $rows);
        });
    }

    /**
     * @return array<int, array{name: string, columns: string[], unique: bool, type: string}>
     */
    public function indexes(Connection $connection, string $database, string $table): array
    {
        return Cache::remember($this->key($connection, $database, $table, 'indexes'), self::TTL, function () use ($connection, $database, $table) {
            $rows = $this->manager->db($connection)->select(
                sprintf('SHOW INDEX FROM %s.%s', $this->quote($database), $this->quote($table))
            );

            $indexes = [];
            foreach ($rows as $row) {
                $name = $row->Key_name;
                $indexes[$name] ??= [
                    'name' => $name,
                    'columns' => [],
                    'unique' => ! $row->Non_unique,
                    'type' => $row->Index_type,
                ];
                $indexes[$name]['columns'][(int) $row->Seq_in_index] = $row->Column_name;
            }

            return array_values(array_map(function ($index) {
                ksort($index['columns']);
                $index['columns'] = array_values($index['columns']);

                return $index;
            }, $indexes));
        });
    }

    public function ddl(Connection $connection, string $database, string $table): string
    {
        return Cache::remember($this->key($connection, $database, $table, 'ddl'), self::TTL, function () use ($connection, $database, $table) {
            $target = sprintf('%s.%s', $this->quote($database), $this->quote($table));
            $db = $this->manager->db($connection);

            try {
                $row = (array) $db->selectOne("SHOW CREATE TABLE $target");
            } catch (\Throwable) {
                $row = (array) $db->selectOne("SHOW CREATE VIEW $target");
            }

            return $row['Create Table'] ?? $row['Create View'] ?? '';
        });
    }

    /**
     * All columns of a database keyed by table, for autocompletion.
     *
     * @return array<string, string[]>
     */
    public function allColumns(Connection $connection, string $database): array
    {
        return Cache::remember($this->key($connection, $database, null, 'allColumns'), self::TTL, function () use ($connection, $database) {
            $rows = $this->manager->db($connection)->select(
                'SELECT TABLE_NAME AS tbl, COLUMN_NAME AS col
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ?
                 ORDER BY TABLE_NAME, ORDINAL_POSITION',
                [$database]
            );

            $map = [];
            foreach ($rows as $row) {
                $map[$row->tbl][] = $row->col;
            }

            return $map;
        });
    }

    /**
     * Primary key column names for a table (empty array = no PK).
     *
     * @return string[]
     */
    public function primaryKey(Connection $connection, string $database, string $table): array
    {
        return Cache::remember($this->key($connection, $database, $table, 'primaryKey'), self::TTL, function () use ($connection, $database, $table) {
            $rows = $this->manager->db($connection)->select(
                "SELECT COLUMN_NAME AS name
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY'
                 ORDER BY ORDINAL_POSITION",
                [$database, $table]
            );

            return array_column($rows, 'name');
        });
    }

    /**
     * Foreign key constraints of a table, with names and rules.
     *
     * @return array<int, array{name: string, column: string, ref_table: string, ref_column: string, on_update: string, on_delete: string}>
     */
    public function foreignKeyConstraints(Connection $connection, string $database, string $table): array
    {
        return Cache::remember($this->key($connection, $database, $table, 'foreignKeyConstraints'), self::TTL, function () use ($connection, $database, $table) {
            $rows = $this->manager->db($connection)->select(
                'SELECT k.CONSTRAINT_NAME AS name, k.COLUMN_NAME AS col,
                        k.REFERENCED_TABLE_NAME AS ref_table, k.REFERENCED_COLUMN_NAME AS ref_col,
                        r.UPDATE_RULE AS on_update, r.DELETE_RULE AS on_delete
                 FROM information_schema.KEY_COLUMN_USAGE k
                 JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                   ON r.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
                 WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
                 ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
                [$database, $table]
            );

            return array_map(fn ($row) => [
                'name' => $row->name,
                'column' => $row->col,
                'ref_table' => $row->ref_table,
                'ref_column' => $row->ref_col,
                'on_update' => $row->on_update,
                'on_delete' => $row->on_delete,
            ], $rows);
        });
    }

    /**
     * Bust every cached metadata entry for one table (columns, indexes, ddl,
     * primary key, FK constraints). Call after any DDL that changes it.
     */
    public function forgetTable(Connection $connection, string $database, string $table): void
    {
        foreach (['columns', 'indexes', 'ddl', 'primaryKey', 'foreignKeyConstraints'] as $kind) {
            Cache::forget($this->key($connection, $database, $table, $kind));
        }
    }

    /**
     * Bust the cached column autocomplete map for a database. tables() isn't
     * cached (see its docblock), so there's nothing to bust there.
     */
    public function forgetDatabase(Connection $connection, string $database): void
    {
        Cache::forget($this->key($connection, $database, null, 'allColumns'));
        Cache::forget($this->key($connection, $database, null, 'tableNames'));
    }

    /**
     * Quote a MySQL identifier with backticks.
     */
    public function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function key(Connection $connection, string $database, ?string $table, string $kind): string
    {
        return "schema.$kind.{$connection->id}.$database".($table !== null ? ".$table" : '');
    }
}
