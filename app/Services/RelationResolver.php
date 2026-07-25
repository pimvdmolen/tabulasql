<?php

namespace App\Services;

use App\Models\Connection;

/**
 * Detects foreign key relations, real constraints plus Laravel-style
 * `xxx_id` naming conventions, and fetches related records.
 */
class RelationResolver
{
    /** @var array<string, array> */
    private array $cache = [];

    public function __construct(
        private ConnectionManager $manager,
        private SchemaExplorer $explorer,
    ) {}

    /**
     * FK map for a table: column name => relation info.
     *
     * @return array<string, array{database: string, table: string, column: string, convention: bool}>
     */
    public function foreignKeys(Connection $connection, string $database, string $table): array
    {
        $cacheKey = "{$connection->id}.$database.$table";

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $map = [];

        $rows = $this->manager->db($connection)->select(
            'SELECT COLUMN_NAME AS col, REFERENCED_TABLE_SCHEMA AS ref_db,
                    REFERENCED_TABLE_NAME AS ref_table, REFERENCED_COLUMN_NAME AS ref_col
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$database, $table]
        );

        foreach ($rows as $row) {
            $map[$row->col] = [
                'database' => $row->ref_db,
                'table' => $row->ref_table,
                'column' => $row->ref_col,
                'convention' => false,
            ];
        }

        // Convention detection: xxx_id -> table `xxxs` or `xxx` with an `id`
        // column, in the same database.
        $tables = array_column($this->explorer->tables($connection, $database), 'name');
        $tableSet = array_flip($tables);

        foreach ($this->explorer->columns($connection, $database, $table) as $column) {
            $name = $column['name'];

            if (isset($map[$name]) || ! str_ends_with($name, '_id')) {
                continue;
            }

            $base = substr($name, 0, -3);

            foreach ([$base.'s', $base, $base.'es'] as $candidate) {
                // A column literally named after its own table (e.g.
                // `google_review_id` on `google_reviews`) is almost always
                // an external ID of this row, not a reference to another
                // one; skip it so it doesn't falsely match itself. Real
                // self-referencing hierarchies (a `parent_id` FK constraint
                // back to the same table) are found via information_schema
                // above, before this convention loop runs, so they're
                // unaffected.
                if ($candidate === $table) {
                    continue;
                }

                if (isset($tableSet[$candidate])) {
                    $map[$name] = [
                        'database' => $database,
                        'table' => $candidate,
                        'column' => 'id',
                        'convention' => true,
                    ];
                    break;
                }
            }
        }

        return $this->cache[$cacheKey] = $map;
    }

    /**
     * Fetch the related record for an FK value. Values are formatted for
     * display with QueryRunner::formatValue.
     *
     * @return ?array<string, mixed>
     */
    public function related(Connection $connection, array $relation, mixed $value): ?array
    {
        $quotedTarget = $this->explorer->quote($relation['database']).'.'.$this->explorer->quote($relation['table']);

        $row = $this->manager->db($connection)->selectOne(
            sprintf('SELECT * FROM %s WHERE %s = ? LIMIT 1', $quotedTarget, $this->explorer->quote($relation['column'])),
            [$value]
        );

        if ($row === null) {
            return null;
        }

        $runner = app(QueryRunner::class);

        return array_map(fn ($column) => $runner->formatValue($column), (array) $row);
    }
}
