<div>
    @if ($connectionId !== null)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/60" wire:keydown.escape.window="close">
            <div class="w-[min(420px,92vw)] rounded-lg border border-edge bg-surface shadow-xl">
                <div class="flex items-center justify-between border-b border-edge/60 px-4 py-3">
                    <h2 class="text-sm font-semibold text-strong">Create Database</h2>
                    <button wire:click="close" class="rounded px-1.5 text-muted hover:bg-raised hover:text-body">&times;</button>
                </div>

                <div class="space-y-3 px-4 py-4">
                    <label class="block">
                        <span class="mb-1 block text-[0.78rem] text-dim">Database name</span>
                        <input type="text" wire:model="databaseName" wire:keydown.enter="create" class="input-field" x-init="$el.focus()">
                    </label>

                    <div class="flex gap-3">
                        <label class="block flex-1">
                            <span class="mb-1 block text-[0.78rem] text-dim">Charset</span>
                            <select wire:model.live="charset" class="input-field">
                                @foreach (\App\Livewire\CreateDatabaseDialog::CHARSETS as $option)
                                    <option value="{{ $option }}">{{ $option }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="block flex-1">
                            <span class="mb-1 block text-[0.78rem] text-dim">Collation</span>
                            <select wire:model="collation" class="input-field">
                                @foreach (\App\Livewire\CreateDatabaseDialog::COLLATIONS[$charset] ?? [] as $option)
                                    <option value="{{ $option }}">{{ $option }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    @if ($error !== null)
                        <div class="rounded border border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-950/40 px-3 py-2 text-[0.78rem] text-red-700 dark:text-red-300">{{ $error }}</div>
                    @endif

                    @if (trim($databaseName) !== '')
                        <pre class="overflow-x-auto rounded border border-grid bg-chrome p-2 font-mono text-xs text-body select-text">{{ $this->sql() }}</pre>
                    @endif
                </div>

                <div class="flex justify-end gap-2 border-t border-edge/60 px-4 py-3">
                    <button wire:click="close" class="rounded border border-edge px-3 py-1.5 text-[0.78rem] text-body hover:bg-raised">Cancel</button>
                    <button wire:click="create" class="rounded bg-sky-600 px-4 py-1.5 text-[0.78rem] font-medium text-white hover:bg-sky-500">Create Database</button>
                </div>
            </div>
        </div>
    @endif
</div>
