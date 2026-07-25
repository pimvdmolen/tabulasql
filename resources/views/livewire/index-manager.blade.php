<div>
    @if ($context !== null)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/60" wire:keydown.escape.window="close">
            <div class="flex max-h-[85vh] w-[min(760px,92vw)] flex-col rounded-lg border border-edge bg-surface shadow-xl">
                <div class="flex items-center justify-between border-b border-edge/60 px-4 py-3">
                    <h2 class="text-sm font-semibold text-strong">Indexes on `{{ $context['database'] }}`.`{{ $context['table'] }}`</h2>
                    <button wire:click="close" class="rounded px-1.5 text-muted hover:bg-raised hover:text-body">&times;</button>
                </div>

                <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 py-4">
                    <div class="overflow-x-auto">
                        <table class="w-full border-collapse font-mono text-[0.78rem]">
                            <thead>
                                <tr class="bg-chrome text-left text-dim">
                                    <th class="border border-edge/60 px-2 py-1">Name</th>
                                    <th class="border border-edge/60 px-2 py-1">Columns</th>
                                    <th class="border border-edge/60 px-2 py-1">Type</th>
                                    <th class="border border-edge/60 px-2 py-1">Unique</th>
                                    <th class="border border-edge/60 px-2 py-1 w-16"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($indexes as $index)
                                    <tr class="text-body" wire:key="idx-{{ $index['name'] }}">
                                        <td class="border border-grid px-2 py-0.5">{{ $index['name'] }}</td>
                                        <td class="border border-grid px-2 py-0.5">{{ implode(', ', $index['columns']) }}</td>
                                        <td class="border border-grid px-2 py-0.5">{{ $index['type'] }}</td>
                                        <td class="border border-grid px-2 py-0.5">{{ $index['unique'] ? 'YES' : 'NO' }}</td>
                                        <td class="border border-grid px-2 py-0.5 text-center">
                                            @if ($confirmingDrop === $index['name'])
                                                <button wire:click="dropIndex(@js($index['name']))" class="text-red-600 dark:text-red-400 hover:underline">confirm</button>
                                            @else
                                                <button wire:click="$set('confirmingDrop', @js($index['name']))" class="text-muted hover:text-red-600 dark:hover:text-red-400">drop</button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="border border-grid px-2 py-1 text-faint">No indexes.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="rounded border border-edge/60 p-3">
                        <h3 class="mb-2 text-[0.78rem] font-semibold uppercase tracking-wide text-dim">Add index</h3>
                        <div class="flex flex-wrap items-end gap-3">
                            <label class="block">
                                <span class="mb-1 block text-[0.78rem] text-dim">Name <span class="text-faint">(optional)</span></span>
                                <input type="text" wire:model="newName" class="input-field w-40">
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-[0.78rem] text-dim">Columns</span>
                                <select wire:model="newColumns" multiple size="3" class="input-field w-44">
                                    @foreach ($availableColumns as $column)
                                        <option value="{{ $column }}">{{ $column }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="flex items-center gap-2 pb-1 text-sm text-body">
                                <input type="checkbox" wire:model="newUnique" class="rounded border-edge bg-raised">
                                Unique
                            </label>
                            <button wire:click="addIndex" class="mb-0.5 rounded bg-sky-600 px-3 py-1.5 text-[0.78rem] font-medium text-white hover:bg-sky-500">Add</button>
                        </div>
                    </div>

                    @if ($error !== null)
                        <div class="rounded border border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-950/40 px-3 py-2 text-[0.78rem] text-red-700 dark:text-red-300">{{ $error }}</div>
                    @endif
                </div>

                <div class="flex justify-end border-t border-edge/60 px-4 py-3">
                    <button wire:click="close" class="rounded border border-edge px-3 py-1.5 text-[0.78rem] text-body hover:bg-raised">Close</button>
                </div>
            </div>
        </div>
    @endif
</div>
