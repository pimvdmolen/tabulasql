<div>
    @if ($context !== null)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/60" wire:keydown.escape.window="close">
            <div class="flex max-h-[85vh] w-[min(920px,92vw)] flex-col rounded-lg border border-edge bg-surface shadow-xl">
                <div class="flex items-center justify-between border-b border-edge/60 px-4 py-3">
                    <h2 class="text-sm font-semibold text-strong">Foreign Keys on `{{ $context['database'] }}`.`{{ $context['table'] }}`</h2>
                    <button wire:click="close" class="rounded px-1.5 text-muted hover:bg-raised hover:text-body">&times;</button>
                </div>

                <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 py-4">
                    <div class="overflow-x-auto">
                        <table class="w-full border-collapse font-mono text-[0.78rem]">
                            <thead>
                                <tr class="bg-chrome text-left text-dim">
                                    <th class="border border-edge/60 px-2 py-1">Constraint</th>
                                    <th class="border border-edge/60 px-2 py-1">Column</th>
                                    <th class="border border-edge/60 px-2 py-1">References</th>
                                    <th class="border border-edge/60 px-2 py-1">On Delete</th>
                                    <th class="border border-edge/60 px-2 py-1">On Update</th>
                                    <th class="border border-edge/60 w-14 px-2 py-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($constraints as $constraint)
                                    <tr class="text-body" wire:key="fk-{{ $constraint['name'] }}-{{ $constraint['column'] }}">
                                        <td class="border border-grid px-2 py-0.5">{{ $constraint['name'] }}</td>
                                        <td class="border border-grid px-2 py-0.5">{{ $constraint['column'] }}</td>
                                        <td class="border border-grid px-2 py-0.5">{{ $constraint['ref_table'] }}.{{ $constraint['ref_column'] }}</td>
                                        <td class="border border-grid px-2 py-0.5">{{ $constraint['on_delete'] }}</td>
                                        <td class="border border-grid px-2 py-0.5">{{ $constraint['on_update'] }}</td>
                                        <td class="border border-grid px-2 py-0.5 text-center">
                                            <button wire:click="dropForeignKey(@js($constraint['name']))" wire:confirm="Drop foreign key {{ $constraint['name'] }}?" class="text-muted hover:text-red-600 dark:hover:text-red-400">drop</button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="border border-grid px-2 py-1 text-faint">No foreign keys.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="rounded border border-edge/60 p-3">
                        <h3 class="mb-2 text-[0.78rem] font-semibold uppercase tracking-wide text-dim">Add foreign key</h3>
                        <div class="flex flex-wrap items-end gap-3">
                            <label class="block">
                                <span class="mb-1 block text-[0.78rem] text-dim">Column</span>
                                <select wire:model="newColumn" class="input-field w-36">
                                    <option value="">Choose…</option>
                                    @foreach ($availableColumns as $column)
                                        <option value="{{ $column }}">{{ $column }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-[0.78rem] text-dim">References table</span>
                                <select wire:model.live="newRefTable" class="input-field w-36">
                                    <option value="">Choose…</option>
                                    @foreach ($availableTables as $table)
                                        <option value="{{ $table }}">{{ $table }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-[0.78rem] text-dim">Column</span>
                                <select wire:model="newRefColumn" class="input-field w-32" @disabled($refColumns === [])>
                                    <option value="">Choose…</option>
                                    @foreach ($refColumns as $column)
                                        <option value="{{ $column }}">{{ $column }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-[0.78rem] text-dim">On delete</span>
                                <select wire:model="onDelete" class="input-field w-28">
                                    @foreach (\App\Livewire\ForeignKeyManager::RULES as $rule)
                                        <option value="{{ $rule }}">{{ $rule }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-[0.78rem] text-dim">On update</span>
                                <select wire:model="onUpdate" class="input-field w-28">
                                    @foreach (\App\Livewire\ForeignKeyManager::RULES as $rule)
                                        <option value="{{ $rule }}">{{ $rule }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <button wire:click="addForeignKey" class="mb-0.5 rounded bg-sky-600 px-3 py-1.5 text-[0.78rem] font-medium text-white hover:bg-sky-500">Add</button>
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
