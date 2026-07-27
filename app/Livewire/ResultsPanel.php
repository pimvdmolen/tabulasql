<?php

namespace App\Livewire;

use App\Models\Connection;
use App\Models\Setting;
use App\Services\DataEditor;
use App\Services\FilterBuilder;
use App\Services\QueryRunner;
use App\Services\RelationResolver;
use App\Services\SchemaExplorer;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

class ResultsPanel extends Component
{
    #[Locked]
    public int $connectionId;

    public string $activeTab = 'data';

    /** @var string[] Ordered result chrome tabs. */
    public array $tabOrder = ['data', 'info', 'messages'];

    /** 'table' = browsing a table, 'query' = showing an editor resultset. */
    public string $mode = 'table';

    public ?string $queryResultKey = null;

    /** @var array<int, array{time: string, type: string, text: string}> */
    public array $messages = [];

    public ?string $database = null;

    public ?string $table = null;

    // Grid paging/sorting state.
    public bool $limitRows = true;

    public int $firstRow = 0;

    public int $rowCount = 500;

    public ?string $sortColumn = null;

    public string $sortDirection = 'asc';

    /** Bumped to invalidate the cached grid/info fetch. */
    public int $version = 0;

    // Filters.
    /** @var array<int, array{column: string, operator: string, value: ?string, value2: ?string}> */
    public array $filters = [];

    public bool $showFilterDialog = false;

    public array $draftFilters = [];

    public bool $showSqlPreview = false;

    // Inline editing.
    /** @var array<int|string, array<string, mixed>> rowIndex => column => value */
    public array $pendingEdits = [];

    /** @var ?array{row: int, col: string} */
    public ?array $editingCell = null;

    /** @var int[]|string[] */
    public array $selectedRows = [];

    public bool $showInsertDialog = false;

    public array $insertValues = [];

    public bool $confirmingDelete = false;

    public bool $safeMode = false;

    /** @var ?array{action: string, title: string, sql: string} */
    public ?array $pendingSafeAction = null;

    // FK drill-down dialog: a stack of records for nested navigation.
    /** @var array<int, array{database: string, table: string, row: array, relation: ?string, convention: bool}> */
    public array $recordStack = [];

    public function mount(): void
    {
        $this->tabOrder = $this->normalizeTabOrder(Setting::get('result_tab_order', ['data', 'info', 'messages']));
        $this->activeTab = $this->tabOrder[0] ?? 'data';
        $this->safeMode = (bool) Setting::get('safe_mode', false);
        $this->log('info', 'Session started.');
    }

    #[On('safe-mode-changed')]
    public function onSafeModeChanged(bool $enabled): void
    {
        $this->safeMode = $enabled;
    }

    /**
     * @param  mixed  $order
     * @return string[]
     */
    private function normalizeTabOrder(mixed $order): array
    {
        $defaults = ['data', 'info', 'messages'];
        $labels = array_flip($defaults);

        if (! is_array($order)) {
            return $defaults;
        }

        $clean = array_values(array_filter($order, fn ($key) => isset($labels[$key])));

        foreach ($defaults as $key) {
            if (! in_array($key, $clean, true)) {
                $clean[] = $key;
            }
        }

        return $clean;
    }

    public function moveTab(string $key, string $direction): void
    {
        $index = array_search($key, $this->tabOrder, true);

        if ($index === false) {
            return;
        }

        $swapWith = $direction === 'left' ? $index - 1 : $index + 1;

        if ($swapWith < 0 || $swapWith >= count($this->tabOrder)) {
            return;
        }

        [$this->tabOrder[$index], $this->tabOrder[$swapWith]] = [$this->tabOrder[$swapWith], $this->tabOrder[$index]];
        Setting::set('result_tab_order', $this->tabOrder);
    }

    public function selectAllRows(): void
    {
        $result = $this->gridResult;

        if ($result === null || ! ($result['ok'] ?? false) || ! $this->isEditable()) {
            return;
        }

        // String keys so they match wire:model checkbox values.
        $this->selectedRows = array_map(
            static fn ($index) => (string) $index,
            array_keys($result['rows'] ?? [])
        );
    }

