<div class="shrink-0 space-y-1.5 border-t border-edge/60 p-2" x-data="{ showKeys: false }">
    <button wire:click="$set('showThemeDialog', true)" class="w-full rounded border border-edge py-1 text-[0.78rem] text-muted hover:bg-raised hover:text-body">
        🎨 Theme
    </button>
    <button x-on:click="showKeys = true" class="w-full rounded border border-edge py-1 text-[0.78rem] text-muted hover:bg-raised hover:text-body">
        ⌨ Keyboard shortcuts
    </button>

    {{-- Theme dialog --}}
    @if ($showThemeDialog)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" wire:keydown.escape.window="$set('showThemeDialog', false)">
            <div class="w-[min(420px,92vw)] rounded-lg border border-edge bg-surface p-4 shadow-xl">
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
        <div x-show="showKeys" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/60"
             x-on:keydown.escape.window="showKeys = false" x-on:click.self="showKeys = false">
            <div class="w-96 rounded-lg border border-edge bg-surface p-4 shadow-xl">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-strong">Keyboard shortcuts</h3>
                    <button x-on:click="showKeys = false" class="rounded px-1.5 text-muted hover:bg-raised hover:text-body">&times;</button>
                </div>
                <table class="w-full text-[0.78rem] text-body">
                    @foreach ([
                        'Ctrl+Enter' => 'Run query',
                        'Ctrl+Shift+Enter' => 'Run selection',
                        'Ctrl+Space' => 'Autocomplete',
                        'F5 / Ctrl+R' => 'Refresh grid',
                        'Ctrl+F' => 'Filter dialog',
                        'F11' => 'Show Table Data tab',
                        'Escape' => 'Close dialog / cancel edit',
                        'Double-click cell' => 'Edit value',
                        'Right-click' => 'Context menu',
                    ] as $key => $action)
                        <tr>
                            <td class="w-40 py-1"><kbd>{{ $key }}</kbd></td>
                            <td class="py-1 text-dim">{{ $action }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>
    </template>
</div>
