@if ($showInsertDialog)
    @php
        $columns = array_filter($this->tableInfo['columns'] ?? [], fn ($column) => ! str_contains($column['extra'], 'auto_increment'));
    @endphp
    <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/60" wire:keydown.escape.window="closeInsertDialog">
        <div class="flex max-h-[85vh] w-[600px] flex-col rounded-lg border border-edge bg-surface shadow-xl">
            <div class="flex items-center justify-between border-b border-edge/60 px-4 py-3">
                <h2 class="text-sm font-semibold text-strong">Insert Row into `{{ $table }}`</h2>
                <button wire:click="closeInsertDialog" class="rounded px-1.5 text-muted hover:bg-raised hover:text-body">&times;</button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-4 py-4">
                <div class="grid grid-cols-2 gap-x-4 gap-y-3">
                    @foreach ($columns as $column)
                        @php [$inputType, $options] = $this->inputTypeFor($column['type']); @endphp
                        <label class="block">
                            <span class="mb-1 flex items-center gap-1 text-[0.78rem] text-dim">
                                @if ($column['key'] === 'PRI')🔑@endif
                                {{ $column['name'] }}
                                @if (! $column['nullable'])<span class="text-red-500">*</span>@endif
                            </span>
                            @if ($inputType === 'enum')
                                <select wire:model="insertValues.{{ $column['name'] }}" class="input-field">
                                    <option value=""></option>
                                    @foreach ($options as $option)
                                        <option value="{{ $option }}">{{ $option }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input
                                    type="{{ $inputType }}"
                                    wire:model="insertValues.{{ $column['name'] }}"
                                    wire:keydown.enter="saveInsert"
                                    placeholder="{{ $column['default'] ?? ($column['nullable'] ? 'NULL' : '') }}"
                                    class="input-field"
                                >
                            @endif
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="flex justify-end gap-2 border-t border-edge/60 px-4 py-3">
                <button wire:click="closeInsertDialog" class="rounded border border-edge px-3 py-1.5 text-[0.78rem] text-body hover:bg-raised">Cancel</button>
                <button
                    wire:click="saveInsert"
                    wire:loading.attr="disabled"
                    class="rounded bg-sky-600 px-4 py-1.5 text-[0.78rem] font-medium text-white hover:bg-sky-500 disabled:opacity-50"
                >Save</button>
            </div>
        </div>
    </div>
@endif
