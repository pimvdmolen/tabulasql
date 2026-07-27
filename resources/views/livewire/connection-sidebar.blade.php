<div class="flex min-h-0 flex-1 flex-col">
    <div class="flex h-9 shrink-0 items-center justify-between border-b border-edge/60 px-2">
        <span class="text-[0.78rem] font-semibold uppercase tracking-wide text-dim">Connections</span>
        <span class="flex gap-0.5">
            <button
                wire:click="$dispatch('open-import-connections')"
                class="rounded px-1.5 py-0.5 text-dim hover:bg-raised hover:text-body"
                title="Import connections"
            ><x-icon name="download" class="size-3.5" /></button>
            <button
                wire:click="$dispatch('open-export-connections')"
                class="rounded px-1.5 py-0.5 text-dim hover:bg-raised hover:text-body"
                title="Export connections"
            ><x-icon name="upload" class="size-3.5" /></button>
            <button
                wire:click="$dispatch('create-connection')"
                class="rounded px-1.5 py-0.5 text-dim hover:bg-raised hover:text-body"
                title="New connection"
            ><x-icon name="plus" class="size-3.5" /></button>
        </span>
    </div>
    <div class="min-h-0 flex-1 overflow-y-auto p-1">
        @forelse ($connections as $connection)
            @php
                $isOpen = in_array($connection->id, $openIds, true);
                $isActive = $activeId !== null && (int) $activeId === $connection->id;
            @endphp
            <div
                wire:key="conn-{{ $connection->id }}"
                wire:click="openConnection({{ $connection->id }})"
                wire:loading.class="opacity-60 pointer-events-none"
                wire:target="openConnection({{ $connection->id }})"
                x-on:contextmenu.prevent="$store.ctx.open($event, window.treeConnectionMenu($wire, { connectionId: {{ $connection->id }}, isOpen: {{ $isOpen ? 'true' : 'false' }}, restricted: {{ filled($connection->database) ? 'true' : 'false' }} }))"
                class="group flex cursor-default items-center gap-2 rounded px-2 py-1.5 text-sm
                    {{ $isActive
                        ? 'bg-sky-500/20 font-medium text-strong'
                        : ($isOpen ? 'text-strong hover:bg-raised' : 'text-body hover:bg-raised') }}"
                title="{{ $isOpen ? ($isActive ? 'Active connection' : 'Connected; click to show') : 'Click to connect' }}"
            >
                <span wire:loading.remove wire:target="openConnection({{ $connection->id }})" class="size-2 shrink-0 rounded-full" style="background: {{ $connection->color ?? '#64748b' }}"></span>
                <span wire:loading wire:target="openConnection({{ $connection->id }})" class="size-2 shrink-0 animate-spin rounded-full border-2 border-sky-500 border-t-transparent"></span>
                <span class="min-w-0 flex-1 truncate">{{ $connection->name }}</span>
                <span wire:loading wire:target="openConnection({{ $connection->id }})" class="shrink-0 text-[0.7rem] text-sky-600 dark:text-sky-400">Connecting…</span>
                @if ($isOpen)
                    <span wire:loading.remove wire:target="openConnection({{ $connection->id }})" class="shrink-0 rounded-full border border-emerald-500/40 bg-emerald-500/10 px-1.5 text-[0.7rem] leading-4 text-emerald-600 dark:text-emerald-400">open</span>
                @endif
                <span class="hidden shrink-0 gap-0.5 group-hover:flex">
                    <button
                        x-on:click.stop="$wire.duplicateConnection({{ $connection->id }})"
                        class="rounded px-1 text-muted hover:bg-overlay hover:text-body"
                        title="Duplicate"
                    ><x-icon name="copy" class="size-3.5" /></button>
                    <button
                        x-on:click.stop="$wire.$dispatch('edit-connection', { id: {{ $connection->id }} })"
                        class="rounded px-1 text-muted hover:bg-overlay hover:text-body"
                        title="Edit"
                    ><x-icon name="pencil" class="size-3.5" /></button>
                    @if ($isOpen)
                        <button
                            x-on:click.stop="$wire.$dispatch('close-connection', { id: {{ $connection->id }} })"
                            class="rounded px-1 text-muted hover:bg-overlay hover:text-body"
                            title="Close connection"
                        ><x-icon name="x" class="size-3.5" /></button>
                    @else
                        <button
                            x-on:click.stop="$wire.confirmDelete({{ $connection->id }})"
                            class="rounded px-1 text-muted hover:bg-overlay hover:text-red-600 dark:hover:text-red-400"
                            title="Delete"
                        ><x-icon name="trash" class="size-3.5" /></button>
                    @endif
                </span>
            </div>
        @empty
            <div class="px-2 py-4 text-center text-[0.78rem] text-muted">
                No connections yet.<br>Click + to add one.
            </div>
        @endforelse
    </div>

    @if ($confirmingDeleteId !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" wire:click="$set('confirmingDeleteId', null)">
            <div class="w-80 rounded-lg border border-edge bg-surface p-4 shadow-xl" wire:click.stop>
                <h3 class="mb-2 text-sm font-semibold text-strong">Delete connection?</h3>
                <p class="mb-4 text-[0.78rem] text-dim">
                    This removes the saved connection. The database itself is not touched.
                </p>
                <div class="flex justify-end gap-2">
                    <button
                        wire:click="$set('confirmingDeleteId', null)"
                        class="rounded border border-edge px-3 py-1 text-[0.78rem] text-body hover:bg-raised"
                    >Cancel</button>
                    <button
                        wire:click="deleteConnection({{ $confirmingDeleteId }})"
                        class="rounded bg-red-600 px-3 py-1 text-[0.78rem] text-white hover:bg-red-500"
                    >Delete</button>
                </div>
            </div>
        </div>
    @endif
</div>
