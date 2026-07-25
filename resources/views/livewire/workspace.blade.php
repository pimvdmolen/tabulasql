<div class="flex h-screen flex-col">
    {{-- Connection tab bar --}}
    <div class="flex h-9 shrink-0 items-center border-b border-edge/60 bg-chrome px-1">
        <div class="flex min-h-0 min-w-0 flex-1 items-end gap-px self-stretch overflow-x-auto">
            @forelse ($openTabs as $tab)
                <div
                    wire:key="conn-tab-{{ $tab['id'] }}"
                    class="group flex h-8 items-center rounded-t border-t-2
                        {{ $activeTabId === $tab['id']
                            ? 'border-sky-500 bg-surface text-strong'
                            : 'border-transparent bg-raised/60 text-dim hover:text-body' }}"
                >
                    <button
                        wire:click="activateTab({{ $tab['id'] }})"
                        class="flex h-full items-center gap-2 pl-3 pr-1 text-sm"
                    >
                        <span class="size-2 rounded-full" style="background: {{ $tab['color'] ?? '#64748b' }}"></span>
                        {{ $tab['name'] }}
                    </button>
                    <button
                        wire:click="closeTab({{ $tab['id'] }})"
                        class="mr-1 rounded px-1 text-muted opacity-0 hover:bg-overlay hover:text-body group-hover:opacity-100"
                        title="Close connection"
                    >&times;</button>
                </div>
            @empty
                <div class="px-3 pb-2 text-[0.78rem] text-muted">No open connections. Double-click a connection to open it.</div>
            @endforelse
        </div>

        <div class="ml-2 flex max-w-[min(620px,58vw)] shrink-0 items-center gap-1.5 px-2 py-0.5 text-[0.72rem] leading-snug text-amber-700 dark:text-amber-400">
            <a
                href="https://buymeacoffee.com/pimvdmolen"
                target="_blank"
                rel="noopener noreferrer"
                class="truncate hover:underline hover:text-amber-600 dark:hover:text-amber-300"
                title="Buy me a coffee"
            >This software is completely free. Please buy me a coffee for support</a>
            <span class="shrink-0 text-sm" aria-hidden="true">☕</span>
        </div>
    </div>

    <div class="flex min-h-0 flex-1">
        {{-- Saved connections + database tables sidebar --}}
        <div
            x-data="splitter({ axis: 'x', initial: 240, min: 170, max: 420, key: 'connections' })"
            class="relative flex shrink-0 flex-col border-r border-edge/60 bg-chrome"
            :style="`width: ${size}px`"
        >
            {{-- Saved connections: resizable height, scrolls internally --}}
            <div
                x-data="splitter({ axis: 'y', initial: 300, min: 120, max: 600, key: 'connections-height' })"
                class="relative flex shrink-0 flex-col overflow-hidden border-b border-edge/60"
                :style="`height: ${size}px`"
            >
                <livewire:connection-sidebar />
                <div x-bind="handle" class="absolute inset-x-0 -bottom-0.5 z-10 h-1 cursor-row-resize hover:bg-sky-500/50"></div>
            </div>

            {{-- Database tables for the active connection tab --}}
            <div class="relative min-h-0 flex-1 bg-surface">
                @forelse ($openTabs as $tab)
                    <div
                        wire:key="explorer-wrap-{{ $tab['id'] }}"
                        class="absolute inset-0 {{ $activeTabId === $tab['id'] ? '' : 'hidden' }}"
                    >
                        <livewire:object-explorer :connection-id="$tab['id']" :key="'explorer-'.$tab['id']" />
                    </div>
                @empty
                    <div class="p-3 text-center text-[0.78rem] text-muted">No open connections.</div>
                @endforelse
            </div>

            <livewire:sidebar-footer />

            <div x-bind="handle" class="absolute inset-y-0 -right-0.5 z-10 w-1 cursor-col-resize hover:bg-sky-500/50"></div>
        </div>

        {{-- Open connection workspaces (all stay mounted; only the active one is visible) --}}
        <div class="relative min-w-0 flex-1">
            @forelse ($openTabs as $tab)
                <div
                    wire:key="conn-workspace-{{ $tab['id'] }}"
                    class="absolute inset-0 {{ $activeTabId === $tab['id'] ? '' : 'hidden' }}"
                >
                    <livewire:connection-tab :connection-id="$tab['id']" :key="'tab-'.$tab['id']" />
                </div>
            @empty
                <div class="flex h-full items-center justify-center text-sm text-faint">
                    <div class="text-center">
                        <div class="mb-2 text-4xl">🗄</div>
                        Add a connection with the <span class="mx-1 rounded bg-raised px-1.5">+</span> button,<br>
                        then double-click it to connect.
                    </div>
                </div>
            @endforelse
        </div>
    </div>

    <livewire:connection-form />
    <livewire:connection-porter-dialog />
    <livewire:copy-wizard />
    <livewire:export-wizard />
    <livewire:import-dialog />
    <livewire:create-table-dialog />
    <livewire:create-database-dialog />
    <livewire:index-manager />
    <livewire:foreign-key-manager />

    {{-- App-wide context menu --}}
    <div
        x-data
        x-show="$store.ctx.visible"
        x-cloak
        x-on:click.window="$store.ctx.close()"
        x-on:contextmenu.window="if (!$event.defaultPrevented) $store.ctx.close()"
        x-on:keydown.escape.window="$store.ctx.close()"
        class="fixed z-[60] min-w-52 rounded border border-edge bg-surface py-1 text-[0.78rem] shadow-xl"
        :style="`left: ${$store.ctx.x}px; top: ${$store.ctx.y}px`"
    >
        <template x-for="(item, index) in $store.ctx.items" :key="index">
            <div>
                <template x-if="item.divider">
                    <div class="my-1 border-t border-edge/60"></div>
                </template>
                <template x-if="!item.divider">
                    <div class="group relative">
                        <button
                            class="flex w-full items-center justify-between gap-4 px-3 py-1 text-left"
                            :class="item.disabled
                                ? 'cursor-default text-faint'
                                : (item.danger ? 'text-red-600 dark:text-red-400 hover:bg-raised' : 'text-body hover:bg-raised')"
                            x-on:click.stop="$store.ctx.run(item)"
                        >
                            <span x-text="item.label"></span>
                            <span x-show="item.children" class="text-faint">▸</span>
                        </button>
                        <template x-if="item.children">
                            <div class="invisible absolute left-full top-0 z-[61] min-w-44 rounded border border-edge bg-surface py-1 shadow-xl group-hover:visible">
                                <template x-for="(child, childIndex) in item.children" :key="childIndex">
                                    <button
                                        class="flex w-full px-3 py-1 text-left"
                                        :class="child.disabled ? 'cursor-default text-faint' : 'text-body hover:bg-raised'"
                                        x-on:click.stop="$store.ctx.run(child)"
                                        x-text="child.label"
                                    ></button>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </template>
    </div>
</div>
