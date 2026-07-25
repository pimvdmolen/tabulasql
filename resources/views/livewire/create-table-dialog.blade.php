<div>
    @if ($context !== null)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/60" wire:keydown.escape.window="close">
            <div class="flex max-h-[85vh] w-[min(860px,92vw)] flex-col rounded-lg border border-edge bg-surface shadow-xl">
                <div class="flex items-center justify-between border-b border-edge/60 px-4 py-3">
                    <h2 class="text-sm font-semibold text-strong">Create Table in `{{ $context['database'] }}`</h2>
                    <button wire:click="close" class="rounded px-1.5 text-muted hover:bg-raised hover:text-body">&times;</button>
                </div>

                <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 py-4">
                    <label class="block w-64">
                        <span class="mb-1 block text-[0.78rem] text-dim">Table name</span>
                        <input type="text" wire:model="tableName" class="input-field" x-init="$el.focus()">
                    </label>

                    <table class="w-full text-[0.78rem]">
                        <thead class="text-left text-dim">
                            <tr>
                                <th class="py-1 pr-2">Column</th>
                                <th class="py-1 pr-2">Type</th>
                                <th class="px-1 py-1 text-center">NULL</th>
                                <th class="px-1 py-1 text-center">PK</th>
                                <th class="px-1 py-1 text-center">AI</th>
                                <th class="py-1 pr-2">Default</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($columns as $index => $column)
                                <tr wire:key="col-{{ $index }}">
                                    <td class="py-0.5 pr-2"><input type="text" wire:model="columns.{{ $index }}.name" class="input-field"></td>
                                    <td class="py-0.5 pr-2">
                                        <input type="text" wire:model="columns.{{ $index }}.type" list="column-types" class="input-field w-36">
                                    </td>
                                    <td class="px-1 text-center"><input type="checkbox" wire:model="columns.{{ $index }}.nullable" class="rounded border-edge bg-raised"></td>
                                    <td class="px-1 text-center"><input type="checkbox" wire:model="columns.{{ $index }}.pk" class="rounded border-edge bg-raised"></td>
                                    <td class="px-1 text-center"><input type="checkbox" wire:model="columns.{{ $index }}.ai" class="rounded border-edge bg-raised"></td>
                                    <td class="py-0.5 pr-2"><input type="text" wire:model="columns.{{ $index }}.default" class="input-field" placeholder="(none)"></td>
                                    <td><button wire:click="removeColumn({{ $index }})" class="px-1 text-muted hover:text-red-600 dark:hover:text-red-400">&times;</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <datalist id="column-types">
                        @foreach (\App\Livewire\CreateTableDialog::TYPES as $type)
                            <option value="{{ $type }}"></option>
                        @endforeach
                    </datalist>

                    <button wire:click="addColumn" class="text-[0.78rem] text-sky-600 dark:text-sky-400 hover:underline">+ Add column</button>

                    @if ($error !== null)
                        <div class="rounded border border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-950/40 px-3 py-2 text-[0.78rem] text-red-700 dark:text-red-300">{{ $error }}</div>
                    @endif

                    @if (trim($tableName) !== '')
                        <pre class="overflow-x-auto rounded border border-grid bg-chrome p-2 font-mono text-xs text-body select-text">{{ $this->sql() }}</pre>
                    @endif
                </div>

                <div class="flex justify-end gap-2 border-t border-edge/60 px-4 py-3">
                    <button wire:click="close" class="rounded border border-edge px-3 py-1.5 text-[0.78rem] text-body hover:bg-raised">Cancel</button>
                    <button wire:click="create" class="rounded bg-sky-600 px-4 py-1.5 text-[0.78rem] font-medium text-white hover:bg-sky-500">Create Table</button>
                </div>
            </div>
        </div>
    @endif
</div>
