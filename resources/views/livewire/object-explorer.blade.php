<div
    class="flex h-full min-h-0 flex-col"
    x-on:keydown.window="
        if ($el.offsetParent === null || $event.target.closest('.cm-editor, input, textarea, select')) return;
        if ($event.key === 'ArrowUp') { $event.preventDefault(); $wire.navigateTable('up'); }
        else if ($event.key === 'ArrowDown') { $event.preventDefault(); $wire.navigateTable('down'); }
    "
>
    {{-- Search --}}
    <div class="shrink-0 space-y-1 border-b border-edge/60 p-2">
        <div class="flex gap-1">
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Filter tables…"
                class="w-full rounded border border-edge bg-raised px-2 py-1 text-sm text-body placeholder:text-muted focus:border-sky-500 focus:outline-none"
            >
            <button
                wire:click="refresh"
                wire:loading.attr="disabled"
                class="shrink-0 rounded border border-edge px-2 text-dim hover:bg-raised hover:text-body"
                title="Refresh"
            ><x-icon name="refresh" class="size-3.5" /></button>
        </div>
        <label class="flex items-center gap-1.5 text-[0.78rem] text-muted">
            <input type="checkbox" wire:model.live="searchRegex">
            Search as Regex
        </label>
    </div>

    {{-- Tree --}}
    <div class="min-h-0 flex-1 overflow-auto p-1 text-sm" wire:loading.class="opacity-50">
        @if ($error !== null)
            <div class="m-1 rounded border border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-950/40 p-2 text-[0.78rem] text-red-700 dark:text-red-300">{{ $error }}</div>
        @endif

        @php $singleDatabase = count($databases) === 1; @endphp

        @foreach ($databases as $database)
            @php $isExpanded = $singleDatabase || in_array($database, $expandedDatabases, true); @endphp
            <div wire:key="db-{{ $database }}">
                @unless ($singleDatabase)
                    <button
                        wire:click="toggleDatabase(@js($database))"
                        x-on:contextmenu.prevent="$store.ctx.open($event, window.treeDatabaseMenu($wire, { connectionId: {{ $connectionId }}, database: @js($database) }))"
                        class="flex w-full items-center gap-1.5 rounded px-1.5 py-1 text-left hover:bg-raised {{ $activeDatabase === $database ? 'text-sky-700 dark:text-sky-300' : 'text-body' }}"
                    >
                        <x-icon :name="$isExpanded ? 'chevron-down' : 'chevron-right'" class="size-3 text-muted" />
                        <x-icon name="database" class="size-3.5 text-amber-500/80" />
                        <span class="truncate">{{ $database }}</span>
                    </button>
                @endunless

                @if ($isExpanded)
                    <div class="{{ $singleDatabase ? '' : 'ml-4 border-l border-grid pl-1' }}">
                        @php
                            $items = $this->filteredTables($database);
                            $tables = array_filter($items, fn ($t) => $t['type'] === 'table');
                            $views = array_filter($items, fn ($t) => $t['type'] === 'view');
                        @endphp

                        <div class="px-1.5 pt-1 text-[0.78rem] font-semibold uppercase tracking-wide text-faint">Tables ({{ count($tables) }})</div>
                        @foreach ($tables as $table)
                            @include('livewire.partials.explorer-table', ['database' => $database, 'table' => $table])
                        @endforeach

                        @if (count($views) > 0)
                            <div class="px-1.5 pt-1 text-[0.78rem] font-semibold uppercase tracking-wide text-faint">Views ({{ count($views) }})</div>
                            @foreach ($views as $table)
                                @include('livewire.partials.explorer-table', ['database' => $database, 'table' => $table])
                            @endforeach
                        @endif

                        @php $routines = $loadedRoutines[$database] ?? ['procedures' => [], 'functions' => [], 'triggers' => [], 'events' => []]; @endphp

                        @if (count($routines['procedures']) > 0)
                            <div class="px-1.5 pt-1 text-[0.78rem] font-semibold uppercase tracking-wide text-faint">Procedures ({{ count($routines['procedures']) }})</div>
                            @foreach ($routines['procedures'] as $name)
                                @include('livewire.partials.explorer-routine', ['database' => $database, 'name' => $name, 'kind' => 'procedure', 'icon' => 'settings'])
                            @endforeach
                        @endif

                        @if (count($routines['functions']) > 0)
                            <div class="px-1.5 pt-1 text-[0.78rem] font-semibold uppercase tracking-wide text-faint">Functions ({{ count($routines['functions']) }})</div>
                            @foreach ($routines['functions'] as $name)
                                @include('livewire.partials.explorer-routine', ['database' => $database, 'name' => $name, 'kind' => 'function', 'icon' => 'function'])
                            @endforeach
                        @endif

                        @if (count($routines['triggers']) > 0)
                            <div class="px-1.5 pt-1 text-[0.78rem] font-semibold uppercase tracking-wide text-faint">Triggers ({{ count($routines['triggers']) }})</div>
                            @foreach ($routines['triggers'] as $trigger)
                                @include('livewire.partials.explorer-routine', ['database' => $database, 'name' => $trigger['name'], 'kind' => 'trigger', 'icon' => 'bolt', 'subtitle' => $trigger['table']])
                            @endforeach
                        @endif

                        @if (count($routines['events']) > 0)
                            <div class="px-1.5 pt-1 text-[0.78rem] font-semibold uppercase tracking-wide text-faint">Events ({{ count($routines['events']) }})</div>
                            @foreach ($routines['events'] as $name)
                                @include('livewire.partials.explorer-routine', ['database' => $database, 'name' => $name, 'kind' => 'event', 'icon' => 'clock'])
                            @endforeach
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Copy selection bar --}}
    @if ($checked !== [])
        <div class="flex shrink-0 items-center gap-2 border-t border-edge/60 bg-chrome px-2 py-1 text-[0.78rem]">
            <span class="text-dim">{{ count($checked) }} selected</span>
            <button wire:click="openCopyWizard" class="rounded bg-sky-600 px-2 py-0.5 font-medium text-white hover:bg-sky-500">Copy…</button>
            <button wire:click="clearChecked" class="text-muted hover:text-body">clear</button>
        </div>
    @endif

    {{-- Table/database operation confirmation --}}
    @if ($operation !== null)
        @php
            $kindNouns = ['table' => 'table', 'view' => 'view', 'procedure' => 'procedure', 'function' => 'function', 'trigger' => 'trigger', 'event' => 'event'];
            $kindNoun = $kindNouns[$operation['kind']] ?? 'table';
            $opLabels = [
                'rename' => 'Rename '.$kindNoun,
                'truncate' => 'Truncate '.$kindNoun,
                'drop' => 'Drop '.$kindNoun,
                'drop-database' => 'Drop database',
                'truncate-database' => 'Truncate database',
                'empty-database' => 'Empty database',
            ];
            $deletionScope = [
                'drop' => "the $kindNoun".($kindNoun === 'table' ? ' and all its data' : ''),
                'drop-database' => 'the entire database including all tables',
                'truncate-database' => 'all rows from every table in the database (structure stays intact)',
                'empty-database' => 'every table, view, procedure, function, trigger and event in the database',
            ];
            $target = $operation['table'] !== null ? "`{$operation['database']}`.`{$operation['table']}`" : "`{$operation['database']}`";
        @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" wire:keydown.escape.window="cancelOperation">
            <div class="w-[min(420px,92vw)] rounded-lg border border-edge bg-surface p-4 shadow-xl">
                <h3 class="mb-2 text-sm font-semibold {{ in_array($operation['type'], ['drop', 'drop-database', 'truncate', 'truncate-database', 'empty-database']) ? 'text-red-600 dark:text-red-400' : 'text-strong' }}">
                    {{ $opLabels[$operation['type']] }} {{ $target }}
                </h3>

                @if ($operation['type'] === 'rename')
                    <label class="mb-3 block">
                        <span class="mb-1 block text-[0.78rem] text-dim">New table name</span>
                        <input type="text" wire:model="operation.input" wire:keydown.enter="executeOperation" class="input-field" x-init="$el.focus()">
                    </label>
                @elseif ($operation['type'] === 'truncate')
                    <p class="mb-3 text-[0.78rem] text-dim">This deletes <strong>all rows</strong> from {{ $target }}. The structure stays intact. This cannot be undone.</p>
                @else
                    <p class="mb-2 text-[0.78rem] text-dim">
                        This permanently deletes {{ $deletionScope[$operation['type']] ?? 'this object' }}. This cannot be undone.
                    </p>
                    <label class="mb-3 block">
                        <span class="mb-1 block text-[0.78rem] text-dim">Type <strong class="select-text">{{ $operation['table'] ?? $operation['database'] }}</strong> to confirm</span>
                        <input type="text" wire:model="operation.input" wire:keydown.enter="executeOperation" class="input-field" x-init="$el.focus()" autocomplete="off">
                    </label>
                @endif

                @if ($error !== null)
                    <div class="mb-3 rounded border border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-950/40 px-2 py-1.5 text-[0.78rem] text-red-700 dark:text-red-300">{{ $error }}</div>
                @endif

                <div class="flex justify-end gap-2">
                    <button wire:click="cancelOperation" class="rounded border border-edge px-3 py-1 text-[0.78rem] text-body hover:bg-raised">Cancel</button>
                    <button
                        wire:click="executeOperation"
                        class="rounded px-3 py-1 text-[0.78rem] text-white {{ $operation['type'] === 'rename' ? 'bg-sky-600 hover:bg-sky-500' : 'bg-red-600 hover:bg-red-500' }}"
                    >{{ $opLabels[$operation['type']] }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Status bar --}}
    <div class="shrink-0 border-t border-edge/60 px-2 py-1 text-[0.78rem] text-muted">
        {{ $activeDatabase ?? 'No active database' }}
    </div>
</div>
