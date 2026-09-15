<div>
    @if ($open)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" wire:click="close" wire:keydown.escape.window="close">
            <div class="flex max-h-[90vh] w-[min(1100px,96vw)] flex-col rounded-lg border border-edge bg-surface shadow-xl" wire:click.stop>
                <div class="flex items-center justify-between border-b border-edge/60 px-4 py-2">
                    <div>
                        <h3 class="text-sm font-semibold text-strong">ER diagram</h3>
                        <p class="text-[0.72rem] text-muted">{{ $database }} · tables and foreign keys</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button wire:click="loadGraph" class="rounded border border-edge px-2 py-0.5 text-[0.78rem] hover:bg-raised">Refresh</button>
                        <button wire:click="close" class="rounded px-1.5 text-muted hover:bg-raised hover:text-body">&times;</button>
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-auto p-4">
                    @if ($error)
                        <div class="rounded border border-red-300 bg-red-50 p-3 text-[0.78rem] text-red-700 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300">{{ $error }}</div>
                    @elseif ($graph === null)
                        <div class="text-[0.78rem] text-muted">Loading…</div>
                    @elseif ($graph['tables'] === [])
                        <div class="text-[0.78rem] text-muted">No tables in this database.</div>
                    @else
                        <div class="mb-4 flex flex-wrap gap-2">
                            @foreach ($graph['edges'] as $edge)
                                <span class="rounded border border-edge bg-chrome px-2 py-0.5 font-mono text-[0.7rem] text-dim">
                                    {{ $edge['from'] }}.{{ $edge['label'] }} → {{ $edge['to'] }}
                                </span>
                            @endforeach
                            @if ($graph['edges'] === [])
                                <span class="text-[0.78rem] text-muted">No foreign keys found (Laravel xxx_id conventions are not drawn here).</span>
                            @endif
                        </div>

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            @foreach ($graph['tables'] as $table)
                                <div wire:key="er-{{ $table['name'] }}" class="rounded-lg border border-edge bg-chrome">
                                    <div class="border-b border-edge/60 bg-raised px-2 py-1 text-[0.78rem] font-semibold text-strong">{{ $table['name'] }}</div>
                                    <ul class="max-h-48 overflow-y-auto px-2 py-1 font-mono text-[0.7rem] text-body">
                                        @foreach ($table['columns'] as $column)
                                            <li class="flex justify-between gap-2 py-0.5">
                                                <span>{{ $column['name'] }}</span>
                                                @if (($column['key'] ?? '') !== '')
                                                    <span class="text-sky-600 dark:text-sky-400">{{ $column['key'] }}</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
