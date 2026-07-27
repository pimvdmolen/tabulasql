<div class="relative flex h-full min-h-0 flex-col">
    {{-- Query tab bar + toolbar --}}
    <div class="flex h-8 shrink-0 items-stretch border-b border-edge/60 bg-chrome px-1">
        <div class="flex min-w-0 items-stretch gap-px overflow-x-auto">
            @foreach ($tabs as $tab)
                <div
                    wire:key="qtab-{{ $tab['id'] }}"
                    class="group flex h-full shrink-0 items-center border-t-2
                        {{ $activeTab === $tab['id']
                            ? 'border-sky-500 bg-surface text-body'
                            : 'border-transparent text-muted hover:text-body' }}"
                >
                    <button wire:click="activateTab({{ $tab['id'] }})" class="flex h-full items-center px-3 text-[0.78rem]">{{ $tab['title'] }}</button>
                    @if (count($tabs) > 1)
                        <button
                            wire:click="closeTab({{ $tab['id'] }})"
                            class="mr-1 rounded px-0.5 text-muted opacity-0 hover:bg-overlay hover:text-body group-hover:opacity-100"
                        >&times;</button>
                    @endif
                </div>
            @endforeach
            <button wire:click="addTab" class="flex h-full shrink-0 items-center px-2 text-muted hover:text-body" title="New query tab">
                <x-icon name="plus" class="size-3.5" />
            </button>
        </div>

        <div class="ml-auto flex shrink-0 items-center gap-1 pl-2 text-[0.78rem]">
            <button
                x-on:click="window.dispatchEvent(new CustomEvent('sql-run', { detail: { connectionId: {{ $connectionId }}, tabId: {{ $activeTab }}, action: 'run' } }))"
                class="inline-flex items-center gap-1 rounded bg-sky-600 px-2.5 py-0.5 font-medium text-white hover:bg-sky-500"
                title="Run (Ctrl+Enter)"
            ><x-icon name="play" class="size-3.5" /> Run</button>
            <button
                x-on:click="window.dispatchEvent(new CustomEvent('sql-run', { detail: { connectionId: {{ $connectionId }}, tabId: {{ $activeTab }}, action: 'explain' } }))"
                class="rounded border border-edge px-2 py-0.5 text-dim hover:bg-raised hover:text-body"
                title="EXPLAIN the first statement"
            >Explain</button>
            <label class="flex items-center gap-1 px-1 text-muted" title="Add LIMIT 1000 to unlimited SELECTs">
                <input type="checkbox" wire:model.live="limitResults">
                Limit
            </label>
            <button
                wire:click="$toggle('showHistory')"
                class="inline-flex items-center rounded border border-edge px-2 py-0.5 {{ $showHistory ? 'bg-raised text-body' : 'text-dim hover:bg-raised hover:text-body' }}"
                title="Query history"
            ><x-icon name="history" class="size-3.5" /></button>
        </div>
    </div>

    {{-- Editors (one CodeMirror per tab; inactive ones stay mounted but hidden) --}}
    <div class="relative min-h-0 flex-1 bg-surface">
        @foreach ($tabs as $tab)
            <div
                wire:key="qeditor-{{ $tab['id'] }}"
                wire:ignore
                x-data="sqlEditor({ connectionId: {{ $connectionId }}, tabId: {{ $tab['id'] }}, initial: @js($tab['sql']) })"
                class="absolute inset-0 overflow-hidden"
                :class="{ invisible: $wire.activeTab !== {{ $tab['id'] }} }"
            >
                <div x-ref="editor" class="h-full"></div>
            </div>
        @endforeach

        <div wire:loading wire:target="run, explain" class="absolute right-2 top-1 z-10 rounded bg-sky-600/90 px-2 py-0.5 text-[0.78rem] text-white">
            Executing…
        </div>

        {{-- History drawer --}}
        @if ($showHistory)
            <div class="absolute inset-y-0 right-0 z-20 flex w-96 flex-col border-l border-edge bg-chrome shadow-xl">
                <div class="flex shrink-0 items-center gap-2 border-b border-edge/60 p-2">
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="historySearch"
                        placeholder="Search history…"
                        class="input-field"
                    >
                    <button wire:click="$set('showHistory', false)" class="rounded px-1.5 text-muted hover:bg-raised hover:text-body">&times;</button>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto p-1">
                    @forelse ($this->history as $entry)
                        <button
                            wire:key="hist-{{ $entry->id }}"
                            wire:click="insertFromHistory({{ $entry->id }})"
                            class="mb-1 block w-full rounded border border-edge/40 px-2 py-1.5 text-left hover:bg-raised"
                            title="Click to insert into the editor"
                        >
                            <span class="block truncate font-mono text-[0.78rem] text-body">{{ mb_substr($entry->query, 0, 200) }}</span>
                            <span class="mt-0.5 block text-[0.7rem] text-muted">
                                {{ $entry->executed_at->format('Y-m-d H:i:s') }} · {{ $entry->duration_ms }} ms · {{ $entry->rows_affected }} row(s){{ $entry->database ? ' · '.$entry->database : '' }}
                            </span>
                        </button>
                    @empty
                        <div class="p-3 text-center text-[0.78rem] text-muted">No queries yet.</div>
                    @endforelse
                </div>
            </div>
        @endif
    </div>

    {{-- Hint bar --}}
    <div class="mb-[5px] flex shrink-0 items-center gap-3 border-t border-edge/60 bg-chrome px-2 py-1.5 text-[0.78rem] text-faint">
        <span><kbd>Ctrl+Enter</kbd> run</span>
        <span><kbd>Ctrl+Shift+Enter</kbd> run selection</span>
        <span><kbd>Ctrl+Space</kbd> autocomplete</span>
        <span class="ml-auto">{{ $activeDatabase ?? 'no database selected' }}</span>
    </div>
</div>
