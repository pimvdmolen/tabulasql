<div>
    @if ($context !== null)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/60" wire:keydown.escape.window="close">
            <div class="flex max-h-[85vh] w-[min(960px,94vw)] flex-col rounded-lg border border-edge bg-surface shadow-xl">
                <div class="flex items-center justify-between border-b border-edge/60 px-4 py-3">
                    <div>
                        <div class="text-[0.72rem] font-semibold uppercase tracking-wide text-faint">Source</div>
                        <h2 class="text-sm font-semibold text-strong">
                            {{ $context['connectionName'] ?? 'Connection' }}
                            <span class="font-normal text-dim">/</span>
                            <span class="font-mono text-sky-700 dark:text-sky-300">{{ $context['database'] }}</span>
                        </h2>
                    </div>
                    <button wire:click="close" class="rounded px-1.5 text-muted hover:bg-raised hover:text-body">&times;</button>
                </div>

                <div class="flex min-h-0 flex-1 gap-4 overflow-hidden px-4 py-4">
                    {{-- Left: object tree --}}
                    <div class="flex w-1/2 min-w-0 flex-col gap-2">
                        <div class="flex items-center justify-between text-[0.78rem] text-muted">
                            <span>{{ count($selected) }} of {{ count($context['objects']) }} selected</span>
                            <span class="flex gap-2">
                                <button wire:click="$set('selected', {{ json_encode(array_column($context['objects'], 'name')) }})" class="hover:text-body">all</button>
                                <button wire:click="$set('selected', [])" class="hover:text-body">none</button>
                            </span>
                        </div>
                        <div class="max-h-[600px] space-y-1 overflow-y-auto rounded border border-edge/60 p-2">
                            @php
                                $groupOrder = ['table' => 'Tables', 'view' => 'Views', 'procedure' => 'Procedures', 'function' => 'Functions', 'trigger' => 'Triggers', 'event' => 'Events'];
                                $groupIcons = [
                                    'table' => ['table', 'text-sky-600/80 dark:text-sky-400/80'],
                                    'view' => ['eye', 'text-purple-600/80 dark:text-purple-400/80'],
                                    'procedure' => ['settings', 'text-emerald-600/80 dark:text-emerald-400/80'],
                                    'function' => ['function', 'text-emerald-600/80 dark:text-emerald-400/80'],
                                    'trigger' => ['bolt', 'text-amber-600/80 dark:text-amber-400/80'],
                                    'event' => ['clock', 'text-amber-600/80 dark:text-amber-400/80'],
                                ];
                                $groups = collect($context['objects'])->groupBy('type');
                            @endphp
                            @foreach ($groupOrder as $type => $label)
                                @php
                                    $objects = $groups->get($type, collect());
                                    $names = $objects->pluck('name')->all();
                                    $checkedCount = count(array_intersect($names, $selected));
                                    $isExpanded = ! in_array($type, $collapsedGroups, true);
                                    [$icon, $iconClass] = $groupIcons[$type] ?? ['table', 'text-dim'];
                                @endphp
                                <div wire:key="cpy-group-{{ $type }}">
                                    <div class="flex items-center gap-1.5 rounded px-1 py-0.5 hover:bg-raised">
                                        <button type="button" wire:click="toggleGroupExpanded('{{ $type }}')" class="shrink-0 text-faint hover:text-body">
                                            <x-icon :name="$isExpanded ? 'chevron-down' : 'chevron-right'" class="size-3" />
                                        </button>
                                        <input
                                            type="checkbox"
                                            wire:click="toggleGroup('{{ $type }}')"
                                            @checked($names !== [] && $checkedCount === count($names))
                                            @disabled($names === [])
                                        >
                                        <button type="button" wire:click="toggleGroupExpanded('{{ $type }}')" class="flex-1 truncate text-left text-[0.72rem] font-semibold uppercase tracking-wide text-dim">
                                            {{ $label }} ({{ $checkedCount }}/{{ count($names) }})
                                        </button>
                                    </div>
                                    @if ($isExpanded)
                                        <div class="ml-4 space-y-0.5 border-l border-grid pl-2">
                                            @forelse ($objects as $object)
                                                <label class="flex items-center gap-2 rounded px-1 py-0.5 text-sm text-body hover:bg-raised" wire:key="cpy-{{ $object['name'] }}">
                                                    <input type="checkbox" wire:model.live="selected" value="{{ $object['name'] }}">
                                                    <x-icon :name="$icon" class="size-3.5 {{ $iconClass }}" />
                                                    <span class="truncate">{{ $object['name'] }}</span>
                                                </label>
                                            @empty
                                                <div class="px-1 py-0.5 text-[0.78rem] italic text-faint">No {{ strtolower($label) }} found.</div>
                                            @endforelse
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Right: target + options --}}
                    <div class="flex w-1/2 min-w-0 flex-col gap-3 overflow-y-auto">
                        <label class="block">
                            <span class="mb-1 block text-[0.78rem] text-dim">Target connection</span>
                            <select wire:model.live="targetConnectionId" class="input-field">
                                <option value="">Choose…</option>
                                @foreach ($connections as $connection)
                                    <option value="{{ $connection->id }}">{{ $connection->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-[0.78rem] text-dim">Target database</span>
                            <select wire:model="targetDatabase" class="input-field" @disabled($targetDatabases === [])>
                                <option value="">Choose…</option>
                                @foreach ($targetDatabases as $database)
                                    <option value="{{ $database }}">{{ $database }}</option>
                                @endforeach
                            </select>
                        </label>

                        <div class="flex flex-col gap-1.5 text-sm text-body">
                            <label class="flex items-center gap-2">
                                <input type="radio" wire:model="withData" value="1" class="border-edge bg-raised">
                                Structure + data
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="radio" wire:model="withData" value="0" class="border-edge bg-raised">
                                Structure only
                            </label>
                        </div>

                        <div class="flex flex-col gap-1.5 text-sm text-body">
                            <label class="flex items-center gap-2">
                                <input type="radio" wire:model="conflict" value="skip" class="border-edge bg-raised">
                                Skip existing objects
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="radio" wire:model="conflict" value="drop" class="border-edge bg-raised">
                                Drop &amp; recreate existing
                            </label>
                        </div>

                        @if ($error !== null)
                            <div class="rounded border border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-950/40 px-3 py-2 text-[0.78rem] text-red-700 dark:text-red-300">{{ $error }}</div>
                        @endif

                        <div wire:stream="copy-progress" class="max-h-40 overflow-y-auto rounded border border-grid bg-chrome p-2 font-mono text-xs text-dim empty:hidden"></div>

                        @if ($summary !== null)
                            <div class="rounded border px-3 py-2 text-[0.78rem] {{ $summary['failed'] > 0 ? 'border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-300' : 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300' }}">
                                Done: {{ $summary['copied'] }} copied, {{ $summary['skipped'] }} skipped,
                                {{ $summary['failed'] }} failed, {{ number_format($summary['rows']) }} row(s) transferred.
                                @foreach ($summary['errors'] as $object => $message)
                                    <div class="mt-1 font-mono">`{{ $object }}`: {{ $message }}</div>
                                @endforeach
                            </div>

                            @if (! empty($summary['packetTooLarge']))
                                <div class="space-y-2 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-[0.78rem] text-amber-700 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
                                    <p>
                                        {{ count($summary['packetTooLarge']) }} table(s) have rows bigger than the target
                                        server's current packet limit
                                        ({{ \App\Exceptions\PacketTooLargeException::human(reset($summary['packetTooLarge'])['current']) }}).
                                        Tabula can raise <code>max_allowed_packet</code> on the target and retry just those
                                        table(s); this needs admin rights on the target server.
                                    </p>
                                    <button
                                        wire:click="fixPacketLimitAndRetry"
                                        wire:loading.attr="disabled"
                                        class="rounded bg-amber-600 px-3 py-1.5 text-[0.78rem] font-medium text-white hover:bg-amber-500 disabled:opacity-50"
                                    >
                                        <span wire:loading.remove wire:target="fixPacketLimitAndRetry">Increase max_allowed_packet on target &amp; retry</span>
                                        <span wire:loading wire:target="fixPacketLimitAndRetry">Retrying…</span>
                                    </button>
                                </div>
                            @endif
                        @endif
                    </div>
                </div>

                <div class="flex justify-end gap-2 border-t border-edge/60 px-4 py-3">
                    <button wire:click="close" class="rounded border border-edge px-3 py-1.5 text-[0.78rem] text-body hover:bg-raised">
                        {{ $summary !== null ? 'Close' : 'Cancel' }}
                    </button>
                    <button
                        wire:click="runCopy"
                        wire:loading.attr="disabled"
                        class="rounded bg-sky-600 px-4 py-1.5 text-[0.78rem] font-medium text-white hover:bg-sky-500 disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="runCopy">Copy</span>
                        <span wire:loading wire:target="runCopy">Copying…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
