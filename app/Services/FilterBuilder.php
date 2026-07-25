<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Translates grid filter rules into a safe WHERE clause with bindings.
 * Rules: ['column' => ..., 'operator' => ..., 'value' => ?, 'value2' => ?]
 */
class FilterBuilder
{
    public const OPERATORS = [
        '=', '<>', '>', '<', '>=', '<=',
        'LIKE', 'NOT LIKE', 'IS NULL', 'IS NOT NULL', 'IN', 'BETWEEN',
    ];

    /**
     * @return array{where: string, bindings: array}
     */
    public function build(array $rules): array
    {
        $clauses = [];
        $bindings = [];

        foreach ($rules as $rule) {
            $column = $rule['column'] ?? '';
            $operator = strtoupper(trim($rule['operator'] ?? ''));

            if ($column === '' || ! in_array($operator, self::OPERATORS, true)) {
                throw new InvalidArgumentException("Invalid filter rule on '$column' ($operator).");
            }

            $quoted = '`'.str_replace('`', '``', $column).'`';

            switch ($operator) {
                case 'IS NULL':
                case 'IS NOT NULL':
                    $clauses[] = "$quoted $operator";
                    break;

                case 'IN':
                    $values = array_map('trim', explode(',', (string) ($rule['value'] ?? '')));
                    $values = array_values(array_filter($values, fn ($value) => $value !== ''));

                    if ($values === []) {
                        throw new InvalidArgumentException('IN filter needs at least one value.');
                    }

                    $clauses[] = "$quoted IN (".implode(', ', array_fill(0, count($values), '?')).')';
                    $bindings = [...$bindings, ...$values];
                    break;

                case 'BETWEEN':
                    $clauses[] = "$quoted BETWEEN ? AND ?";
                    $bindings[] = $rule['value'] ?? '';
                    $bindings[] = $rule['value2'] ?? '';
                    break;

                default:
                    $clauses[] = "$quoted $operator ?";
                    $bindings[] = $rule['value'] ?? '';
            }
        }

        return [
            'where' => implode(' AND ', $clauses),
            'bindings' => $bindings,
        ];
    }

    /**
     * Human-readable label for a filter chip.
     */
    public function describe(array $rule): string
    {
        $operator = strtoupper($rule['operator'] ?? '');

        return match ($operator) {
            'IS NULL', 'IS NOT NULL' => "{$rule['column']} $operator",
            'BETWEEN' => "{$rule['column']} BETWEEN {$rule['value']} AND {$rule['value2']}",
            default => "{$rule['column']} $operator {$rule['value']}",
        };
    }
}
