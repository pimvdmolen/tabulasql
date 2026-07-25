@php $result = $this->gridResult; @endphp
<div class="flex h-full flex-col">
    @if ($mode === 'table' && $table === null)
        <div class="flex h-full items-center justify-center text-sm text-faint">
            Select a table in the Object Explorer, or run a query.
        </div>
    @elseif ($mode === 'query')
        <div class="flex shrink-0 items-center gap-3 border-b border-grid px-2 py-1 text-[0.78rem] text-dim">
            <span class="font-semibold">Query result</span>
            @if ($result !== null && $result['ok'])
                <span class="ml-auto text-muted">{{ $result['row_count'] }} row(s) · {{ $result['duration_ms'] }} ms</span>
            @endif
        </div>
        <div class="min-h-0 flex-1 overflow-auto">
            @if ($result === null)
                <div class="p-4 text-sm text-faint">The result expired. Run the query again.</div>
            @elseif (! $result['ok'])
                <div class="m-2 rounded border border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-950/40 p-3 font-mono text-[0.78rem] text-red-700 dark:text-red-300">{{ $result['error'] }}</div>
            @elseif ($result['row_count'] === 0)
                <div class="p-4 text-sm text-faint">No rows.</div>
            @else
                @include('livewire.partials.grid-table', ['result' => $result, 'sortable' => false, 'editable' => false, 'gridKey' => 'query-'.($queryResultKey ?? 'none')])
            @endif
        </div>
    @else
        @php $editable = $this->isEditable(); @endphp

        {{-- Toolbar --}}
        <div class="flex shrink-0 flex-wrap items-center gap-x-3 gap-y-1 border-b border-grid px-2 py-1 text-[0.78rem] text-dim">
            <button wire:click="openFilterDialog" class="rounded border border-edge px-2 py-0.5 hover:bg-raised hover:text-body {{ $filters !== [] ? 'border-sky-500 text-sky-600 dark:text-sky-400' : '' }}" title="Custom filter">⧩ Filter</button>
            <button wire:click="refresh" class="rounded border border-edge px-2 py-0.5 hover:bg-raised hover:text-body" title="Refresh">⟳</button>

            <label class="flex items-center gap-1.5">
                <input type="checkbox" wire:model.live="limitRows" class="size-3 rounded border-edge bg-raised">
                Limit rows
            </label>
            <label class="flex items-center gap-1">
                First row
                <input type="number" min="0" wire:model.live.debounce.500ms="firstRow" @disabled(! $limitRows)
                    class="w-20 rounded border border-edge bg-raised px-1.5 py-0.5 text-body disabled:opacity-40">
            </label>
            <label class="flex items-center gap-1">
                # of rows
                <input type="number" min="1" wire:model.live.debounce.500ms="rowCount" @disabled(! $limitRows)
                    class="w-16 rounded border border-edge bg-raised px-1.5 py-0.5 text-body disabled:opacity-40">
            </label>
            <div class="flex gap-0.5">
                <button wire:click="previousPage" @disabled(! $limitRows || $firstRow === 0)
                    class="rounded border border-edge px-1.5 py-0.5 hover:bg-raised disabled:opacity-40" title="Previous page">◀</button>
                <button wire:click="nextPage" @disabled(! $limitRows || ($result !== null && $result['row_count'] < $rowCount))
                    class="rounded border border-edge px-1.5 py-0.5 hover:bg-raised disabled:opacity-40" title="Next page">▶</button>
            </div>

            @if ($editable)
                <span class="h-4 w-px bg-edge"></span>
                <button wire:click="openInsertDialog" class="rounded border border-edge px-2 py-0.5 hover:bg-raised hover:text-body {{ $showInsertDialog ? 'bg-raised text-body' : '' }}" title="Insert new row">+ Row</button>
                @if ($selectedRows !== [])
                    <button wire:click="confirmDeleteRows" class="rounded border border-red-400 dark:border-red-700 px-2 py-0.5 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/40">🗑 Delete ({{ count($selectedRows) }})</button>
                @endif
                @if ($pendingEdits !== [])
                    <button wire:click="saveChanges" class="rounded bg-emerald-600 px-2.5 py-0.5 font-medium text-white hover:bg-emerald-500">✓ Save Changes ({{ count($pendingEdits) }})</button>
                    <button wire:click="cancelChanges" class="rounded border border-edge px-2 py-0.5 hover:bg-raised hover:text-body">Cancel Changes</button>
                @endif
            @elseif ($result !== null && $result['ok'])
                <span class="rounded bg-amber-100 px-1.5 py-0.5 text-amber-700 dark:bg-amber-950 dark:text-amber-400" title="This table has no primary key">read-only, no primary key</span>
            @endif

            @if ($result !== null && $result['ok'])
                <span class="ml-auto text-muted">
                    {{ $result['row_count'] }} row(s){{ $limitRows ? ' from row '.number_format($firstRow) : '' }} · {{ $result['duration_ms'] }} ms
                </span>
            @endif
        </div>

        {{-- Filter chips --}}
        @if ($filters !== [])
            <div class="flex shrink-0 flex-wrap items-center gap-1 border-b border-grid bg-chrome/60 px-2 py-1">
                @foreach ($this->filterChips as $index => $chip)
                    <span class="flex items-center gap-1 rounded-full border border-sky-500/40 bg-sky-500/10 px-2 py-0.5 font-mono text-xs text-sky-700 dark:text-sky-300" wire:key="chip-{{ $index }}">
                        {{ $chip }}
                        <button wire:click="removeFilter({{ $index }})" class="hover:text-strong" title="Remove filter">&times;</button>
                    </span>
                @endforeach
                <button wire:click="clearFilters" class="ml-1 text-xs text-muted hover:text-body">clear all</button>
            </div>
        @endif

        {{-- Grid --}}
        <div class="min-h-0 flex-1 overflow-auto">
            @if ($result === null)
                <div class="p-4 text-sm text-faint">Loading…</div>
            @elseif (! $result['ok'])
                <div class="m-2 rounded border border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-950/40 p-3 font-mono text-[0.78rem] text-red-700 dark:text-red-300">{{ $result['error'] }}</div>
            @elseif ($result['row_count'] === 0)
                <div class="p-4 text-sm text-faint">No rows{{ $filters !== [] ? ' match the active filters' : '' }}.</div>
            @else
                @include('livewire.partials.grid-table', ['result' => $result, 'sortable' => true, 'editable' => $editable, 'gridKey' => "table-$database.$table"])
            @endif
        </div>

        @include('livewire.partials.filter-dialog')
        @include('livewire.partials.insert-row-dialog')

        {{-- Delete confirmation --}}
        @if ($confirmingDelete)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60">
                <div class="w-96 rounded-lg border border-edge bg-surface p-4 shadow-xl">
                    <h3 class="mb-2 text-sm font-semibold text-strong">Delete {{ count($selectedRows) }} row(s)?</h3>
                    <p class="mb-4 text-[0.78rem] text-dim">This permanently deletes the selected rows from `{{ $database }}`.`{{ $table }}`.</p>
                    <div class="flex justify-end gap-2">
                        <button wire:click="$set('confirmingDelete', false)" class="rounded border border-edge px-3 py-1 text-[0.78rem] text-body hover:bg-raised">Cancel</button>
                        <button wire:click="deleteSelectedRows" class="rounded bg-red-600 px-3 py-1 text-[0.78rem] text-white hover:bg-red-500">Delete</button>
                    </div>
                </div>
            </div>
        @endif
    @endif
</div>
