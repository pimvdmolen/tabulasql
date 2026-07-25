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
                    <tr class="text-body">
                        <td class="border border-grid px-2 py-0.5">
                            @if ($column['key'] === 'PRI')🔑 @endif{{ $column['name'] }}
                        </td>
                        <td class="border border-grid px-2 py-0.5">{{ $column['type'] }}</td>
                        <td class="border border-grid px-2 py-0.5">{{ $column['nullable'] ? 'YES' : 'NO' }}</td>
                        <td class="border border-grid px-2 py-0.5">{{ $column['key'] }}</td>
                        <td class="border border-grid px-2 py-0.5">{{ $column['default'] ?? '(NULL)' }}</td>
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
