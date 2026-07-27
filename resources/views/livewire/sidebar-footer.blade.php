<div class="flex items-center gap-0.5" x-data="{ showKeys: false }">
    <button
        wire:click="toggleSafeMode"
        class="rounded p-1.5 {{ $safeMode ? 'text-amber-600 dark:text-amber-400 bg-amber-500/10' : 'text-muted hover:bg-raised hover:text-body' }}"
        title="{{ $safeMode ? 'Safe mode on; writes ask for confirmation' : 'Safe mode off; click to enable' }}"
    >
        <x-icon :name="$safeMode ? 'lock' : 'lock-open'" class="size-4" />
    </button>
    <button
        wire:click="$set('showThemeDialog', true)"
        class="rounded p-1.5 text-muted hover:bg-raised hover:text-body"
        title="Theme"
    >
        <x-icon name="palette" class="size-4" />
    </button>
    <button
        x-on:click="showKeys = true"
        class="rounded p-1.5 text-muted hover:bg-raised hover:text-body"
        title="Keyboard shortcuts"
    >
        <x-icon name="help" class="size-4" />
    </button>

    @if ($showThemeDialog)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60"
            wire:keydown.escape.window="$set('showThemeDialog', false)"
            wire:click="$set('showThemeDialog', false)"
        >
            <div class="w-[min(420px,92vw)] rounded-lg border border-edge bg-surface p-4 shadow-xl" wire:click.stop>
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-strong">Theme</h3>
                    <button wire:click="$set('showThemeDialog', false)" class="rounded px-1.5 text-muted hover:bg-raised hover:text-body">&times;</button>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    @foreach ([
                        'auto' => 'Auto',
                        'light' => 'Light',
                        'dark' => 'Dark',
                        'classic' => 'TabulaSQL Classic',
                    ] as $value => $label)
                        <button
                            wire:click="setTheme('{{ $value }}')"
                            x-on:click="window.__applyTheme('{{ $value }}'); $wire.showThemeDialog = false"
                            class="flex flex-col items-center gap-2 rounded-lg border-2 p-2 {{ $theme === $value ? 'border-sky-500' : 'border-edge hover:border-overlay' }}"
                        >
                            @include('livewire.partials.theme-swatch', ['theme' => $value])
                            <span class="text-[0.78rem] {{ $theme === $value ? 'text-strong' : 'text-dim' }}">{{ $label }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <template x-teleport="body">
        <div
            x-show="showKeys"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60"
            x-on:keydown.escape.window="showKeys = false"
            x-on:click.self="showKeys = false"
        >
            <div class="w-[min(440px,92vw)] rounded-lg border border-edge bg-surface p-4 shadow-xl">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-strong">Keyboard shortcuts</h3>
                    <button x-on:click="showKeys = false" class="rounded px-1.5 text-muted hover:bg-raised hover:text-body">&times;</button>
                </div>
                <table class="w-full text-[0.78rem] text-body">
                    @foreach ([
                        'Click connection' => 'Connect / switch to it',
                        'Ctrl+Enter' => 'Run query',
                        'Ctrl+Shift+Enter' => 'Run selection',
                        'Ctrl+Space' => 'Autocomplete',
                        'F5 / Ctrl+R' => 'Refresh grid',
                        'Ctrl+F' => 'Filter dialog',
                        'F11' => 'Show Table Data tab',
                        'Ctrl+A' => 'Select all rows (grid)',
                        'Shift+Click' => 'Extend row selection',
                        'Delete' => 'Delete selected rows',
                        'Escape' => 'Close dialog / cancel edit',
                        'Double-click cell' => 'Edit value',
                        'Right-click' => 'Context menu',
                    ] as $key => $action)
                        <tr>
                            <td class="w-44 py-1"><kbd>{{ $key }}</kbd></td>
                            <td class="py-1 text-dim">{{ $action }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>
    </template>
</div>
