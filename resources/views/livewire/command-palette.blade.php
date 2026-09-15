<div
    x-data
    x-on:keydown.window="
        if (($event.ctrlKey || $event.metaKey) && $event.key.toLowerCase() === 'p') {
            if ($event.target.closest('[data-command-palette] input')) return;
            $event.preventDefault();
            $wire.openPalette();
        }
    "
>
    @if ($open)
        <div
            data-command-palette
            class="fixed inset-0 z-[70] flex items-start justify-center bg-black/50 pt-[12vh]"
            wire:click="close"
            wire:keydown.escape.window="close"
        >
            <div
                class="w-[min(560px,92vw)] overflow-hidden rounded-lg border border-edge bg-surface shadow-2xl"
                wire:click.stop
                x-data
                x-init="$nextTick(() => $refs.q?.focus())"
                x-on:keydown.arrow-down.prevent="$wire.moveHighlight(1)"
                x-on:keydown.arrow-up.prevent="$wire.moveHighlight(-1)"
                x-on:keydown.enter.prevent="$wire.runHighlighted()"
            >
                <div class="border-b border-edge/60 p-2">
                    <input
                        x-ref="q"
                        type="text"
                        wire:model.live.debounce.100ms="query"
                        placeholder="Jump to table, saved query, or action…"
                        class="input-field border-0 bg-transparent text-sm shadow-none focus:ring-0"
                    >
                </div>
                <div class="max-h-[50vh] overflow-y-auto py-1">
                    @forelse ($items as $index => $item)
                        <button
                            wire:key="palette-{{ $index }}"
                            type="button"
                            wire:click="runItem({{ $index }})"
                            class="flex w-full items-center justify-between gap-3 px-3 py-1.5 text-left text-[0.78rem]
                                {{ $highlight === $index ? 'bg-sky-500/15 text-strong' : 'text-body hover:bg-raised' }}"
                        >
                            <span class="truncate">{{ $item['label'] }}</span>
                            <span class="shrink-0 text-[0.7rem] text-muted">{{ $item['hint'] }}</span>
                        </button>
                    @empty
                        <div class="px-3 py-4 text-center text-[0.78rem] text-muted">No matches.</div>
                    @endforelse
                </div>
                <div class="border-t border-edge/60 px-3 py-1.5 text-[0.7rem] text-faint">
                    ↑↓ navigate · Enter run · Esc close · Ctrl+P
                </div>
            </div>
        </div>
    @endif
</div>
