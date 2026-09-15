<?php

namespace App\Services;

/**
 * Turn MySQL EXPLAIN FORMAT=JSON (or tabular EXPLAIN rows) into a compact
 * tree the UI can render without pulling in a chart library.
 */
class ExplainVisualizer
{
    /**
     * @param  array<int, object|array<string, mixed>>  $rows
     * @return array{mode: 'tree'|'table', nodes?: array<int, array{id: string, label: string, detail: string, children: array}>, columns?: string[], rows?: array<int, array<string, mixed>>}
     */
    public function visualize(array $rows): array
    {
        if ($rows === []) {
            return ['mode' => 'table', 'columns' => [], 'rows' => []];
        }

        $first = (array) $rows[0];
        $pgPlan = $this->postgresPlanRoot($first);

        if ($pgPlan !== null) {
            return [
                'mode' => 'tree',
                'nodes' => [$this->walkPostgresPlan($pgPlan, 'root')],
            ];
        }

        $json = $first['EXPLAIN'] ?? $first['explain'] ?? null;

        if (is_string($json) && str_starts_with(ltrim($json), '{')) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $queryBlock = $decoded['query_block'] ?? $decoded;

                return [
                    'mode' => 'tree',
                    'nodes' => [$this->walk($queryBlock, 'root')],
                ];
            }
        }

        $columns = array_keys($first);
        $normalized = array_map(function ($row) {
            $data = (array) $row;
            foreach ($data as $key => $value) {
                if (is_object($value) || is_array($value)) {
                    $data[$key] = json_encode($value);
                }
            }

            return $data;
        }, $rows);

        return [
            'mode' => 'table',
            'columns' => $columns,
            'rows' => $normalized,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{id: string, label: string, detail: string, children: array}
     */
    private function walk(array $node, string $id): array
    {
        $label = 'operation';
        if (isset($node['table_name'])) {
            $label = (string) $node['table_name'];
        } elseif (isset($node['nested_loop'])) {
            $label = 'nested_loop';
        } elseif (isset($node['ordering_operation'])) {
            $label = 'ordering';
        } elseif (isset($node['grouping_operation'])) {
            $label = 'grouping';
        } elseif (isset($node['select_id'])) {
            $label = 'select #'.$node['select_id'];
        }

        $parts = [];
        foreach (['access_type', 'key', 'rows_examined_per_scan', 'rows_produced_per_join', 'filtered', 'using_index', 'using_temporary_table', 'using_filesort'] as $field) {
            if (array_key_exists($field, $node) && ! is_array($node[$field])) {
                $parts[] = $field.'='.(is_bool($node[$field]) ? ($node[$field] ? 'true' : 'false') : $node[$field]);
            }
        }

        $children = [];
        $childIndex = 0;
        foreach ($node as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            if ($key === 'nested_loop' && array_is_list($value)) {
                foreach ($value as $item) {
                    if (is_array($item)) {
                        $inner = $item['table'] ?? $item;
                        if (is_array($inner)) {
                            $children[] = $this->walk($inner, $id.'.'.$childIndex++);
                        }
                    }
                }
                continue;
            }

            if (in_array($key, ['table', 'ordering_operation', 'grouping_operation', 'duplicates_removal', 'windowing', 'buffer_result'], true)) {
                $children[] = $this->walk($value, $id.'.'.$childIndex++);
            }
        }

        return [
            'id' => $id,
            'label' => (string) $label,
            'detail' => implode(', ', $parts),
            'children' => $children,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function postgresPlanRoot(array $row): ?array
    {
        foreach ($row as $key => $value) {
            if (strtolower((string) $key) !== 'query plan') {
                continue;
            }

            $decoded = is_string($value) ? json_decode($value, true) : $value;

            if (! is_array($decoded)) {
                continue;
            }

            if (isset($decoded[0]['Plan']) && is_array($decoded[0]['Plan'])) {
                return $decoded[0]['Plan'];
            }

            if (isset($decoded['Plan']) && is_array($decoded['Plan'])) {
                return $decoded['Plan'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{id: string, label: string, detail: string, children: array}
     */
    private function walkPostgresPlan(array $node, string $id): array
    {
        $label = (string) ($node['Node Type'] ?? 'plan');
        if (! empty($node['Relation Name'])) {
            $label .= ' on '.$node['Relation Name'];
        }

        $parts = [];
        foreach (['Startup Cost', 'Total Cost', 'Plan Rows', 'Plan Width', 'Actual Rows', 'Actual Loops', 'Filter', 'Index Name'] as $field) {
            if (array_key_exists($field, $node) && ! is_array($node[$field])) {
                $parts[] = $field.'='.$node[$field];
            }
        }

        $children = [];
        foreach ($node['Plans'] ?? [] as $index => $child) {
            if (is_array($child)) {
                $children[] = $this->walkPostgresPlan($child, $id.'.'.$index);
            }
        }

        return [
            'id' => $id,
            'label' => $label,
            'detail' => implode(', ', $parts),
            'children' => $children,
        ];
    }
}
