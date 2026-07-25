<div>
    @if ($context !== null)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/60" wire:keydown.escape.window="close">
            <div class="flex max-h-[85vh] w-[min(520px,92vw)] flex-col rounded-lg border border-edge bg-surface shadow-xl">
                <div class="flex items-center justify-between border-b border-edge/60 px-4 py-3">
                    <h2 class="text-sm font-semibold text-strong">
                        Import SQL {{ $context['database'] !== null ? 'into `'.$context['database'].'`' : '' }}
                    </h2>
                    <button wire:click="close" class="rounded px-1.5 text-muted hover:bg-raised hover:text-body">&times;</button>
                </div>

                <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 py-4">
                    <label class="block">
                        <span class="mb-1 block text-[0.78rem] text-dim">.sql file</span>
                        <input type="file" wire:model="upload" accept=".sql"
                            class="w-full text-[0.78rem] text-dim file:mr-3 file:rounded file:border file:border-edge file:bg-raised file:px-3 file:py-1.5 file:text-[0.78rem] file:text-body">
                    </label>
                    <div wire:loading wire:target="upload" class="text-[0.78rem] text-sky-600 dark:text-sky-400">Uploading…</div>

                    @if ($error !== null)
                        <div class="rounded border border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-950/40 px-3 py-2 text-[0.78rem] text-red-700 dark:text-red-300">{{ $error }}</div>
                    @endif

                    <div wire:stream="import-progress" class="max-h-40 overflow-y-auto rounded border border-grid bg-chrome p-2 font-mono text-xs text-dim empty:hidden"></div>

                    @if ($summary !== null)
                        <div class="rounded border px-3 py-2 text-[0.78rem] {{ $summary['failed'] > 0 ? 'border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-300' : 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300' }}">
                            {{ $summary['executed'] }}/{{ $summary['statements'] }} statement(s) executed,
                            {{ $summary['failed'] }} failed, {{ number_format($summary['duration_ms']) }} ms.
                            @if ($summary['failed'] > 0)
                                <div class="mt-1">See the Messages tab for details.</div>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="flex justify-end gap-2 border-t border-edge/60 px-4 py-3">
                    <button wire:click="close" class="rounded border border-edge px-3 py-1.5 text-[0.78rem] text-body hover:bg-raised">
                        {{ $summary !== null ? 'Close' : 'Cancel' }}
                    </button>
                    <button
                        wire:click="runImport"
                        wire:loading.attr="disabled"
                        class="rounded bg-sky-600 px-4 py-1.5 text-[0.78rem] font-medium text-white hover:bg-sky-500 disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="runImport">Import</span>
                        <span wire:loading wire:target="runImport">Importing…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
