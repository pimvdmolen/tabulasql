{{-- Shared resultset table. Expects: $result, $sortable (bool), $editable (bool). --}}
@php
    $columnMeta = [];
    if ($editable) {
        foreach (($this->tableInfo['columns'] ?? []) as $meta) {
            $columnMeta[$meta['name']] = $meta;
        }
    }
    $fkColumns = $editable || ($mode ?? null) === 'table' ? $this->foreignKeys : [];
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
                <th class="relative w-8 border border-edge/60 px-1 py-1">
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
                            <span class="text-sky-600 dark:text-sky-400">{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
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
            <tr class="hover:bg-raised/50" wire:key="row-{{ $version }}-{{ $rowIndex }}">
                @if ($editable)
                    <td class="border border-grid px-1 py-0.5 text-center">
                        <input type="checkbox" wire:model.live="selectedRows" value="{{ $rowIndex }}" class="size-3 rounded border-edge bg-raised">
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
                    <td
                        @if ($editable && ! is_array($value)) wire:dblclick="startEdit({{ $rowIndex }}, @js($column))" @endif
                        @if (($mode ?? null) === 'table')
                            x-on:contextmenu.prevent="$store.ctx.open($event, window.gridCellMenu($wire, {
                                row: {{ $rowIndex }},
                                col: @js($column),
                                editable: @js($editable),
                                isFk: @js($isFk),
                                hasPending: @js($pendingEdits !== []),
                                hasSelection: @js($selectedRows !== []),
                                hasFilters: @js($filters !== []),
                                sorted: @js($sortColumn !== null),
                            }))"
                        @endif
                        class="group/cell relative max-w-md truncate whitespace-nowrap border border-grid px-2 py-0.5 select-text
                            {{ $hasPending ? 'bg-amber-500/15 text-amber-700 dark:text-amber-300' : 'text-body' }}"
                    >
                        @if ($isEditing)
                            @php [$inputType, $options] = $this->inputTypeFor($columnMeta[$column]['type'] ?? 'text'); @endphp
                            @if ($inputType === 'enum')
                                <select
                                    x-init="$el.focus()"
                                    x-on:change="$wire.setCellValue({{ $rowIndex }}, @js($column), $event.target.value)"
                                    x-on:keydown.escape="$wire.cancelEditCell()"
                                    class="w-full border border-sky-500 bg-surface px-1 py-0 text-body focus:outline-none"
                                >
                                    <option value="" @selected($value === null)></option>
                                    @foreach ($options as $option)
                                        <option value="{{ $option }}" @selected($value === $option)>{{ $option }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input
                                    type="{{ $inputType }}"
                                    value="{{ $inputType === 'datetime-local' ? str_replace(' ', 'T', (string) $value) : $value }}"
                                    x-init="$el.focus(); $el.select && $el.select()"
                                    x-on:keydown.enter="$wire.setCellValue({{ $rowIndex }}, @js($column), $event.target.value.replace('T', ' '))"
                                    x-on:keydown.escape.stop="$wire.cancelEditCell()"
                                    x-on:blur="$wire.setCellValue({{ $rowIndex }}, @js($column), $event.target.value.replace('T', ' '))"
                                    class="w-full min-w-32 border border-sky-500 bg-surface px-1 py-0 text-body focus:outline-none"
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
                            @elseif (is_array($value))
                                <button
                                    class="cursor-pointer rounded bg-raised px-1.5 py-0.5 text-left text-dim hover:bg-overlay"
                                    @click="viewer = {
                                        title: @js($column.', '.($value['blob'] ? 'binary, ' : '').\Illuminate\Support\Number::fileSize($value['size'])),
                                        content: @js($value['full']),
                                        note: @js($value['truncated'] ? 'Value truncated to 64 KB for display.' : ($value['blob'] ? 'Hex representation.' : null)),
                                    }"
                                >
                                    {{ $value['blob'] ? '⬡' : '¶' }} {{ \Illuminate\Support\Number::fileSize($value['size']) }}
                                </button>
                            @else
                                {{ $value }}
                            @endif

                            @if ($isFk && $value !== null && ! is_array($value))
                                <button
                                    wire:click="showRelated({{ $rowIndex }}, @js($column))"
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