    public function clearRowSelection(): void
    {
        $this->selectedRows = [];
    }

    public function toggleSelectAllRows(): void
    {
        $result = $this->gridResult;
        $rowCount = count($result['rows'] ?? []);

        if ($rowCount > 0 && count($this->selectedRows) === $rowCount) {
            $this->clearRowSelection();

            return;
        }

        $this->selectAllRows();
    }

    public function toggleRowSelection(int $row, bool $shift = false): void
    {
        if (! $this->isEditable()) {
            return;
        }

        $rowKey = (string) $row;

        if ($shift && $this->selectedRows !== []) {
            $anchor = (int) end($this->selectedRows);
            $from = min($anchor, $row);
            $to = max($anchor, $row);
            $range = array_map(static fn ($index) => (string) $index, range($from, $to));
            $this->selectedRows = array_values(array_unique([...$this->selectedRows, ...$range]));

            return;
        }

        if (in_array($rowKey, $this->selectedRows, true) || in_array($row, $this->selectedRows, false)) {
            $this->selectedRows = array_values(array_filter(
                $this->selectedRows,
                static fn ($value) => (string) $value !== $rowKey
            ));
        } else {
            $this->selectedRows[] = $rowKey;
        }
    }

    // ------------------------------------------------------------------
    // Navigation

    #[On('table-selected')]
    public function showTable(int $connectionId, string $database, string $table, array $filters = []): void
    {
        if ($connectionId !== $this->connectionId) {
            return;
        }

        $this->database = $database;
        $this->table = $table;
        $this->firstRow = 0;
        $this->sortColumn = null;
        $this->filters = $filters;
        $this->mode = 'table';
        $this->activeTab = 'data';
        $this->resetEditing();
        $this->invalidateGrid();

        $this->log('info', "Opened table `$database`.`$table`.");
    }

    #[On('query-result')]
    public function showQueryResult(int $connectionId, string $key): void
    {
        if ($connectionId !== $this->connectionId) {
            return;
        }

        $this->mode = 'query';
        $this->queryResultKey = $key;
        $this->activeTab = 'data';
        $this->invalidateGrid();
    }

    #[On('log')]
    public function logFrom(int $connectionId, string $type, string $text): void
    {
        if ($connectionId === $this->connectionId) {
            $this->log($type, $text);
        }
    }

    public function refresh(): void
    {
        $this->resetEditing();

        if ($this->database !== null && $this->table !== null) {
            app(SchemaExplorer::class)->forgetTable($this->connection(), $this->database, $this->table);
        }

        $this->invalidateGrid();
    }

    /**
     * Bust the per-request computed cache. gridResult may already have been
     * evaluated earlier in the same request (e.g. by rawCell() before a
     * mutation ran), so every grid-affecting change must unset it.
     */
    private function invalidateGrid(): void
    {
        $this->version++;
        unset($this->gridResult, $this->tableInfo);
    }

    public function sortBy(string $column): void
    {
        if ($this->sortColumn === $column) {
            if ($this->sortDirection === 'asc') {
                $this->sortDirection = 'desc';
            } else {
                $this->sortColumn = null;
                $this->sortDirection = 'asc';
            }
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        }

        $this->resetEditing();
        $this->invalidateGrid();
    }

    public function unsort(): void
    {
        $this->sortColumn = null;
        $this->resetEditing();
        $this->invalidateGrid();
    }

    public function nextPage(): void
    {
        $this->firstRow += $this->rowCount;
        $this->resetEditing();
        $this->invalidateGrid();
    }

