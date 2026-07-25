<div
    class="flex h-full min-h-0 flex-col bg-surface"
    x-data="{ viewer: null }"
    x-on:keydown.window="
        if ($el.offsetParent === null || $event.target.closest('.cm-editor, input, textarea, select')) return;
        if ($event.key === 'F5' || ($event.ctrlKey && $event.key.toLowerCase() === 'r')) { $event.preventDefault(); $wire.refresh(); }
        else if ($event.ctrlKey && $event.key.toLowerCase() === 'f') { $event.preventDefault(); $wire.openFilterDialog(); }
        else if ($event.key === 'F11') { $event.preventDefault(); $wire.$set('activeTab', 'data'); }
    "
>
    {{-- Result tabs --}}
    <div class="mt-[5px] flex h-8 shrink-0 items-center gap-px border-b border-edge/60 bg-chrome px-1">
        @foreach (['messages' => 'Messages', 'data' => 'Table Data', 'info' => 'Info'] as $key => $label)
            <button
                wire:click="$set('activeTab', '{{ $key }}')"
                class="h-7 rounded-t border-t-2 px-3 text-[0.78rem]
                    {{ $activeTab === $key
                        ? 'border-sky-500 bg-surface text-body'
                        : 'border-transparent text-muted hover:text-body' }}"
            >{{ $label }}</button>
        @endforeach
        @if ($table !== null)
            <span class="ml-2 truncate text-[0.78rem] text-faint">{{ $database }}.{{ $table }}</span>
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
