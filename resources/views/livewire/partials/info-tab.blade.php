@php $info = $this->tableInfo; @endphp
<div class="h-full overflow-auto p-3">
    @if ($table === null || $info === null)
        <div class="flex h-full items-center justify-center text-sm text-faint">
            Select a table in the Object Explorer to view its structure.
        </div>
    @elseif ($info['error'] !== null)
        <div class="rounded border border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-950/40 p-3 font-mono text-[0.78rem] text-red-700 dark:text-red-300">{{ $info['error'] }}</div>
    @else
        <div class="mb-2 flex items-center justify-between">
            <h3 class="text-[0.78rem] font-semibold uppercase tracking-wide text-dim">Columns</h3>
            <button wire:click="refresh" class="rounded border border-edge px-2 py-0.5 text-[0.78rem] text-dim hover:bg-raised hover:text-body">⟳ Refresh</button>
        </div>
        <p class="mb-2 text-[0.72rem] text-faint">Double-click name, type, or default to edit. Click Null to toggle. Primary-key and foreign-key name/type changes are blocked.</p>
        <table class="mb-4 w-full border-collapse font-mono text-[0.78rem]">
            <thead>
                <tr class="bg-chrome text-left text-dim">
                    <th class="border border-edge/60 px-2 py-1">Field</th>
                    <th class="border border-edge/60 px-2 py-1">Type</th>
                    <th class="border border-edge/60 px-2 py-1">Null</th>
                    <th class="border border-edge/60 px-2 py-1">Key</th>
                    <th class="border border-edge/60 px-2 py-1">Default</th>
                    <th class="border border-edge/60 px-2 py-1">Extra</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($info['columns'] as $column)
                    @php
                        $editing = $editingColumn !== null && $editingColumn['name'] === $column['name']
                            ? $editingColumn['field']
                            : null;
                    @endphp
                    <tr class="text-body" wire:key="col-{{ $column['name'] }}">
                        <td
                            class="border border-grid px-2 py-0.5"
                            wire:dblclick="startEditColumn(@js($column['name']), 'name')"
                        >
                            @if ($editing === 'name')
                                <input
                                    type="text"
                                    value="{{ $column['name'] }}"
                                    x-init="$nextTick(() => { $el.focus(); $el.select(); })"
                                    x-on:keydown.enter="$wire.saveColumnEdit(@js($column['name']), 'name', $event.target.value)"
                                    x-on:keydown.escape.stop="$wire.cancelEditColumn()"
                                    x-on:blur="$wire.saveColumnEdit(@js($column['name']), 'name', $event.target.value)"
                                    class="cell-editor w-full border border-sky-500 bg-surface px-1 py-0 text-body focus:outline-none"
                                >
                            @else
                                @if ($column['key'] === 'PRI')🔑 @endif{{ $column['name'] }}
                            @endif
                        </td>
                        <td
                            class="border border-grid px-2 py-0.5"
                            wire:dblclick="startEditColumn(@js($column['name']), 'type')"
                        >
                            @if ($editing === 'type')
                                <input
                                    type="text"
                                    value="{{ $column['type'] }}"
                                    x-init="$nextTick(() => { $el.focus(); $el.select(); })"
                                    x-on:keydown.enter="$wire.saveColumnEdit(@js($column['name']), 'type', $event.target.value)"
                                    x-on:keydown.escape.stop="$wire.cancelEditColumn()"
                                    x-on:blur="$wire.saveColumnEdit(@js($column['name']), 'type', $event.target.value)"
                                    class="cell-editor w-full border border-sky-500 bg-surface px-1 py-0 text-body focus:outline-none"
                                >
                            @else
                                {{ $column['type'] }}
                            @endif
                        </td>
                        <td class="border border-grid px-2 py-0.5">
                            <button
                                type="button"
                                wire:click="toggleColumnNullable(@js($column['name']))"
                                class="rounded px-1 {{ $column['nullable'] ? 'text-body' : 'text-dim' }}"
                                title="Toggle NULL / NOT NULL"
                            >{{ $column['nullable'] ? 'YES' : 'NO' }}</button>
                        </td>
                        <td class="border border-grid px-2 py-0.5">{{ $column['key'] }}</td>
                        <td
                            class="border border-grid px-2 py-0.5"
                            wire:dblclick="startEditColumn(@js($column['name']), 'default')"
                        >
                            @if ($editing === 'default')
                                <input
                                    type="text"
                                    value="{{ $column['default'] ?? '' }}"
                                    placeholder="NULL"
                                    x-init="$nextTick(() => $el.focus())"
                                    x-on:keydown.enter="$wire.saveColumnEdit(@js($column['name']), 'default', $event.target.value)"
                                    x-on:keydown.escape.stop="$wire.cancelEditColumn()"
                                    x-on:blur="$wire.saveColumnEdit(@js($column['name']), 'default', $event.target.value)"
                                    class="cell-editor w-full border border-sky-500 bg-surface px-1 py-0 text-body focus:outline-none"
                                >
                            @else
                                {{ $column['default'] ?? '(NULL)' }}
                            @endif
                        </td>
                        <td class="border border-grid px-2 py-0.5">{{ $column['extra'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h3 class="mb-2 text-[0.78rem] font-semibold uppercase tracking-wide text-dim">Indexes</h3>
        <table class="mb-4 w-full border-collapse font-mono text-[0.78rem]">
            <thead>
                <tr class="bg-chrome text-left text-dim">
                    <th class="border border-edge/60 px-2 py-1">Name</th>
                    <th class="border border-edge/60 px-2 py-1">Columns</th>
                    <th class="border border-edge/60 px-2 py-1">Type</th>
                    <th class="border border-edge/60 px-2 py-1">Unique</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($info['indexes'] as $index)
                    <tr class="text-body">
                        <td class="border border-grid px-2 py-0.5">{{ $index['name'] }}</td>
                        <td class="border border-grid px-2 py-0.5">{{ implode(', ', $index['columns']) }}</td>
                        <td class="border border-grid px-2 py-0.5">{{ $index['type'] }}</td>
                        <td class="border border-grid px-2 py-0.5">{{ $index['unique'] ? 'YES' : 'NO' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="border border-grid px-2 py-1 text-faint">No indexes.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="mb-2 flex items-center justify-between">
            <h3 class="text-[0.78rem] font-semibold uppercase tracking-wide text-dim">DDL</h3>
            <button
                x-data
                @click="navigator.clipboard.writeText(@js($info['ddl'])).then(() => { $el.textContent = 'Copied!'; setTimeout(() => $el.textContent = 'Copy', 1500) })"
                class="rounded border border-edge px-2 py-0.5 text-[0.78rem] text-dim hover:bg-raised hover:text-body"
            >Copy</button>
        </div>
        <pre class="overflow-x-auto rounded border border-grid bg-chrome p-3 font-mono text-[0.78rem] text-body select-text">{{ $info['ddl'] }}</pre>
    @endif
</div>
