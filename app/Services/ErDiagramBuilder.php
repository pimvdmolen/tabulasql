<?php

namespace App\Services;

use App\Models\Connection;

/**
 * Lightweight ER graph for a database: tables as nodes, FK edges between them.
 * No external graph library; the UI lays nodes out in a simple grid.
 */
class ErDiagramBuilder
{
    public function __construct(private SchemaExplorer $explorer) {}

    /**
     * @return array{
     *     tables: array<int, array{name: string, columns: array<int, array{name: string, key: string}}>},
     *     edges: array<int, array{from: string, to: string, label: string}>
     * }
     */
    public function build(Connection $connection, string $database, int $columnLimit = 12): array
    {
        $tables = [];
        $edges = [];

        foreach ($this->explorer->tables($connection, $database) as $tableMeta) {
            if (($tableMeta['type'] ?? 'table') === 'view') {
                continue;
            }

            $name = $tableMeta['name'];
            $columns = array_map(
                fn (array $col) => ['name' => $col['name'], 'key' => $col['key'] ?? ''],
                array_slice($this->explorer->columns($connection, $database, $name), 0, $columnLimit)
            );

            $tables[] = ['name' => $name, 'columns' => $columns];

            foreach ($this->explorer->foreignKeyConstraints($connection, $database, $name) as $fk) {
                $edges[] = [
                    'from' => $name,
                    'to' => $fk['ref_table'],
                    'label' => $fk['column'].' → '.$fk['ref_column'],
                ];
            }
        }

        usort($tables, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return ['tables' => $tables, 'edges' => $edges];
    }
}
