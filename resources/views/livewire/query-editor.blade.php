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
            <button
                x-on:click="window.dispatchEvent(new CustomEvent('sql-run', { detail: { connectionId: {{ $connectionId }}, tabId: {{ $activeTab }}, action: 'format' } }))"
                class="rounded border border-edge px-2 py-0.5 text-dim hover:bg-raised hover:text-body"
                title="Format SQL"
            >Format</button>
            <button
                x-on:click="
                    window.dispatchEvent(new CustomEvent('sql-run', {
                        detail: { connectionId: {{ $connectionId }}, tabId: {{ $activeTab }}, action: 'save' }
                    }))
                "
                class="rounded border border-edge px-2 py-0.5 text-dim hover:bg-raised hover:text-body"
                title="Save query"
            >Save</button>
            <button
                wire:click="$set('showAiPrompt', true); $set('showHistory', false); $set('showSavedQueries', false)"
                class="rounded border border-edge px-2 py-0.5 {{ $showAiPrompt ? 'bg-raised text-body' : 'text-dim hover:bg-raised hover:text-body' }}"
                title="Ask AI to write SQL"
            >AI</button>
            <label class="flex items-center gap-1 px-1 text-muted" title="Add LIMIT 1000 to unlimited SELECTs">
                <input type="checkbox" wire:model.live="limitResults">
                Limit
            </label>
            <button
                wire:click="$set('showSavedQueries', ! $wire.showSavedQueries); $set('showHistory', false); $set('showAiPrompt', false)"
                class="inline-flex items-center rounded border border-edge px-2 py-0.5 {{ $showSavedQueries ? 'bg-raised text-body' : 'text-dim hover:bg-raised hover:text-body' }}"
                title="Saved queries"
            ><x-icon name="file" class="size-3.5" /></button>
            <button
                wire:click="$set('showHistory', ! $wire.showHistory); $set('showSavedQueries', false); $set('showAiPrompt', false)"
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

        <div wire:loading wire:target="run, explain, generateAiSql, formatSql" class="absolute right-2 top-1 z-10 rounded bg-sky-600/90 px-2 py-0.5 text-[0.78rem] text-white">
            Working…
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

        {{-- Saved queries drawer --}}
        @if ($showSavedQueries)
            <div class="absolute inset-y-0 right-0 z-20 flex w-96 flex-col border-l border-edge bg-chrome shadow-xl">
                <div class="flex shrink-0 items-center gap-2 border-b border-edge/60 p-2">
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="savedSearch"
                        placeholder="Search saved…"
                        class="input-field"
                    >
                    <button wire:click="$set('showSavedQueries', false)" class="rounded px-1.5 text-muted hover:bg-raised hover:text-body">&times;</button>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto p-1">
                    @forelse ($this->savedQueries as $entry)
                        <div wire:key="saved-{{ $entry->id }}" class="group mb-1 flex items-start gap-1 rounded border border-edge/40 px-2 py-1.5 hover:bg-raised">
                            <button wire:click="insertSavedQuery({{ $entry->id }})" class="min-w-0 flex-1 text-left">
                                <span class="block truncate text-[0.78rem] font-medium text-body">{{ $entry->title }}</span>
                                <span class="mt-0.5 block truncate font-mono text-[0.7rem] text-muted">{{ mb_substr($entry->sql, 0, 160) }}</span>
                            </button>
                            <button wire:click="deleteSavedQuery({{ $entry->id }})" class="rounded px-1 text-muted opacity-0 hover:bg-overlay hover:text-red-500 group-hover:opacity-100" title="Delete">&times;</button>
                        </div>
                    @empty
                        <div class="p-3 text-center text-[0.78rem] text-muted">No saved queries yet. Use Save in the toolbar.</div>
                    @endforelse
                </div>
            </div>
        @endif
    </div>

    @if ($showAiPrompt)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            wire:click="$set('showAiPrompt', false)"
            wire:keydown.escape.window="$set('showAiPrompt', false)"
        >
            <div
                class="flex h-[min(72vh,620px)] w-[min(720px,96vw)] flex-col rounded-lg border border-edge bg-surface shadow-xl"
                wire:click.stop
            >
                <div class="flex shrink-0 items-center justify-between border-b border-edge/60 px-4 py-3">
                    <div>
                        <h3 class="text-sm font-semibold text-strong">Ask AI for SQL</h3>
                        @if ($activeDatabase)
                            <p class="mt-0.5 text-[0.72rem] text-dim">Using schema from <span class="font-mono text-sky-600 dark:text-sky-400">{{ $activeDatabase }}</span></p>
                        @else
                            <p class="mt-0.5 text-[0.72rem] text-amber-700 dark:text-amber-400">No database expanded — expand one in the sidebar first</p>
                        @endif
                    </div>
                    <button wire:click="$set('showAiPrompt', false)" class="rounded px-1.5 text-muted hover:bg-raised hover:text-body">&times;</button>
                </div>
                <div class="flex min-h-0 flex-1 flex-col gap-3 p-4">
                    @if (! $activeDatabase)
                        <div class="shrink-0 rounded border border-amber-300 bg-amber-50 p-2 text-[0.78rem] text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                            Expand a database in the left sidebar first. Only that database’s tables and columns are sent to the AI — without it, Tabula will not guess table names.
                        </div>
                    @endif
                    <p class="shrink-0 text-[0.78rem] text-dim">Describe the data you want in plain language. Tabula sends the active schema summary plus your prompt to the configured provider. Review the SQL before running it.</p>
                    <button
                        type="button"
                        wire:click="$dispatch('open-ai-settings')"
                        class="shrink-0 self-start text-[0.72rem] text-sky-600 hover:underline dark:text-sky-400"
                    >Open AI settings (API key)…</button>
                    <textarea
                        rows="10"
                        class="input-field min-h-0 w-full flex-1 resize-none font-sans"
                        placeholder="e.g. all users from 2025 or older who placed at least 2 orders"
                        @disabled(! $activeDatabase)
                        x-data
                        x-init="
                            const saved = window.__tabulaCookie?.get('tabula_ai_prompt') || '';
                            if (saved !== '') {
                                $el.value = saved;
                                $wire.set('aiPrompt', saved);
                            } else if (@js($aiPrompt) !== '') {
                                $el.value = @js($aiPrompt);
                            }
                        "
                        x-on:input="
                            window.__tabulaCookie?.set('tabula_ai_prompt', $el.value);
                            $wire.set('aiPrompt', $el.value);
                        "
                    >{{ $aiPrompt }}</textarea>
                    @if ($aiError)
                        <div class="shrink-0 break-words rounded border border-red-300 bg-red-50 p-2 text-[0.78rem] text-red-700 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300">{{ $aiError }}</div>
                    @endif
                </div>
                <div class="flex shrink-0 justify-end gap-2 border-t border-edge/60 px-4 py-3">
                    <button wire:click="$set('showAiPrompt', false)" class="rounded border border-edge px-3 py-1 text-[0.78rem] text-body hover:bg-raised">Cancel</button>
                    <button
                        wire:click="generateAiSql"
                        class="rounded bg-sky-600 px-3 py-1 text-[0.78rem] font-medium text-white hover:bg-sky-500 disabled:cursor-not-allowed disabled:opacity-50"
                        @disabled(! $activeDatabase)
                    >Generate</button>
                </div>
            </div>
        </div>
    @endif

    @if ($showSaveDialog)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" wire:click="$set('showSaveDialog', false)">
            <div class="w-[min(420px,92vw)] rounded-lg border border-edge bg-surface p-4 shadow-xl" wire:click.stop>
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-strong">Save query</h3>
                    <button wire:click="$set('showSaveDialog', false)" class="rounded px-1.5 text-muted hover:bg-raised hover:text-body">&times;</button>
                </div>
                <label class="mb-3 block">
                    <span class="mb-1 block text-[0.78rem] text-dim">Title</span>
                    <input type="text" wire:model="saveTitle" class="input-field" placeholder="Users with 2+ orders" wire:keydown.enter="saveCurrentQuery">
                </label>
                <div class="flex justify-end gap-2">
                    <button wire:click="$set('showSaveDialog', false)" class="rounded border border-edge px-3 py-1 text-[0.78rem] hover:bg-raised">Cancel</button>
                    <button wire:click="saveCurrentQuery" class="rounded bg-sky-600 px-3 py-1 text-[0.78rem] text-white hover:bg-sky-500">Save</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Hint bar --}}
    <div class="mb-[5px] flex shrink-0 items-center gap-3 border-t border-edge/60 bg-chrome px-2 py-1.5 text-[0.78rem] text-faint">
        <span><kbd>Ctrl+Enter</kbd> run</span>
        <span><kbd>Ctrl+Shift+Enter</kbd> run selection</span>
        <span><kbd>Ctrl+P</kbd> command palette</span>
        <span><kbd>Ctrl+Space</kbd> autocomplete</span>
        <span class="ml-auto">{{ $activeDatabase ?? 'no database selected' }}</span>
    </div>
</div>
