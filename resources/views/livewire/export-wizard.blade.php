<div>
    @if ($context !== null)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/60" wire:keydown.escape.window="close">
            <div class="flex max-h-[85vh] w-[min(560px,92vw)] flex-col rounded-lg border border-edge bg-surface shadow-xl">
                <div class="flex items-center justify-between border-b border-edge/60 px-4 py-3">
                    <h2 class="text-sm font-semibold text-strong">Export `{{ $context['database'] }}` as SQL</h2>
                    <button wire:click="close" class="rounded px-1.5 text-muted hover:bg-raised hover:text-body">&times;</button>
                </div>

                <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 py-4">
                    <div class="flex items-center justify-between text-[0.78rem] text-muted">
                        <span>{{ count($selected) }} of {{ count($objects) }} objects selected</span>
                        <span class="flex gap-2">
                            <button wire:click="$set('selected', {{ json_encode(array_column($objects, 'name')) }})" class="hover:text-body">all</button>
                            <button wire:click="$set('selected', [])" class="hover:text-body">none</button>
                        </span>
                    </div>
                    <div class="max-h-44 space-y-0.5 overflow-y-auto rounded border border-edge/60 p-2">
                        @foreach ($objects as $object)
                            <label class="flex items-center gap-2 rounded px-1 py-0.5 text-sm text-body hover:bg-raised" wire:key="exp-{{ $object['name'] }}">
                                <input type="checkbox" wire:model.live="selected" value="{{ $object['name'] }}" class="rounded border-edge bg-raised">
                                <span class="{{ $object['type'] === 'view' ? 'text-purple-600/80 dark:text-purple-400/80' : 'text-sky-600/80 dark:text-sky-400/80' }}">{{ $object['type'] === 'view' ? '👁' : '▦' }}</span>
                                {{ $object['name'] }}
                            </label>
                        @endforeach
                    </div>

                    <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-body">
                        <label class="flex items-center gap-2"><input type="checkbox" wire:model="withStructure" class="rounded border-edge bg-raised"> Structure</label>
                        <label class="flex items-center gap-2"><input type="checkbox" wire:model="withData" class="rounded border-edge bg-raised"> Data</label>
                        <label class="flex items-center gap-2"><input type="checkbox" wire:model="dropIfExists" class="rounded border-edge bg-raised"> DROP IF EXISTS</label>
                        <label class="flex items-center gap-2"><input type="checkbox" wire:model="createDatabase" class="rounded border-edge bg-raised"> CREATE DATABASE + USE</label>
                    </div>

                    @if ($error !== null)
                        <div class="rounded border border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-950/40 px-3 py-2 text-[0.78rem] text-red-700 dark:text-red-300">{{ $error }}</div>
                    @endif

                    <div wire:stream="export-progress" class="max-h-40 overflow-y-auto rounded border border-grid bg-chrome p-2 font-mono text-xs text-dim empty:hidden"></div>
                </div>

                <div class="flex justify-end gap-2 border-t border-edge/60 px-4 py-3">
                    <button wire:click="close" class="rounded border border-edge px-3 py-1.5 text-[0.78rem] text-body hover:bg-raised">Cancel</button>
                    <button
                        wire:click="runExport"
                        wire:loading.attr="disabled"
                        class="rounded bg-sky-600 px-4 py-1.5 text-[0.78rem] font-medium text-white hover:bg-sky-500 disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="runExport">Export</span>
                        <span wire:loading wire:target="runExport">Exporting…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
