@php $record = $recordStack !== [] ? end($recordStack) : null; @endphp
@if ($record !== null)
    <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/60" wire:keydown.escape.window="closeRecordDialog">
        <div class="flex max-h-[85vh] w-[560px] flex-col rounded-lg border border-edge bg-surface shadow-xl">
            <div class="flex items-center justify-between border-b border-edge/60 px-4 py-3">
                <div class="min-w-0">
                    <h2 class="truncate text-sm font-semibold text-strong">
                        {{ $record['database'] }}.{{ $record['table'] }}
                        @if ($record['convention'])
                            <span class="ml-1 rounded border border-dashed border-edge px-1 text-[0.7rem] font-normal text-muted" title="Matched by naming convention, not a real foreign key">convention</span>
                        @endif
                    </h2>
                    <p class="truncate font-mono text-xs text-muted">{{ $record['relation'] }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-1">
                    @if (count($recordStack) > 1)
                        <button wire:click="popRecord" class="rounded border border-edge px-2 py-0.5 text-[0.78rem] text-dim hover:bg-raised hover:text-body" title="Back">←</button>
                    @endif
                    <button wire:click="closeRecordDialog" class="rounded px-1.5 text-muted hover:bg-raised hover:text-body">&times;</button>
                </div>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto p-3">
                <table class="w-full font-mono text-[0.78rem]">
                    @foreach ($record['row'] as $field => $value)
                        <tr class="border-b border-grid/60" wire:key="rec-{{ count($recordStack) }}-{{ $field }}">
                            <td class="w-40 truncate py-1 pr-3 align-top font-semibold text-dim">{{ $field }}</td>
                            <td class="break-all py-1 text-body select-text">
                                @if ($value === null)
                                    <span class="italic text-faint">(NULL)</span>
                                @elseif (is_array($value))
                                    <span class="text-muted">{{ $value['blob'] ? '⬡ binary' : '¶ text' }}, {{ \App\Support\Bytes::format($value['size']) }}</span>
                                @else
                                    {{ $value }}
                                @endif

                                @if (in_array($field, $record['fkColumns'], true) && $value !== null && ! is_array($value))
                                    <button
                                        wire:click="drillRelated(@js($field))"
                                        class="ml-1 rounded border border-edge bg-raised px-1 text-[0.7rem] text-dim hover:bg-overlay hover:text-body"
                                        title="Show related record"
                                    >…</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>

            <div class="flex justify-end gap-2 border-t border-edge/60 px-4 py-3">
                <button wire:click="openRecordInGrid" class="rounded border border-edge px-3 py-1.5 text-[0.78rem] text-body hover:bg-raised">Open in Table Data</button>
                <button wire:click="closeRecordDialog" class="rounded bg-sky-600 px-4 py-1.5 text-[0.78rem] font-medium text-white hover:bg-sky-500">Close</button>
            </div>
        </div>
    </div>
@endif
