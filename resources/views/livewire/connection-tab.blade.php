<div class="flex h-full min-w-0">
    @if (! $connected)
        <div class="flex h-full w-full items-center justify-center">
            <div class="max-w-md rounded border border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-950/40 p-4 text-center">
                <div class="mb-2 text-sm font-semibold text-red-700 dark:text-red-300">Could not connect</div>
                <p class="mb-3 text-[0.78rem] text-red-800/80 dark:text-red-800 dark:text-red-200/80">{{ $connectionError }}</p>
                <button
                    wire:click="retry"
                    wire:loading.attr="disabled"
                    class="rounded border border-red-400 dark:border-red-700 px-3 py-1 text-[0.78rem] text-red-800 dark:text-red-200 hover:bg-red-100 dark:hover:bg-red-900/50"
                >
                    <span wire:loading.remove wire:target="retry">Retry</span>
                    <span wire:loading wire:target="retry">Connecting…</span>
                </button>
            </div>
        </div>
    @else
        {{-- Editor + results --}}
        <div class="flex min-w-0 flex-1 flex-col">
            <div
                x-data="splitter({ axis: 'y', initial: 260, min: 100, max: 800, key: 'query-editor' })"
                class="relative flex shrink-0 flex-col border-b border-edge/60"
                :style="`height: ${size}px`"
            >
                <div class="min-h-0 flex-1">
                    <livewire:query-editor :connection-id="$connectionId" :key="'editor-'.$connectionId" />
                </div>
                <div x-bind="handle" class="absolute inset-x-0 -bottom-0.5 z-10 h-1 cursor-row-resize hover:bg-sky-500/50"></div>
            </div>

            <div class="min-h-0 flex-1">
                <livewire:results-panel :connection-id="$connectionId" :key="'results-'.$connectionId" />
            </div>

            {{-- Status strip --}}
            @php $tunnel = $this->tunnelStatus(); @endphp
            <div class="flex h-5 shrink-0 items-center gap-3 border-t border-edge/60 bg-chrome px-2 text-xs text-muted" @if ($tunnel !== null) wire:poll.15s @endif>
                @if ($serverVersion)
                    <span>Server: {{ $serverVersion }}</span>
                @endif
                @if ($tunnel !== null)
                    <span class="flex items-center gap-1" title="SSH tunnel via local port {{ $tunnel['port'] }}">
                        <span class="size-1.5 rounded-full {{ $tunnel['alive'] ? 'bg-emerald-500' : 'bg-red-500' }}"></span>
                        SSH tunnel {{ $tunnel['alive'] ? 'up' : 'down' }} (:{{ $tunnel['port'] }})
                    </span>
                @endif
            </div>
        </div>
    @endif
</div>
