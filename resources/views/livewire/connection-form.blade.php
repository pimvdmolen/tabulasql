<div>
    @if ($open)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/60" wire:keydown.escape.window="close">
            <div class="max-h-[90vh] w-[min(480px,92vw)] overflow-y-auto rounded-lg border border-edge bg-surface shadow-xl">
                <div class="flex items-center justify-between border-b border-edge/60 px-4 py-3">
                    <h2 class="text-sm font-semibold text-strong">
                        {{ $connectionId === null ? 'New Connection' : 'Edit Connection' }}
                    </h2>
                    <button wire:click="close" class="rounded px-1.5 text-muted hover:bg-raised hover:text-body">&times;</button>
                </div>

                <div class="space-y-3 px-4 py-4">
                    <div class="flex gap-3">
                        <label class="block flex-1">
                            <span class="mb-1 block text-[0.78rem] text-dim">Name</span>
                            <input type="text" wire:model="name" class="input-field" placeholder="My server">
                            @error('name') <span class="mt-1 block text-[0.78rem] text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                        </label>
                        <label class="block w-24">
                            <span class="mb-1 block text-[0.78rem] text-dim">Color</span>
                            <input type="color" wire:model="color" class="h-[30px] w-full cursor-pointer rounded border border-edge bg-raised p-0.5">
                        </label>
                    </div>

                    <div class="flex gap-3">
                        <label class="block flex-1">
                            <span class="mb-1 block text-[0.78rem] text-dim">Host</span>
                            <input type="text" wire:model="host" class="input-field" placeholder="localhost">
                            @error('host') <span class="mt-1 block text-[0.78rem] text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                        </label>
                        <label class="block w-24">
                            <span class="mb-1 block text-[0.78rem] text-dim">Port</span>
                            <input type="number" wire:model="port" class="input-field">
                        </label>
                    </div>

                    <div class="flex gap-3">
                        <label class="block flex-1">
                            <span class="mb-1 block text-[0.78rem] text-dim">Username</span>
                            <input type="text" wire:model="username" class="input-field">
                            @error('username') <span class="mt-1 block text-[0.78rem] text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                        </label>
                        <label class="block flex-1">
                            <span class="mb-1 block text-[0.78rem] text-dim">Password</span>
                            <input type="password" wire:model="password" class="input-field" autocomplete="new-password">
                        </label>
                    </div>

                    <label class="block">
                        <span class="mb-1 block text-[0.78rem] text-dim">
                            Database <span class="text-faint">(optional, restricts the tree to just this database)</span>
                        </span>
                        <input type="text" wire:model.live.debounce.300ms="database" class="input-field" placeholder="e.g. my_database">
                        @error('database') <span class="mt-1 block text-[0.78rem] text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                    </label>

                    @if (trim($database) === '')
                        <label class="block">
                            <span class="mb-1 block text-[0.78rem] text-dim">Default database <span class="text-faint">(optional, just pre-opens it in the tree)</span></span>
                            <input type="text" wire:model="default_database" class="input-field">
                        </label>
                    @endif

                    <label class="flex items-center gap-2 pt-1">
                        <input type="checkbox" wire:model.live="use_ssh" class="rounded border-edge bg-raised">
                        <span class="text-sm text-body">Connect through SSH tunnel</span>
                    </label>

                    @if ($use_ssh)
                        <div class="space-y-3 rounded border border-edge/60 bg-chrome/50 p-3">
                            <div class="flex gap-3">
                                <label class="block flex-1">
                                    <span class="mb-1 block text-[0.78rem] text-dim">SSH host</span>
                                    <input type="text" wire:model="ssh_host" class="input-field">
                                    @error('ssh_host') <span class="mt-1 block text-[0.78rem] text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                                </label>
                                <label class="block w-24">
                                    <span class="mb-1 block text-[0.78rem] text-dim">SSH port</span>
                                    <input type="number" wire:model="ssh_port" class="input-field">
                                </label>
                            </div>
                            <div class="flex gap-3">
                                <label class="block flex-1">
                                    <span class="mb-1 block text-[0.78rem] text-dim">SSH user</span>
                                    <input type="text" wire:model="ssh_username" class="input-field">
                                    @error('ssh_username') <span class="mt-1 block text-[0.78rem] text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                                </label>
                                <label class="block flex-1">
                                    <span class="mb-1 block text-[0.78rem] text-dim">Authentication</span>
                                    <select wire:model.live="ssh_auth_type" class="input-field">
                                        <option value="password">Password</option>
                                        <option value="key">Private key</option>
                                    </select>
                                </label>
                            </div>
                            @if ($ssh_auth_type === 'password')
                                <label class="block">
                                    <span class="mb-1 block text-[0.78rem] text-dim">SSH password</span>
                                    <input type="password" wire:model="ssh_password" class="input-field" autocomplete="new-password">
                                </label>
                            @else
                                <label class="block">
                                    <span class="mb-1 block text-[0.78rem] text-dim">Private key path</span>
                                    <div class="flex gap-2">
                                        <input type="text" wire:model="ssh_key_path" class="input-field" placeholder="/home/username/.ssh/id_ed25519">
                                        <button
                                            type="button"
                                            wire:click="browseForPrivateKey"
                                            wire:loading.attr="disabled"
                                            wire:target="browseForPrivateKey"
                                            class="shrink-0 rounded border border-edge px-3 text-[0.78rem] text-body hover:bg-raised disabled:opacity-50"
                                        >
                                            <span wire:loading.remove wire:target="browseForPrivateKey">Browse…</span>
                                            <span wire:loading wire:target="browseForPrivateKey">Waiting…</span>
                                        </button>
                                    </div>
                                    <span class="mt-1 block text-[0.7rem] text-faint">
                                        Select the private key itself (e.g. <code>/home/username/.ssh/id_ed25519</code> or <code>/home/username/.ssh/id_rsa</code>), not the matching <code>.pub</code> file.
                                    </span>
                                    @if ($browseMessage !== null)
                                        <span class="mt-1 block text-[0.7rem] text-amber-600 dark:text-amber-400">{{ $browseMessage }}</span>
                                    @endif
                                </label>
                            @endif
                        </div>
                    @endif

                    @if ($testResult !== null)
                        <div class="rounded border px-3 py-2 text-[0.78rem] {{ $testResult['ok'] ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-700 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-300' : 'border-red-300 bg-red-50 dark:border-red-700 dark:bg-red-950/50 text-red-700 dark:text-red-300' }}">
                            {{ $testResult['message'] }}
                        </div>
                    @endif
                </div>

                <div class="flex items-center justify-between border-t border-edge/60 px-4 py-3">
                    <button
                        wire:click="testConnection"
                        wire:loading.attr="disabled"
                        class="rounded border border-edge px-3 py-1.5 text-[0.78rem] text-body hover:bg-raised disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="testConnection">Test connection</span>
                        <span wire:loading wire:target="testConnection">Testing…</span>
                    </button>
                    <div class="flex gap-2">
                        <button wire:click="close" class="rounded border border-edge px-3 py-1.5 text-[0.78rem] text-body hover:bg-raised">Cancel</button>
                        <button
                            wire:click="save"
                            wire:loading.attr="disabled"
                            class="rounded bg-sky-600 px-4 py-1.5 text-[0.78rem] font-medium text-white hover:bg-sky-500 disabled:opacity-50"
                        >Save</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
