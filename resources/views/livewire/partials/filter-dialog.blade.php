@if ($showFilterDialog)
    @php $columns = array_column($this->tableInfo['columns'] ?? [], 'name'); @endphp
    <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/60" wire:keydown.escape.window="$set('showFilterDialog', false)">
        <div class="max-h-[85vh] w-[640px] overflow-y-auto rounded-lg border border-edge bg-surface shadow-xl">
            <div class="flex items-center justify-between border-b border-edge/60 px-4 py-3">
                <h2 class="text-sm font-semibold text-strong">Custom Filter for {{ $table }}</h2>
                <button wire:click="$set('showFilterDialog', false)" class="rounded px-1.5 text-muted hover:bg-raised hover:text-body">&times;</button>
            </div>

            <div class="space-y-2 px-4 py-4">
                @foreach ($draftFilters as $index => $rule)
                    @php $operator = strtoupper($rule['operator'] ?? '='); @endphp
                    <div class="flex items-center gap-2" wire:key="rule-{{ $index }}">
                        <select wire:model="draftFilters.{{ $index }}.column" class="input-field w-44">
                            <option value="">Choose a field…</option>
                            @foreach ($columns as $column)
                                <option value="{{ $column }}">{{ $column }}</option>
                            @endforeach
                        </select>
                        <select wire:model.live="draftFilters.{{ $index }}.operator" class="input-field w-32">
                            @foreach (\App\Services\FilterBuilder::OPERATORS as $op)
                                <option value="{{ $op }}">{{ $op }}</option>
                            @endforeach
                        </select>
                        @if (! in_array($operator, ['IS NULL', 'IS NOT NULL'], true))
                            <input type="text" wire:model="draftFilters.{{ $index }}.value" class="input-field flex-1"
                                placeholder="{{ $operator === 'IN' ? 'a, b, c' : 'value' }}">
                            @if ($operator === 'BETWEEN')
                                <input type="text" wire:model="draftFilters.{{ $index }}.value2" class="input-field flex-1" placeholder="and value">
                            @endif
                        @endif
                        <button wire:click="removeDraftRule({{ $index }})" class="rounded px-1.5 text-muted hover:bg-raised hover:text-red-600 dark:hover:text-red-400" title="Remove rule">&times;</button>
                    </div>
                @endforeach

                <div class="flex items-center justify-between pt-1">
                    <button wire:click="addDraftRule" class="text-[0.78rem] text-sky-600 dark:text-sky-400 hover:underline">+ Add condition (AND)</button>
                    <button wire:click="$toggle('showSqlPreview')" class="text-[0.78rem] text-muted hover:text-body">
                        {{ $showSqlPreview ? 'Hide' : 'Show' }} SQL Preview
                    </button>
                </div>

                @if ($showSqlPreview)
                    <pre class="overflow-x-auto rounded border border-grid bg-chrome p-2 font-mono text-[0.78rem] text-body select-text">{{ $this->draftSqlPreview !== '' ? $this->draftSqlPreview : '-- no conditions yet' }}</pre>
                @endif
            </div>

            <div class="flex justify-end gap-2 border-t border-edge/60 px-4 py-3">
                <button wire:click="$set('showFilterDialog', false)" class="rounded border border-edge px-3 py-1.5 text-[0.78rem] text-body hover:bg-raised">Cancel</button>
                <button wire:click="applyFilters" class="rounded bg-sky-600 px-4 py-1.5 text-[0.78rem] font-medium text-white hover:bg-sky-500">Apply Filter</button>
            </div>
        </div>
    </div>
@endif
