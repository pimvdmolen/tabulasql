<?php

namespace App\Services;

use App\Models\Connection;

/**
 * Schema reads for PostgreSQL and SQLite. Kept separate so the MySQL path in
 * SchemaExplorer stays a straight line with no extra branches on hot calls
 * beyond a single driverName() check.
 */
class AltSchemaExplorer
{
    public function __construct(private ConnectionManager $manager) {}

    /**
     * @return string[]
     */
    public function databases(Connection $connection): array
    {
        if ($connection->database !== null) {
            return [$connection->database];
        }

        if ($connection->driverName() === 'sqlite') {
            $path = $connection->host !== '' ? $connection->host : 'main';

            return [basename($path) ?: 'main'];
        }

        return array_column(
            $this->manager->db($connection)->select(
                'SELECT datname AS name FROM pg_database WHERE datistemplate = false ORDER BY datname'
            ),
            'name'
        );
    }

    /**
     * @return array<int, array{name: string, type: string, engine: ?string, rows: ?int, size: ?int}>
     */
    public function tables(Connection $connection, string $database): array
    {
        if ($connection->driverName() === 'sqlite') {
            $rows = $this->manager->db($connection)->select(
                "SELECT name, type FROM sqlite_master WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%' ORDER BY name"
            );

            return array_map(fn ($row) => [
                'name' => $row->name,
                'type' => $row->type === 'view' ? 'view' : 'table',
                'engine' => 'sqlite',
                'rows' => null,
                'size' => null,
            ], $rows);
        }

        $rows = $this->manager->db($connection, $database)->select(
            "SELECT c.relname AS name,
                    CASE c.relkind WHEN 'v' THEN 'view' WHEN 'm' THEN 'view' ELSE 'table' END AS type
             FROM pg_class c
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = 'public' AND c.relkind IN ('r','p','v','m')
             ORDER BY c.relname"
        );

        return array_map(fn ($row) => [
            'name' => $row->name,
            'type' => $row->type,
            'engine' => 'pgsql',
            'rows' => null,
            'size' => null,
        ], $rows);
    }

    /**
     * @return string[]
     */
    public function tableNames(Connection $connection, string $database): array
    {
        return array_column($this->tables($connection, $database), 'name');
    }

    /**
     * @return array<int, array{name: string, type: string, nullable: bool, key: string, default: ?string, extra: string, comment: string}>
     */
    public function columns(Connection $connection, string $database, string $table): array
    {
        if ($connection->driverName() === 'sqlite') {
            $rows = $this->manager->db($connection)->select('PRAGMA table_info('.$this->quote($connection, $table).')');

            return array_map(fn ($row) => [
                'name' => $row->name,
                'type' => $row->type ?: 'TEXT',
                'nullable' => ! ((int) $row->notnull),
                'key' => ((int) $row->pk) > 0 ? 'PRI' : '',
                'default' => $row->dflt_value,
                'extra' => '',
                'comment' => '',
            ], $rows);
        }

        $rows = $this->manager->db($connection, $database)->select(
            'SELECT a.attname AS name,
                    pg_catalog.format_type(a.atttypid, a.atttypmod) AS type,
                    NOT a.attnotnull AS nullable,
                    pg_get_expr(ad.adbin, ad.adrelid) AS def
             FROM pg_attribute a
             JOIN pg_class c ON c.oid = a.attrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             LEFT JOIN pg_attrdef ad ON ad.adrelid = a.attrelid AND ad.adnum = a.attnum
             WHERE n.nspname = \'public\' AND c.relname = ? AND a.attnum > 0 AND NOT a.attisdropped
             ORDER BY a.attnum',
            [$table]
        );

        $pk = $this->primaryKey($connection, $database, $table);

        return array_map(fn ($row) => [
            'name' => $row->name,
            'type' => $row->type,
            'nullable' => (bool) $row->nullable,
            'key' => in_array($row->name, $pk, true) ? 'PRI' : '',
            'default' => $row->def,
            'extra' => '',
            'comment' => '',
        ], $rows);
    }

    /**
     * @return array<string, string[]>
     */
    public function allColumns(Connection $connection, string $database): array
    {
        $map = [];
        foreach ($this->tableNames($connection, $database) as $table) {
            $map[$table] = array_column($this->columns($connection, $database, $table), 'name');
        }

        return $map;
    }

    /**
     * @return string[]
     */
    public function primaryKey(Connection $connection, string $database, string $table): array
    {
        if ($connection->driverName() === 'sqlite') {
            return array_values(array_map(
                fn ($row) => $row->name,
                array_filter(
                    $this->manager->db($connection)->select('PRAGMA table_info('.$this->quote($connection, $table).')'),
                    fn ($row) => ((int) $row->pk) > 0
                )
            ));
        }

        $rows = $this->manager->db($connection, $database)->select(
            'SELECT a.attname AS name
             FROM pg_index i
             JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
             JOIN pg_class c ON c.oid = i.indrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE i.indisprimary AND n.nspname = \'public\' AND c.relname = ?
             ORDER BY a.attnum',
            [$table]
        );

        return array_column($rows, 'name');
    }

