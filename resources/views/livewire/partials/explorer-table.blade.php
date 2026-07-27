@php
    $key = $database.'.'.$table['name'];
    $isTableExpanded = in_array($key, $expandedTables, true);
    $isActiveTable = $activeTable === $key;
    $details = $loadedTableDetails[$key] ?? null;
@endphp
<div wire:key="tbl-{{ $key }}">
    <div class="group flex items-center">
        <input
            type="checkbox"
            wire:model.live="checked"
            value="{{ $database }}|{{ $table['name'] }}|{{ $table['type'] }}"
            class="mr-0.5 shrink-0 opacity-0 group-hover:opacity-100 checked:opacity-100"
            title="Select for copy"
        >
        <button
            wire:click="toggleTable(@js($database), @js($table['name']))"
            class="shrink-0 px-0.5 text-faint hover:text-body"
        ><x-icon :name="$isTableExpanded ? 'chevron-down' : 'chevron-right'" class="size-3" /></button>
        <button
            wire:click="selectTable(@js($database), @js($table['name']))"
            x-on:contextmenu.prevent="$store.ctx.open($event, window.treeTableMenu($wire, { connectionId: {{ $connectionId }}, database: @js($database), table: @js($table['name']), type: @js($table['type']) }))"
            class="flex min-w-0 flex-1 items-center gap-1.5 rounded px-1 py-0.5 text-left focus:outline-none
                {{ $isActiveTable ? 'bg-sky-500/20 font-medium text-strong' : 'text-body hover:bg-raised' }}"
            title="{{ $table['name'] }}"
        >
            <x-icon
                :name="$table['type'] === 'view' ? 'eye' : 'table'"
                class="size-3.5 {{ $table['type'] === 'view' ? 'text-purple-600/80 dark:text-purple-400/80' : 'text-sky-600/80 dark:text-sky-400/80' }}"
            />
            <span class="truncate">{{ $table['name'] }}</span>
        </button>
    </div>

    @if ($isTableExpanded && $details !== null)
        <div class="ml-4 border-l border-grid pl-2 text-[0.78rem]">
            @foreach ($details['columns'] as $column)
                <div class="flex items-center gap-1.5 px-1 py-0.5 text-dim" wire:key="col-{{ $key }}-{{ $column['name'] }}">
                    @if ($column['key'] === 'PRI')
                        <x-icon name="key" class="size-3 text-amber-400" />
                    @else
                        <x-icon name="dot" class="size-3 text-faint" />
                    @endif
                    <span class="truncate">{{ $column['name'] }}</span>
                    <span class="ml-auto shrink-0 pl-1 text-faint">{{ $column['type'] }}</span>
                </div>
            @endforeach
            @if (count($details['indexes']) > 0)
                <div class="px-1 pt-0.5 font-semibold uppercase tracking-wide text-faint">Indexes</div>
                @foreach ($details['indexes'] as $index)
                    <div class="flex items-center gap-1.5 px-1 py-0.5 text-dim" wire:key="idx-{{ $key }}-{{ $index['name'] }}">
                        <x-icon name="key" class="size-3 text-faint" />
                        <span class="truncate">{{ $index['name'] }}</span>
                        <span class="ml-auto shrink-0 pl-1 text-faint">{{ implode(', ', $index['columns']) }}</span>
                    </div>
                @endforeach
            @endif
        </div>
    @endif
</div>
