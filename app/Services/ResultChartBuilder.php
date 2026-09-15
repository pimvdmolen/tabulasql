<?php

namespace App\Services;

/**
 * Derive a simple bar-chart series from a query/table result without a chart lib.
 * Picks the first non-numeric column as labels and the first numeric column as values.
 */
class ResultChartBuilder
{
    /**
     * @param  string[]  $columns
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{ok: bool, label_column?: string, value_column?: string, points?: array<int, array{label: string, value: float}>, error?: string}
     */
    public function build(array $columns, array $rows, int $maxPoints = 40): array
    {
        if ($columns === [] || $rows === []) {
            return ['ok' => false, 'error' => 'No rows to chart.'];
        }

        $labelCol = null;
        $valueCol = null;

        foreach ($columns as $column) {
            $sample = $this->scalar($rows[0][$column] ?? null);
            if ($labelCol === null && ! $this->isNumeric($sample)) {
                $labelCol = $column;
            }
            if ($valueCol === null && $this->isNumeric($sample)) {
                $valueCol = $column;
            }
        }

        if ($valueCol === null) {
            foreach ($columns as $column) {
                foreach ($rows as $row) {
                    if ($this->isNumeric($this->scalar($row[$column] ?? null))) {
                        $valueCol = $column;
                        break 2;
                    }
                }
            }
        }

        if ($valueCol === null) {
            return ['ok' => false, 'error' => 'No numeric column found for a chart.'];
        }

        if ($labelCol === null) {
            $labelCol = $columns[0] === $valueCol
                ? ($columns[1] ?? $columns[0])
                : $columns[0];
        }

        $points = [];
        foreach (array_slice($rows, 0, $maxPoints) as $index => $row) {
            $raw = $this->scalar($row[$valueCol] ?? null);
            if (! $this->isNumeric($raw)) {
                continue;
            }
            $label = $this->scalar($row[$labelCol] ?? null);
            $points[] = [
                'label' => $label === null || $label === '' ? '#'.($index + 1) : mb_substr((string) $label, 0, 48),
                'value' => (float) $raw,
            ];
        }

        return $points === []
            ? ['ok' => false, 'error' => 'No numeric values to chart.']
            : ['ok' => true, 'label_column' => $labelCol, 'value_column' => $valueCol, 'points' => $points];
    }

    private function scalar(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value['preview'] ?? $value['full'] ?? null;
        }

        return $value;
    }

    private function isNumeric(mixed $value): bool
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return false;
        }

        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value));
    }
}
