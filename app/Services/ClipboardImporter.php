<?php

namespace App\Services;

/**
 * Parse clipboard text (CSV / TSV / JSON) into column-aligned row arrays
 * for inserting into the current table grid.
 */
class ClipboardImporter
{
    /**
     * @param  string[]  $columns
     * @return array{ok: bool, rows?: array<int, array<string, ?string>>, error?: string, detected?: string}
     */
    public function parse(string $text, array $columns): array
    {
        $text = trim($text);

        if ($text === '' || $columns === []) {
            return ['ok' => false, 'error' => 'Nothing to paste, or the table has no columns.'];
        }

        if (str_starts_with($text, '[') || str_starts_with($text, '{')) {
            $json = json_decode($text, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $this->fromJson($json, $columns);
            }
        }

        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
        $lines = array_values(array_filter($lines, fn ($line) => trim($line) !== ''));

        if ($lines === []) {
            return ['ok' => false, 'error' => 'Clipboard is empty.'];
        }

        $delimiter = substr_count($lines[0], "\t") >= substr_count($lines[0], ',') ? "\t" : ',';
        $matrix = array_map(fn ($line) => str_getcsv($line, $delimiter), $lines);

        return $this->fromMatrix($matrix, $columns, $delimiter === "\t" ? 'tsv' : 'csv');
    }

    /**
     * @param  mixed  $json
     * @param  string[]  $columns
     * @return array{ok: bool, rows?: array<int, array<string, ?string>>, error?: string, detected?: string}
     */
    private function fromJson(mixed $json, array $columns): array
    {
        if (isset($json[0]) && is_array($json[0])) {
            $rows = [];
            foreach ($json as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $row = [];
                foreach ($columns as $column) {
                    $row[$column] = array_key_exists($column, $item)
                        ? $this->stringify($item[$column])
                        : null;
                }
                $rows[] = $row;
            }

            return $rows === []
                ? ['ok' => false, 'error' => 'JSON array had no objects.']
                : ['ok' => true, 'rows' => $rows, 'detected' => 'json'];
        }

        if (is_array($json) && ! array_is_list($json)) {
            $row = [];
            foreach ($columns as $column) {
                $row[$column] = array_key_exists($column, $json)
                    ? $this->stringify($json[$column])
                    : null;
            }

            return ['ok' => true, 'rows' => [$row], 'detected' => 'json'];
        }

        return ['ok' => false, 'error' => 'JSON must be an object or array of objects.'];
    }

    /**
     * @param  array<int, array<int, string|null>>  $matrix
     * @param  string[]  $columns
     * @return array{ok: bool, rows?: array<int, array<string, ?string>>, error?: string, detected?: string}
     */
    private function fromMatrix(array $matrix, array $columns, string $detected): array
    {
        $header = $matrix[0];
        $hasHeader = count(array_intersect($header, $columns)) >= max(1, (int) floor(count($columns) / 2));

        if ($hasHeader) {
            $map = [];
            foreach ($header as $index => $name) {
                if (in_array($name, $columns, true)) {
                    $map[$index] = $name;
                }
            }
            $dataRows = array_slice($matrix, 1);
        } else {
            $map = [];
            foreach (array_values($columns) as $index => $name) {
                $map[$index] = $name;
            }
            $dataRows = $matrix;
        }

        $rows = [];
        foreach ($dataRows as $dataRow) {
            $row = array_fill_keys($columns, null);
            foreach ($map as $index => $name) {
                $value = $dataRow[$index] ?? null;
                $row[$name] = $value === '' ? null : $value;
            }
            $rows[] = $row;
        }

        return $rows === []
            ? ['ok' => false, 'error' => 'No data rows found.']
            : ['ok' => true, 'rows' => $rows, 'detected' => $detected];
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }
}
