<?php

namespace App\Services;

use App\Models\Connection;
use RuntimeException;

/**
 * Generates safe UPDATE/INSERT/DELETE statements based on primary keys and
 * changed fields. All values go through bindings; identifiers are quoted.
 * The sentinel self::DEFAULT makes a column use its DEFAULT value.
 */
class DataEditor
{
    public const DEFAULT = "\0__DEFAULT__\0";

    public function __construct(
        private ConnectionManager $manager,
        private SchemaExplorer $explorer,
    ) {}

    /**
     * @param  array<string, mixed>  $primaryKey  column => value
     * @param  array<string, mixed>  $changes  column => new value
     */
    public function update(Connection $connection, string $database, string $table, array $primaryKey, array $changes): int
    {
        $this->assertKey($primaryKey);

        if ($changes === []) {
            return 0;
        }

        $sets = [];
        $bindings = [];

        foreach ($changes as $column => $value) {
            if ($value === self::DEFAULT) {
                $sets[] = $this->quote($column).' = DEFAULT';
            } else {
                $sets[] = $this->quote($column).' = ?';
                $bindings[] = $value;
            }
        }

        [$whereClause, $whereBindings] = $this->keyWhere($primaryKey);

        $sql = sprintf(
            'UPDATE %s.%s SET %s WHERE %s LIMIT 1',
            $this->quote($database), $this->quote($table),
            implode(', ', $sets), $whereClause
        );

        return $this->manager->db($connection)->update($sql, [...$bindings, ...$whereBindings]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function insert(Connection $connection, string $database, string $table, array $values): int
    {
        $values = array_filter($values, fn ($value) => $value !== self::DEFAULT);

        if ($values === []) {
            throw new RuntimeException('Nothing to insert.');
        }

        $columns = implode(', ', array_map($this->quote(...), array_keys($values)));
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        $sql = sprintf(
            'INSERT INTO %s.%s (%s) VALUES (%s)',
            $this->quote($database), $this->quote($table), $columns, $placeholders
        );

        return $this->manager->db($connection)->affectingStatement($sql, array_values($values));
    }

    /**
     * @param  array<int, array<string, mixed>>  $primaryKeys  list of column => value maps
     */
    public function delete(Connection $connection, string $database, string $table, array $primaryKeys): int
    {
        $deleted = 0;

        foreach ($primaryKeys as $primaryKey) {
            $this->assertKey($primaryKey);
            [$whereClause, $bindings] = $this->keyWhere($primaryKey);

            $sql = sprintf(
                'DELETE FROM %s.%s WHERE %s LIMIT 1',
                $this->quote($database), $this->quote($table), $whereClause
            );

            $deleted += $this->manager->db($connection)->delete($sql, $bindings);
        }

        return $deleted;
    }

    /**
     * Duplicate a row, letting auto-increment columns regenerate.
     */
    public function duplicate(Connection $connection, string $database, string $table, array $primaryKey): int
    {
        $this->assertKey($primaryKey);
        [$whereClause, $bindings] = $this->keyWhere($primaryKey);

        $row = $this->manager->db($connection)->selectOne(sprintf(
            'SELECT * FROM %s.%s WHERE %s LIMIT 1',
            $this->quote($database), $this->quote($table), $whereClause
        ), $bindings);

        if ($row === null) {
            throw new RuntimeException('The row to duplicate no longer exists.');
        }

        $values = (array) $row;

        foreach ($this->explorer->columns($connection, $database, $table) as $column) {
            if (str_contains($column['extra'], 'auto_increment')) {
                unset($values[$column['name']]);
            }
        }

        return $this->insert($connection, $database, $table, $values);
    }

    private function assertKey(array $primaryKey): void
    {
        if ($primaryKey === []) {
            throw new RuntimeException('This table has no primary key. Editing is disabled.');
        }
    }

    /**
     * @return array{0: string, 1: array}
     */
    private function keyWhere(array $primaryKey): array
    {
        $clauses = [];
        $bindings = [];

        foreach ($primaryKey as $column => $value) {
            if ($value === null) {
                $clauses[] = $this->quote($column).' IS NULL';
            } else {
                $clauses[] = $this->quote($column).' = ?';
                $bindings[] = $value;
            }
        }

        return [implode(' AND ', $clauses), $bindings];
    }

    private function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
