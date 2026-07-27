@php
    $tabLabels = ['messages' => 'Messages', 'data' => 'Table Data', 'info' => 'Info'];
@endphp
<div
    class="flex h-full min-h-0 flex-col bg-surface"
    x-data="{ viewer: null }"
    x-on:keydown.window="
        if ($el.offsetParent === null || $event.target.closest('.cm-editor, input, textarea, select')) return;
        if ($event.key === 'F5' || ($event.ctrlKey && $event.key.toLowerCase() === 'r')) { $event.preventDefault(); $wire.refresh(); }
        else if ($event.ctrlKey && $event.key.toLowerCase() === 'f') { $event.preventDefault(); $wire.openFilterDialog(); }
        else if ($event.key === 'F11') { $event.preventDefault(); $wire.$set('activeTab', 'data'); }
        else if ($event.ctrlKey && $event.key.toLowerCase() === 'a' && $wire.activeTab === 'data') { $event.preventDefault(); $wire.selectAllRows(); }
        else if (($event.key === 'Delete' || $event.key === 'Backspace') && $wire.activeTab === 'data') { $event.preventDefault(); $wire.confirmDeleteRows(); }
    "
>
    {{-- Result tabs; square edges (no rounding), reorder with ◀ ▶ --}}
    <div class="mt-[5px] flex h-8 shrink-0 items-center gap-px border-b border-edge/60 bg-chrome px-1">
        @foreach ($tabOrder as $key)
            <div class="group/tab relative flex h-7 items-stretch">
                <button
                    wire:click="$set('activeTab', '{{ $key }}')"
                    class="border-t-2 px-3 text-[0.78rem]
                        {{ $activeTab === $key
                            ? 'border-sky-500 bg-surface text-body'
                            : 'border-transparent text-muted hover:text-body' }}"
                >{{ $tabLabels[$key] ?? $key }}</button>
                <span class="absolute -bottom-5 left-1/2 z-20 hidden -translate-x-1/2 items-center gap-0.5 rounded border border-edge bg-surface p-0.5 shadow group-hover/tab:flex">
                    <button type="button" wire:click="moveTab('{{ $key }}', 'left')" class="rounded p-0.5 text-muted hover:bg-raised hover:text-body" title="Move left">
                        <x-icon name="chevron-left" class="size-3" />
                    </button>
                    <button type="button" wire:click="moveTab('{{ $key }}', 'right')" class="rounded p-0.5 text-muted hover:bg-raised hover:text-body" title="Move right">
                        <x-icon name="chevron-right" class="size-3" />
                    </button>
                </span>
            </div>
        @endforeach
        @if ($table !== null)
            <span class="ml-2 truncate text-[0.78rem] text-faint">{{ $database }}.{{ $table }}</span>
        @endif
        @if ($safeMode)
            <span class="ml-2 inline-flex items-center gap-1 rounded border border-amber-500/40 bg-amber-500/10 px-1.5 text-[0.7rem] text-amber-700 dark:text-amber-400" title="Safe mode: writes require confirmation">
                <x-icon name="lock" class="size-3" /> Safe
            </span>
        @endif
        <div wire:loading class="ml-auto pr-2 text-[0.78rem] text-sky-600 dark:text-sky-400">Loading…</div>
    </div>

    <div class="min-h-0 flex-1 overflow-hidden">
        @if ($activeTab === 'messages')
            @include('livewire.partials.messages-tab')
        @elseif ($activeTab === 'data')
            @include('livewire.partials.data-tab')
        @elseif ($activeTab === 'info')
            @include('livewire.partials.info-tab')
        @endif
    </div>

    @include('livewire.partials.record-dialog')

    @if ($pendingSafeAction !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" wire:click="cancelSafeAction">
            <div class="flex max-h-[80vh] w-[min(640px,92vw)] flex-col rounded-lg border border-edge bg-surface shadow-xl" wire:click.stop>
                <div class="flex items-center justify-between border-b border-edge/60 px-4 py-2">
                    <div class="flex items-center gap-2 text-sm font-semibold text-strong">
                        <x-icon name="lock" class="size-4 text-amber-500" />
                        {{ $pendingSafeAction['title'] }}
                    </div>
                    <button wire:click="cancelSafeAction" class="rounded px-1.5 text-muted hover:bg-raised hover:text-body">&times;</button>
                </div>
                <p class="px-4 pt-3 text-[0.78rem] text-dim">Review the SQL that will be sent to the server:</p>
                <pre class="mx-4 my-2 max-h-64 overflow-auto rounded border border-edge bg-chrome p-3 font-mono text-[0.72rem] text-body whitespace-pre-wrap break-all">{{ $pendingSafeAction['sql'] }}</pre>
                <div class="flex justify-end gap-2 border-t border-edge/60 px-4 py-3">
                    <button wire:click="cancelSafeAction" class="rounded border border-edge px-3 py-1 text-[0.78rem] text-body hover:bg-raised">Cancel</button>
                    <button wire:click="confirmSafeAction" class="rounded bg-amber-600 px-3 py-1 text-[0.78rem] text-white hover:bg-amber-500">Run</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Cell value viewer --}}
    <template x-teleport="body">
        <div
            x-show="viewer !== null"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60"
            @keydown.escape.window="viewer = null"
            @click.self="viewer = null"
        >
            <div class="flex max-h-[80vh] w-[min(640px,92vw)] flex-col rounded-lg border border-edge bg-surface shadow-xl">
                <div class="flex items-center justify-between border-b border-edge/60 px-4 py-2">
                    <span class="text-[0.78rem] font-semibold text-body" x-text="viewer?.title"></span>
                    <button @click="viewer = null" class="rounded px-1.5 text-muted hover:bg-raised hover:text-body">&times;</button>
                </div>
                <pre class="min-h-0 flex-1 overflow-auto whitespace-pre-wrap break-all p-4 font-mono text-[0.78rem] text-body select-text" x-text="viewer?.content"></pre>
                <div class="border-t border-edge/60 px-4 py-2 text-[0.78rem] text-muted" x-show="viewer?.note" x-text="viewer?.note"></div>
            </div>
        </div>
    </template>
</div>
