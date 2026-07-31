{{-- Shared resultset table. Expects: $result, $sortable (bool), $editable (bool). --}}
@php
    $columnMeta = [];
    if ($editable) {
        foreach (($this->tableInfo['columns'] ?? []) as $meta) {
            $columnMeta[$meta['name']] = $meta;
        }
    }
    $fkColumns = $editable || ($mode ?? null) === 'table' ? $this->foreignKeys : [];
    $selectedLookup = array_fill_keys(array_map('strval', $selectedRows ?? []), true);
@endphp
<table
    wire:key="{{ $gridKey ?? 'grid' }}"
    x-data="resizableColumns()"
    :class="{ 'grid-cols-sized': fitted }"
    class="w-max min-w-full border-collapse font-mono text-[0.78rem]"
>
    <colgroup>
        @if ($editable)
            <col style="width: 2rem; min-width: 2rem">
        @endif
        @foreach ($result['columns'] as $column)
            <col :style="widths[@js($column)] ? `width: ${widths[@js($column)]}px; min-width: ${widths[@js($column)]}px` : ''">
        @endforeach
    </colgroup>
    <thead class="sticky top-0 z-10 bg-chrome">
        <tr>
            @if ($editable)
                <th class="relative w-8 border border-edge/60 px-1 py-1 text-center">
                    @php
                        $allSelected = count($result['rows']) > 0
                            && count($selectedRows) === count($result['rows']);
                    @endphp
                    <input
                        type="checkbox"
                        wire:key="select-all-{{ $version }}-{{ count($selectedRows) }}-{{ count($result['rows']) }}"
                        wire:click.prevent="toggleSelectAllRows"
                        @checked($allSelected)
                        title="Select all rows"
                    >
                    <span
                        x-on:dblclick.stop.prevent="autoFitAll()"
                        x-on:mousedown.stop
                        title="Double-click: fit all columns to their content"
                        class="absolute inset-y-0 right-0 w-3 cursor-col-resize select-none hover:bg-sky-500/50"
                    ></span>
                </th>
            @endif
            @foreach ($result['columns'] as $column)
                <th
                    data-col="{{ $column }}"
                    class="relative whitespace-nowrap border border-edge/60 px-2 py-1 text-left font-semibold text-body"
                >
                    <span
                        @if ($sortable) wire:click="sortBy(@js($column))" @endif
                        class="block truncate pr-1.5 {{ $sortable ? 'cursor-pointer hover:text-sky-700 dark:hover:text-sky-300' : '' }}"
                    >
                        {{ $column }}
                        @if ($sortable && $sortColumn === $column)
                            <x-icon :name="$sortDirection === 'asc' ? 'sort-asc' : 'sort-desc'" class="ml-0.5 inline size-3 text-sky-600 dark:text-sky-400" />
                        @endif
                    </span>
                    <span
                        x-on:mousedown.stop.prevent="startResize(@js($column), $event, $el.closest('th'))"
                        x-on:click.stop.prevent
                        title="Drag to resize column"
                        class="absolute inset-y-0 right-0 w-3 cursor-col-resize select-none hover:bg-sky-500/50"
                    ></span>
                </th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($result['rows'] as $rowIndex => $row)
            <tr
                wire:key="row-{{ $version }}-{{ $rowIndex }}"
                @if (($mode ?? null) === 'table')
                    x-on:click="focusRow({{ $rowIndex }})"
                @endif
                :class="rowHighlightClass({{ $rowIndex }}, {{ isset($selectedLookup[(string) $rowIndex]) ? 'true' : 'false' }})"
            >
                @if ($editable)
                    <td class="border border-grid px-1 py-0.5 text-center" wire:click.stop>
                        <input
                            type="checkbox"
                            wire:model.live="selectedRows"
                            value="{{ $rowIndex }}"
                            x-on:click="if ($event.shiftKey) { $event.preventDefault(); $wire.toggleRowSelection({{ $rowIndex }}, true); }"
                        >
                    </td>
                @endif
                @foreach ($result['columns'] as $column)
                    @php
                        $value = $row[$column] ?? null;
                        $pending = $pendingEdits[$rowIndex][$column] ?? '__NONE__';
                        $hasPending = $pending !== '__NONE__';
                        $isEditing = $editable && $editingCell !== null && $editingCell['row'] === $rowIndex && $editingCell['col'] === $column;
                        $isFk = isset($fkColumns[$column]);
                    @endphp
                    @php
                        $isBlob = is_array($value) && ($value['blob'] ?? false);
                        $isLongText = is_array($value) && ! $isBlob;
                        $displayValue = $isLongText ? ($value['preview'] ?? '') : $value;
                        $editValue = $isLongText ? ($value['full'] ?? '') : $value;
                        $canEditCell = $editable && ! $isBlob;
                    @endphp
                    <td
                        @if ($canEditCell)
                            x-on:dblclick.stop="freezeAllWidths(); $wire.startEdit({{ $rowIndex }}, @js($column))"
                        @endif
                        @if (($mode ?? null) === 'table')
                            x-on:contextmenu.prevent="$store.ctx.open($event, window.gridCellMenu($wire, {
                                row: {{ $rowIndex }},
                                col: @js($column),
                                editable: @js($editable && ! $isBlob),
                                isFk: @js($isFk),
                                hasPending: @js($pendingEdits !== []),
                                hasSelection: @js($selectedRows !== []),
                                hasFilters: @js($filters !== []),
                                sorted: @js($sortColumn !== null),
                            }))"
                        @endif
                        class="group/cell relative max-w-md cursor-default truncate whitespace-nowrap border border-grid px-2 py-0.5 select-text
                            {{ $hasPending ? 'bg-amber-500/15 text-amber-700 dark:text-amber-300' : 'text-body' }}"
                    >
                        @if ($isEditing)
                            @php [$inputType, $options] = $this->inputTypeFor($columnMeta[$column]['type'] ?? 'text'); @endphp
                            @if ($inputType === 'enum')
                                <select
                                    x-init="$nextTick(() => $el.focus())"
                                    x-on:change="$wire.setCellValue({{ $rowIndex }}, @js($column), $event.target.value)"
                                    x-on:keydown.escape="$wire.cancelEditCell()"
                                    x-on:click.stop
                                    class="cell-editor border border-sky-500 bg-surface px-1 py-0 text-body focus:outline-none"
                                >
                                    <option value="" @selected($editValue === null)></option>
                                    @foreach ($options as $option)
                                        <option value="{{ $option }}" @selected($editValue === $option)>{{ $option }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input
                                    type="{{ $isLongText ? 'text' : $inputType }}"
                                    value="{{ $inputType === 'datetime-local' && ! $isLongText ? str_replace(' ', 'T', (string) $editValue) : $editValue }}"
                                    data-datetime="{{ $inputType === 'datetime-local' && ! $isLongText ? '1' : '0' }}"
                                    x-init="$nextTick(() => { $el.focus(); if ($el.dataset.datetime !== '1') $el.select?.(); })"
                                    x-on:keydown.enter="$wire.setCellValue({{ $rowIndex }}, @js($column), $el.dataset.datetime === '1' ? $event.target.value.replace('T', ' ') : $event.target.value)"
                                    x-on:keydown.escape.stop="$wire.cancelEditCell()"
                                    x-on:blur="$wire.setCellValue({{ $rowIndex }}, @js($column), $el.dataset.datetime === '1' ? $event.target.value.replace('T', ' ') : $event.target.value)"
                                    x-on:click.stop
                                    x-on:mousedown.stop
                                    class="cell-editor border border-sky-500 bg-surface px-1 py-0 text-body focus:outline-none"
                                >
                            @endif
                        @else
                            @if ($hasPending)
                                @if ($pending === null)
                                    <span class="italic">(NULL)</span>
                                @elseif ($pending === \App\Services\DataEditor::DEFAULT)
                                    <span class="italic">(DEFAULT)</span>
                                @else
                                    {{ $pending }}
                                @endif
                            @elseif ($value === null)
                                <span class="italic text-faint">(NULL)</span>
                            @elseif ($isBlob)
                                <button
                                    class="inline-flex cursor-pointer items-center gap-1 rounded bg-raised px-1.5 py-0.5 text-left text-dim"
                                    @click.stop="viewer = {
                                        title: @js($column.', binary, '.\App\Support\Bytes::format($value['size'])),
                                        content: @js($value['full']),
                                        note: @js($value['truncated'] ? 'Value truncated to 64 KB for display.' : 'Hex representation.'),
                                    }"
                                >
                                    <x-icon name="file" class="size-3" />
                                    {{ \App\Support\Bytes::format($value['size']) }}
                                </button>
                            @elseif ($isLongText)
                                <button
                                    type="button"
                                    class="block max-w-full truncate text-left"
                                    title="Click to view full value ({{ \App\Support\Bytes::format($value['size']) }})"
                                    @click.stop="
                                        (() => {
                                            const raw = @js($value['full']);
                                            let content = raw;
                                            let note = @js($value['truncated'] ? 'Value truncated to 64 KB for display.' : null);
                                            try {
                                                content = JSON.stringify(JSON.parse(raw), null, 2);
                                                note = note ? note + ' Pretty-printed JSON.' : 'Pretty-printed JSON.';
                                            } catch (e) {}
                                            viewer = {
                                                title: @js($column.', '.\App\Support\Bytes::format($value['size'])),
                                                content,
                                                note,
                                            };
                                        })()
                                    "
                                >{{ $displayValue }}{{ ($value['truncated'] ?? false) || strlen($value['full'] ?? '') > 60 ? '…' : '' }}</button>
                            @else
                                {{ $value }}
                            @endif

                            @if ($isFk && $value !== null && ! is_array($value))
                                <button
                                    wire:click.stop="showRelated({{ $rowIndex }}, @js($column))"
                                    class="absolute right-0.5 top-1/2 hidden -translate-y-1/2 rounded border px-1 text-[0.7rem] group-hover/cell:inline-block
                                        {{ $fkColumns[$column]['convention'] ? 'border-dashed border-edge bg-raised text-muted' : 'border-edge bg-raised text-dim' }} hover:bg-overlay hover:text-body"
                                    title="Show related record in {{ $fkColumns[$column]['table'] }}{{ $fkColumns[$column]['convention'] ? ' (convention match)' : '' }}"
                                >…</button>
                            @endif
                        @endif
                    </td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>
