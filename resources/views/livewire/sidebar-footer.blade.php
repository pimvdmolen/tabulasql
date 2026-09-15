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
        wire:click="openAiSettings"
        class="rounded p-1.5 {{ $aiHasKey ? 'text-sky-600 dark:text-sky-400 bg-sky-500/10' : 'text-muted hover:bg-raised hover:text-body' }}"
        title="AI settings (text-to-SQL)"
    >
        <x-icon name="bolt" class="size-4" />
    </button>
    <button
        wire:click="toggleMessagesTab"
        class="rounded p-1.5 {{ $showMessagesTab ? 'text-sky-600 dark:text-sky-400 bg-sky-500/10' : 'text-muted hover:bg-raised hover:text-body' }}"
        title="{{ $showMessagesTab ? 'Messages tab visible' : 'Show Messages tab' }}"
    >
        <x-icon name="history" class="size-4" />
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

    @if ($showAiDialog)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60"
            wire:keydown.escape.window="$set('showAiDialog', false)"
            wire:click="$set('showAiDialog', false)"
        >
            <div class="w-[min(480px,92vw)] rounded-lg border border-edge bg-surface p-4 shadow-xl" wire:click.stop>
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-strong">AI text-to-SQL</h3>
                    <button wire:click="$set('showAiDialog', false)" class="rounded px-1.5 text-muted hover:bg-raised hover:text-body">&times;</button>
                </div>
                <p class="mb-3 text-[0.78rem] text-dim">Keys are encrypted in the local app database. Nothing is sent until you click Generate in the AI drawer.</p>

                <label class="mb-2 block text-[0.78rem] text-dim">Provider
                    <select wire:model.live="aiProvider" class="input-field mt-1">
                        <option value="openai">OpenAI</option>
                        <option value="anthropic">Anthropic</option>
                        <option value="openai_compatible">OpenAI-compatible (Ollama, Groq, …)</option>
                    </select>
                </label>

                <label class="mb-2 block text-[0.78rem] text-dim">
                    API key {{ $aiHasKey ? '(leave blank to keep current)' : '' }}
                    <input type="password" wire:model="aiApiKey" class="input-field mt-1" autocomplete="off" placeholder="{{ $aiHasKey ? '••••••••' : 'sk-…' }}">
                </label>

                @if ($aiProvider === 'openai_compatible')
                    <label class="mb-2 block text-[0.78rem] text-dim">Base URL
                        <input type="text" wire:model.blur="aiBaseUrl" class="input-field mt-1" placeholder="http://127.0.0.1:11434/v1">
                    </label>
                @endif

                <div class="mb-2">
                    <div class="mb-1 flex items-center justify-between gap-2">
                        <span class="text-[0.78rem] text-dim">Model</span>
                        <button
                            type="button"
                            wire:click="refreshAiModels"
                            class="text-[0.7rem] text-sky-600 hover:underline dark:text-sky-400"
                            title="Fetch models from the provider API"
                        >{{ $aiModelsLoading ? 'Loading…' : 'Refresh list' }}</button>
                    </div>

                    @if (! $aiModelCustom)
                        <select wire:model.live="aiModel" class="input-field">
                            @foreach ($aiModelGroups as $group)
                                <optgroup label="{{ $group['label'] }}">
                                    @foreach ($group['models'] as $model)
                                        <option value="{{ $model['id'] }}">{{ $model['label'] }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                            <option value="__custom__">Custom model id…</option>
                        </select>
                    @else
                        <input
                            type="text"
                            wire:model="aiModel"
                            class="input-field"
                            placeholder="model id"
                        >
                        <button
                            type="button"
                            wire:click="useModelList"
                            class="mt-1 text-[0.7rem] text-sky-600 hover:underline dark:text-sky-400"
                        >Back to list</button>
                    @endif

                    <p class="mt-1 text-[0.7rem] text-faint">
                        @if ($aiModelsSource === 'api')
                            Loaded from provider API.
                        @else
                            Curated list (add/save an API key, then Refresh for live models).
                        @endif
                    </p>
                    @if ($aiModelsError)
                        <p class="mt-1 break-words text-[0.7rem] text-amber-700 dark:text-amber-400">{{ $aiModelsError }}</p>
                    @endif
                </div>

                <div class="mt-3 flex items-center justify-between gap-2">
                    <button wire:click="clearAiKey" class="rounded border border-edge px-2 py-1 text-[0.72rem] text-dim hover:bg-raised" @disabled(! $aiHasKey)>Clear key</button>
                    <div class="flex gap-2">
                        <button wire:click="$set('showAiDialog', false)" class="rounded border border-edge px-3 py-1 text-[0.78rem]">Cancel</button>
                        <button wire:click="saveAiSettings" class="rounded bg-sky-600 px-3 py-1 text-[0.78rem] text-white hover:bg-sky-500">Save</button>
                    </div>
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
                        'Ctrl+P' => 'Command palette',
                        'Ctrl+Space' => 'Autocomplete',
                        'F5 / Ctrl+R' => 'Refresh grid',
                        'Ctrl+F' => 'Filter dialog',
                        'F11' => 'Show Data tab',
                        'Ctrl+A' => 'Select all rows (grid)',
                        'Shift+Click' => 'Extend row selection',
                        'Delete' => 'Delete selected rows',
                        'Escape' => 'Clear row selection / close dialog / cancel edit',
                        'Double-click cell' => 'Edit value',
                        'Right-click' => 'Context menu',
                        'Drag tabs / connections' => 'Reorder',
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