    public function previousPage(): void
    {
        $this->firstRow = max(0, $this->firstRow - $this->rowCount);
        $this->resetEditing();
        $this->invalidateGrid();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['limitRows', 'firstRow', 'rowCount'], true)) {
            $this->firstRow = max(0, $this->firstRow);
            $this->rowCount = max(1, min(100_000, $this->rowCount));
            $this->invalidateGrid();
        }
    }

    // ------------------------------------------------------------------
    // Filters

    public function openFilterDialog(): void
    {
        $this->draftFilters = $this->filters !== [] ? $this->filters : [
            ['column' => '', 'operator' => '=', 'value' => '', 'value2' => ''],
        ];
        $this->showFilterDialog = true;
    }

    public function addDraftRule(): void
    {
        $this->draftFilters[] = ['column' => '', 'operator' => '=', 'value' => '', 'value2' => ''];
    }

    public function removeDraftRule(int $index): void
    {
        unset($this->draftFilters[$index]);
        $this->draftFilters = array_values($this->draftFilters);
    }

    public function applyFilters(): void
    {
        $rules = array_values(array_filter($this->draftFilters, fn ($rule) => ($rule['column'] ?? '') !== ''));

        try {
            app(FilterBuilder::class)->build($rules);
        } catch (Throwable $e) {
            $this->log('error', $e->getMessage());

            return;
        }

        $this->filters = $rules;
        $this->showFilterDialog = false;
        $this->firstRow = 0;
        $this->resetEditing();
        $this->invalidateGrid();
    }

    public function removeFilter(int $index): void
    {
        unset($this->filters[$index]);
        $this->filters = array_values($this->filters);
        $this->invalidateGrid();
    }

    public function clearFilters(): void
    {
        $this->filters = [];
        $this->invalidateGrid();
    }

    /**
     * Quick filter from the cell context menu.
     */
    public function quickFilter(int $row, string $column, string $operator): void
    {
        $value = $this->rawCell($row, $column);

        $rule = $value === null && in_array($operator, ['=', '<>'], true)
            ? ['column' => $column, 'operator' => $operator === '=' ? 'IS NULL' : 'IS NOT NULL', 'value' => '', 'value2' => '']
            : match ($operator) {
                'LIKE' => ['column' => $column, 'operator' => 'LIKE', 'value' => '%'.$value.'%', 'value2' => ''],
                default => ['column' => $column, 'operator' => $operator, 'value' => (string) $value, 'value2' => ''],
            };

        $this->filters[] = $rule;
        $this->firstRow = 0;
        $this->resetEditing();
        $this->invalidateGrid();
    }

    #[Computed]
    public function filterChips(): array
    {
        $builder = app(FilterBuilder::class);

        return array_map(fn ($rule) => $builder->describe($rule), $this->filters);
    }

    #[Computed]
    public function draftSqlPreview(): string
    {
        $rules = array_values(array_filter($this->draftFilters, fn ($rule) => ($rule['column'] ?? '') !== ''));

        if ($rules === []) {
            return '';
        }

        try {
            $built = app(FilterBuilder::class)->build($rules);
        } catch (Throwable $e) {
            return '-- '.$e->getMessage();
        }

        $sql = $built['where'];

        foreach ($built['bindings'] as $binding) {
            $sql = preg_replace('/\?/', "'".addslashes((string) $binding)."'", $sql, 1);
        }

        return 'WHERE '.$sql;
    }

    // ------------------------------------------------------------------
    // Inline editing

    public function startEdit(int $row, string $column): void
    {
        if (! $this->isEditable()) {
            return;
        }

        $this->editingCell = ['row' => $row, 'col' => $column];
    }

    public function cancelEditCell(): void
    {
        $this->editingCell = null;
    }

    public function setCellValue(int $row, string $column, ?string $value): void
    {
        $this->editingCell = null;

        $current = $this->rawCell($row, $column);
        // Form inputs always submit a string (or '' for a blank/NULL cell),
        // so compare loosely: only a genuine change should create a pending
        // edit, and editing a value back to what it was should drop it.
        $unchanged = $value === (string) $current || ($current === null && $value === '');

        if ($unchanged) {
            unset($this->pendingEdits[$row][$column]);

            if (($this->pendingEdits[$row] ?? []) === []) {
                unset($this->pendingEdits[$row]);
            }

            return;
        }

        $this->pendingEdits[$row][$column] = $value;
    }

    public function setCellSpecial(int $row, string $column, string $kind): void
    {
        $this->pendingEdits[$row][$column] = match ($kind) {
            'null' => null,
            'empty' => '',
            default => DataEditor::DEFAULT,
        };
        $this->editingCell = null;
    }

    public function cancelChanges(): void
    {
        $this->resetEditing();
        $this->log('info', 'Pending changes discarded.');
    }

    public function saveChanges(): void
    {
        if ($this->pendingEdits === []) {
            return;
        }

        if ($this->safeMode && $this->pendingSafeAction === null) {
            $statements = [];

            foreach ($this->pendingEdits as $row => $changes) {
                $sets = [];
                foreach ($changes as $column => $value) {
                    $sets[] = '`'.$column.'` = '.($value === DataEditor::DEFAULT ? 'DEFAULT' : $this->sqlLiteral($value));
                }
                $pk = $this->primaryKeyValues((int) $row);
                $where = collect($pk)->map(fn ($v, $k) => '`'.$k.'` = '.$this->sqlLiteral($v))->implode(' AND ');
                $statements[] = 'UPDATE `'.$this->database.'`.`'.$this->table.'` SET '.implode(', ', $sets).' WHERE '.$where.' LIMIT 1;';
            }

            $this->pendingSafeAction = [
                'action' => 'save',
                'title' => 'Confirm save ('.count($this->pendingEdits).' row(s))',
                'sql' => implode("\n", $statements),
            ];

            return;
        }

        $this->pendingSafeAction = null;
        $editor = app(DataEditor::class);
        $saved = 0;
        $failed = 0;

        foreach ($this->pendingEdits as $row => $changes) {
            try {
                $editor->update(
                    $this->connection(), $this->database, $this->table,
                    $this->primaryKeyValues((int) $row), $changes
                );
                $saved++;
            } catch (Throwable $e) {
                $failed++;
                $this->log('error', 'Row '.((int) $row + 1).': '.$e->getMessage());
            }
        }

        $this->log($failed > 0 ? 'error' : 'success', "Saved $saved row(s)".($failed > 0 ? ", $failed failed" : '').'.');
        $this->resetEditing();
        $this->invalidateGrid();
    }

    public function openInsertDialog(): void
    {
        $this->showInsertDialog = true;
        $this->insertValues = [];
    }

    public function closeInsertDialog(): void
    {
        $this->showInsertDialog = false;
        $this->insertValues = [];
    }

    public function saveInsert(): void
    {
        $values = array_filter($this->insertValues, fn ($value) => $value !== null && $value !== '');

        if ($this->safeMode && $this->pendingSafeAction === null) {
            $columns = array_keys($values);
            $literals = array_map(fn ($value) => $this->sqlLiteral($value), array_values($values));
            $this->pendingSafeAction = [
                'action' => 'insert',
                'title' => 'Confirm insert',
                'sql' => 'INSERT INTO `'.$this->database.'`.`'.$this->table.'` (`'.implode('`, `', $columns).'`) VALUES ('.implode(', ', $literals).');',
            ];

            return;
        }

        $this->pendingSafeAction = null;

        try {
            app(DataEditor::class)->insert($this->connection(), $this->database, $this->table, $values);
            $this->log('success', 'Row inserted.');
            $this->showInsertDialog = false;
            $this->insertValues = [];
            $this->invalidateGrid();
        } catch (Throwable $e) {
            $this->log('error', $e->getMessage());
        }
    }

    /**
     * Map a MySQL column type to an HTML input type (+ enum options, if
     * any). Shared by the inline cell editor and the insert-row dialog.
     *
     * @return array{0: string, 1: string[]}
     */
    public function inputTypeFor(string $type): array
    {
        return match (true) {
            str_starts_with($type, 'enum(') => ['enum', array_map(fn ($option) => trim($option, "'"), explode(',', substr($type, 5, -1)))],
            str_starts_with($type, 'datetime'), str_starts_with($type, 'timestamp') => ['datetime-local', []],
            str_starts_with($type, 'date') => ['date', []],
            str_starts_with($type, 'time') => ['time', []],
            (bool) preg_match('/^(tiny|small|medium|big)?int|^decimal|^float|^double/', $type) => ['number', []],
            default => ['text', []],
        };
    }

    public function duplicateRow(int $row): void
    {
        if ($this->safeMode && $this->pendingSafeAction === null) {
            $pk = $this->primaryKeyValues($row);
            $where = collect($pk)->map(fn ($v, $k) => '`'.$k.'` = '.$this->sqlLiteral($v))->implode(' AND ');
            $this->pendingSafeAction = [
                'action' => 'duplicate',
                'title' => 'Confirm duplicate row',
                'sql' => '-- Duplicate row matching: '.$where."\n".'INSERT INTO `'.$this->database.'`.`'.$this->table.'` SELECT … (auto-increment columns regenerated)',
                'row' => $row,
            ];

            return;
        }

        $row = (int) ($this->pendingSafeAction['row'] ?? $row);
        $this->pendingSafeAction = null;

        try {
            app(DataEditor::class)->duplicate(
                $this->connection(), $this->database, $this->table,
                $this->primaryKeyValues($row)
            );
            $this->log('success', 'Row duplicated.');
            $this->invalidateGrid();
        } catch (Throwable $e) {
            $this->log('error', $e->getMessage());
        }
    }

    public function confirmDeleteRows(): void
    {
        if ($this->selectedRows === []) {
            return;
        }

        if ($this->safeMode && $this->pendingSafeAction === null) {
            $statements = [];
            foreach ($this->selectedRows as $row) {
                $pk = $this->primaryKeyValues((int) $row);
                $where = collect($pk)->map(fn ($v, $k) => '`'.$k.'` = '.$this->sqlLiteral($v))->implode(' AND ');
                $statements[] = 'DELETE FROM `'.$this->database.'`.`'.$this->table.'` WHERE '.$where.' LIMIT 1;';
            }

            $this->pendingSafeAction = [
                'action' => 'delete',
                'title' => 'Confirm delete ('.count($this->selectedRows).' row(s))',
                'sql' => implode("\n", $statements),
            ];

            return;
        }

        $this->confirmingDelete = true;
    }

    public function deleteSelectedRows(): void
    {
        $this->confirmingDelete = false;
        $this->pendingSafeAction = null;

        try {
            $keys = array_map(fn ($row) => $this->primaryKeyValues((int) $row), $this->selectedRows);
            $deleted = app(DataEditor::class)->delete($this->connection(), $this->database, $this->table, $keys);
            $this->log('success', "Deleted $deleted row(s).");
        } catch (Throwable $e) {
            $this->log('error', $e->getMessage());
        }

        $this->resetEditing();
        $this->invalidateGrid();
    }

    public function confirmSafeAction(): void
    {
        $action = $this->pendingSafeAction['action'] ?? null;

        match ($action) {
            'save' => $this->saveChanges(),
            'insert' => $this->saveInsert(),
            'delete' => $this->deleteSelectedRows(),
            'duplicate' => $this->duplicateRow((int) ($this->pendingSafeAction['row'] ?? 0)),
            default => $this->pendingSafeAction = null,
        };
    }

    public function cancelSafeAction(): void
    {
        $this->pendingSafeAction = null;
    }

    private function sqlLiteral(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'".str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value)."'";
    }

    // ------------------------------------------------------------------
    // Copy helpers (called from the context menu, return strings for the clipboard)

    public function copyCell(int $row, string $column): string
    {
        $value = $this->rawCell($row, $column);

        return $value === null ? 'NULL' : (string) $value;
    }

    public function copyRowCsv(int $row, string $separator = ','): string
    {
        $result = $this->gridResult;
        $cells = [];

        foreach ($result['columns'] ?? [] as $column) {
            $value = $this->rawCell($row, $column);
            $cells[] = $value === null ? '' : '"'.str_replace('"', '""', (string) $value).'"';
        }

        return implode($separator, $cells);
    }

    public function copyRowInsert(int $row): string
    {
        $result = $this->gridResult;
        $columns = $result['columns'] ?? [];
        $values = [];

        foreach ($columns as $column) {
            $raw = $this->gridResult['rows'][$row][$column] ?? null;

            if ($raw === null) {
                $values[] = 'NULL';
            } elseif (is_array($raw) && $raw['blob']) {
                $values[] = '0x'.$raw['full'];
            } elseif (is_int($raw) || is_float($raw)) {
                $values[] = (string) $raw;
            } else {
                $value = is_array($raw) ? $raw['full'] : $raw;
                $values[] = "'".addslashes((string) $value)."'";
            }
        }

        $quote = fn (string $identifier) => '`'.str_replace('`', '``', $identifier).'`';

        return sprintf(
            'INSERT INTO %s.%s (%s) VALUES (%s);',
            $quote($this->database), $quote($this->table),
            implode(', ', array_map($quote, $columns)),
            implode(', ', $values)
        );
    }

    // ------------------------------------------------------------------
    // Resultset export

    #[On('export-table-data')]
    public function exportTableData(int $connectionId, string $database, string $table, string $format)
    {
        if ($connectionId !== $this->connectionId) {
            return;
        }

        $this->database = $database;
        $this->table = $table;
        $this->mode = 'table';

        return $this->exportRows($format);
    }

    /**
     * Export all rows of the current table (filters/sort applied, no limit)
     * or the current query result as CSV, JSON or SQL INSERTs.
     */
    public function exportRows(string $format)
    {
        if (! in_array($format, ['csv', 'json', 'sql'], true)) {
            return;
        }

        try {
            [$columns, $rows] = $this->rowsForExport();
        } catch (Throwable $e) {
            $this->log('error', $e->getMessage());

            return;
        }

        $name = ($this->table ?? 'query-result').'-'.now()->format('Ymd-His').'.'.$format;
        $content = $this->formatExport($format, $columns, $rows);

        $this->log('success', count($rows).' row(s) exported as '.strtoupper($format).'.');

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $name);
    }

    /**
     * @return array{0: string[], 1: array<int, array<string, mixed>>}
     */
    private function rowsForExport(): array
    {
        if ($this->mode === 'query') {
            $result = $this->gridResult;

            if ($result === null || ! $result['ok']) {
                throw new \RuntimeException('No query result to export.');
            }

            $rows = array_map(
                fn ($row) => array_map(fn ($value) => is_array($value) ? $value['full'] : $value, $row),
                $result['rows']
            );

            return [$result['columns'], $rows];
        }

        $explorer = app(SchemaExplorer::class);
        $sql = 'SELECT * FROM '.$explorer->quote($this->database).'.'.$explorer->quote($this->table);
        $bindings = [];

        if ($this->filters !== []) {
            $built = app(FilterBuilder::class)->build($this->filters);
            $sql .= ' WHERE '.$built['where'];
            $bindings = $built['bindings'];
        }

        if ($this->sortColumn !== null) {
            $sql .= ' ORDER BY '.$explorer->quote($this->sortColumn).' '.($this->sortDirection === 'desc' ? 'DESC' : 'ASC');
        }

        $rows = array_map(
            fn ($row) => (array) $row,
            app(\App\Services\ConnectionManager::class)->db($this->connection(), $this->database)->select($sql, $bindings)
        );

        return [$rows === [] ? array_column($this->tableInfo['columns'] ?? [], 'name') : array_keys($rows[0]), $rows];
    }

    private function formatExport(string $format, array $columns, array $rows): string
    {
        if ($format === 'json') {
            return json_encode(
                array_map(function ($row) {
                    return array_map(
                        fn ($value) => is_string($value) && ! preg_match('//u', $value) ? '0x'.strtoupper(bin2hex($value)) : $value,
                        $row
                    );
                }, $rows),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
        }

        if ($format === 'csv') {
            $out = fopen('php://temp', 'w+');
            fputcsv($out, $columns);

            foreach ($rows as $row) {
                fputcsv($out, array_map(
                    fn ($column) => is_string($row[$column] ?? null) && ! preg_match('//u', $row[$column])
                        ? '0x'.strtoupper(bin2hex($row[$column]))
                        : $row[$column] ?? null,
                    $columns
                ));
            }

            rewind($out);
            $csv = stream_get_contents($out);
            fclose($out);

            return $csv;
        }

        // SQL INSERTs
        $dumper = app(\App\Services\SqlDumper::class);
        $pdo = app(\App\Services\ConnectionManager::class)->db($this->connection(), $this->database)->getPdo();
        $quote = fn (string $identifier) => '`'.str_replace('`', '``', $identifier).'`';
        $table = $quote($this->table ?? 'query_result');
        $columnList = implode(', ', array_map($quote, $columns));

        $lines = [];

        foreach ($rows as $row) {
            $values = implode(', ', array_map(fn ($column) => $dumper->literal($pdo, $row[$column] ?? null), $columns));
            $lines[] = "INSERT INTO $table ($columnList) VALUES ($values);";
        }

        return implode("\n", $lines)."\n";
    }

    // ------------------------------------------------------------------
    // FK drill-down

    public function showRelated(int $row, string $column): void
    {
        $relation = $this->foreignKeys[$column] ?? null;

        if ($relation === null) {
            return;
        }

        $this->openRelatedRecord($relation, $this->rawCell($row, $column), "`{$this->table}`.`$column`");
    }

    /**
     * Drill further from inside the record dialog.
     */
    public function drillRelated(string $column): void
    {
        $top = end($this->recordStack);

        if ($top === false) {
            return;
        }

        $relations = app(RelationResolver::class)->foreignKeys($this->connection(), $top['database'], $top['table']);
        $relation = $relations[$column] ?? null;

        if ($relation === null) {
            return;
        }

        $raw = $top['row'][$column] ?? null;
        $value = is_array($raw) ? $raw['full'] : $raw;

        $this->openRelatedRecord($relation, $value, "`{$top['table']}`.`$column`");
    }

    public function popRecord(): void
    {
        array_pop($this->recordStack);
    }

    public function closeRecordDialog(): void
    {
        $this->recordStack = [];
    }

    public function openRecordInGrid(): void
    {
        $top = end($this->recordStack);

        if ($top === false) {
            return;
        }

        $this->recordStack = [];
        $this->showTable($this->connectionId, $top['database'], $top['table'], [[
            'column' => $top['keyColumn'],
            'operator' => '=',
            'value' => (string) $top['keyValue'],
            'value2' => '',
        ]]);
    }

    private function openRelatedRecord(array $relation, mixed $value, string $via): void
    {
        try {
            $row = app(RelationResolver::class)->related($this->connection(), $relation, $value);
        } catch (Throwable $e) {
            $this->log('error', $e->getMessage());

            return;
        }

        if ($row === null) {
            $this->log('info', "No related record found in `{$relation['table']}` for value '$value'.");

            return;
        }

        $fks = app(RelationResolver::class)->foreignKeys($this->connection(), $relation['database'], $relation['table']);

        $this->recordStack[] = [
            'database' => $relation['database'],
            'table' => $relation['table'],
            'row' => $row,
            'relation' => $via.' → `'.$relation['table'].'`.`'.$relation['column'].'`',
            'convention' => $relation['convention'],
            'keyColumn' => $relation['column'],
            'keyValue' => $value,
            'fkColumns' => array_keys($fks),
        ];
    }

    // ------------------------------------------------------------------
    // Data access

    #[Computed]
    public function gridResult(): ?array
    {
        if ($this->mode === 'query') {
            return $this->queryResultKey === null ? null : Cache::get($this->queryResultKey);
        }

        if ($this->database === null || $this->table === null) {
            return null;
        }

        $explorer = app(SchemaExplorer::class);
        $target = $explorer->quote($this->database).'.'.$explorer->quote($this->table);
        $sql = "SELECT * FROM $target";
        $bindings = [];

        if ($this->filters !== []) {
            try {
                $built = app(FilterBuilder::class)->build($this->filters);
                $sql .= ' WHERE '.$built['where'];
                $bindings = $built['bindings'];
            } catch (Throwable $e) {
                $this->log('error', $e->getMessage());
            }
        }

        if ($this->sortColumn !== null) {
            $sql .= ' ORDER BY '.$explorer->quote($this->sortColumn).' '.($this->sortDirection === 'desc' ? 'DESC' : 'ASC');
        }

        if ($this->limitRows) {
            $sql .= sprintf(' LIMIT %d, %d', $this->firstRow, $this->rowCount);
        }

        $result = app(QueryRunner::class)->run($this->connection(), $this->database, $sql, $bindings, log: false);

        if ($result['ok']) {
            $this->log('success', sprintf(
                '%d row(s) fetched from `%s`.`%s` in %s ms.',
                $result['row_count'], $this->database, $this->table, $result['duration_ms']
            ));
        } else {
            $this->log('error', $result['error']);
        }

        return $result;
    }

    #[Computed]
    public function tableInfo(): ?array
    {
        if ($this->database === null || $this->table === null) {
            return null;
        }

        $explorer = app(SchemaExplorer::class);

        try {
            return [
                'columns' => $explorer->columns($this->connection(), $this->database, $this->table),
                'indexes' => $explorer->indexes($this->connection(), $this->database, $this->table),
                'ddl' => $explorer->ddl($this->connection(), $this->database, $this->table),
                'error' => null,
            ];
        } catch (Throwable $e) {
            return ['columns' => [], 'indexes' => [], 'ddl' => '', 'error' => $e->getMessage()];
        }
    }

    /** @return string[] */
    #[Computed]
    public function primaryKey(): array
    {
        if ($this->database === null || $this->table === null) {
            return [];
        }

        try {
            return app(SchemaExplorer::class)->primaryKey($this->connection(), $this->database, $this->table);
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, array> column => relation */
    #[Computed]
    public function foreignKeys(): array
    {
        if ($this->database === null || $this->table === null) {
            return [];
        }

        try {
            return app(RelationResolver::class)->foreignKeys($this->connection(), $this->database, $this->table);
        } catch (Throwable) {
            return [];
        }
    }

    public function isEditable(): bool
    {
        return $this->mode === 'table' && $this->primaryKey !== [];
    }

    public function clearMessages(): void
    {
        $this->messages = [];
    }

    /**
     * Raw (unformatted-as-far-as-possible) cell value by grid position.
     */
    private function rawCell(int $row, string $column): mixed
    {
        $value = $this->gridResult['rows'][$row][$column] ?? null;

        return is_array($value) ? $value['full'] : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function primaryKeyValues(int $row): array
    {
        $values = [];

        foreach ($this->primaryKey as $column) {
            $values[$column] = $this->rawCell($row, $column);
        }

        return $values;
    }

    private function resetEditing(): void
    {
        $this->pendingEdits = [];
        $this->editingCell = null;
        $this->selectedRows = [];
        $this->showInsertDialog = false;
        $this->insertValues = [];
        $this->confirmingDelete = false;
    }

    private function log(string $type, string $text): void
    {
        $this->messages[] = [
            'time' => now()->format('H:i:s'),
            'type' => $type,
            'text' => $text,
        ];

        if (count($this->messages) > 500) {
            $this->messages = array_slice($this->messages, -500);
        }
    }

    private function connection(): Connection
    {
        return Connection::findOrFail($this->connectionId);
    }

    public function render()
    {
        return view('livewire.results-panel');
    }
}