    /**
     * @return array<int, array{name: string, columns: string[], unique: bool, type: string}>
     */
    public function indexes(Connection $connection, string $database, string $table): array
    {
        if ($connection->driverName() === 'sqlite') {
            $list = $this->manager->db($connection)->select('PRAGMA index_list('.$this->quote($connection, $table).')');
            $indexes = [];
            foreach ($list as $index) {
                $cols = $this->manager->db($connection)->select('PRAGMA index_info('.$this->quote($connection, $index->name).')');
                $indexes[] = [
                    'name' => $index->name,
                    'columns' => array_column($cols, 'name'),
                    'unique' => (bool) $index->unique,
                    'type' => 'BTREE',
                ];
            }

            return $indexes;
        }

        $rows = $this->manager->db($connection, $database)->select(
            'SELECT i.relname AS name, a.attname AS col, ix.indisunique AS is_unique
             FROM pg_index ix
             JOIN pg_class t ON t.oid = ix.indrelid
             JOIN pg_class i ON i.oid = ix.indexrelid
             JOIN pg_namespace n ON n.oid = t.relnamespace
             JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
             WHERE n.nspname = \'public\' AND t.relname = ?
             ORDER BY i.relname, a.attnum',
            [$table]
        );

        $indexes = [];
        foreach ($rows as $row) {
            $indexes[$row->name] ??= [
                'name' => $row->name,
                'columns' => [],
                'unique' => (bool) $row->is_unique,
                'type' => 'BTREE',
            ];
            $indexes[$row->name]['columns'][] = $row->col;
        }

        return array_values($indexes);
    }

    public function ddl(Connection $connection, string $database, string $table): string
    {
        if ($connection->driverName() === 'sqlite') {
            $row = $this->manager->db($connection)->selectOne(
                'SELECT sql FROM sqlite_master WHERE name = ? LIMIT 1',
                [$table]
            );

            return $row->sql ?? '';
        }

        return '-- PostgreSQL DDL preview is limited in Tabula; use pg_dump for full DDL.';
    }

    /**
     * @return array<int, array{name: string, column: string, ref_table: string, ref_column: string, on_update: string, on_delete: string}>
     */
    public function foreignKeyConstraints(Connection $connection, string $database, string $table): array
    {
        if ($connection->driverName() === 'sqlite') {
            $rows = $this->manager->db($connection)->select('PRAGMA foreign_key_list('.$this->quote($connection, $table).')');

            return array_map(fn ($row) => [
                'name' => 'fk_'.$row->id,
                'column' => $row->from,
                'ref_table' => $row->table,
                'ref_column' => $row->to,
                'on_update' => $row->on_update,
                'on_delete' => $row->on_delete,
            ], $rows);
        }

        $rows = $this->manager->db($connection, $database)->select(
            'SELECT con.conname AS name,
                    att.attname AS col,
                    ref.relname AS ref_table,
                    ratt.attname AS ref_col,
                    con.confupdtype AS on_update,
                    con.confdeltype AS on_delete
             FROM pg_constraint con
             JOIN pg_class rel ON rel.oid = con.conrelid
             JOIN pg_namespace nsp ON nsp.oid = rel.relnamespace
             JOIN pg_attribute att ON att.attrelid = con.conrelid AND att.attnum = ANY(con.conkey)
             JOIN pg_class ref ON ref.oid = con.confrelid
             JOIN pg_attribute ratt ON ratt.attrelid = con.confrelid AND ratt.attnum = ANY(con.confkey)
             WHERE con.contype = \'f\' AND nsp.nspname = \'public\' AND rel.relname = ?',
            [$table]
        );

        $map = ['a' => 'NO ACTION', 'r' => 'RESTRICT', 'c' => 'CASCADE', 'n' => 'SET NULL', 'd' => 'SET DEFAULT'];

        return array_map(fn ($row) => [
            'name' => $row->name,
            'column' => $row->col,
            'ref_table' => $row->ref_table,
            'ref_column' => $row->ref_col,
            'on_update' => $map[$row->on_update] ?? $row->on_update,
            'on_delete' => $map[$row->on_delete] ?? $row->on_delete,
        ], $rows);
    }

    public function quote(Connection $connection, string $identifier): string
    {
        if ($connection->driverName() === 'mysql') {
            return '`'.str_replace('`', '``', $identifier).'`';
        }

        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
