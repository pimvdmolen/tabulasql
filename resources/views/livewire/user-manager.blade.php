<div>
    @if ($open)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" wire:click="close" wire:keydown.escape.window="close">
            <div class="flex max-h-[90vh] w-[min(900px,96vw)] flex-col rounded-lg border border-edge bg-surface shadow-xl" wire:click.stop>
                <div class="flex items-center justify-between border-b border-edge/60 px-4 py-2">
                    <div>
                        <h3 class="text-sm font-semibold text-strong">MySQL users & privileges</h3>
                        <p class="text-[0.72rem] text-muted">Requires a connection that can read mysql.user</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button wire:click="$set('showCreate', true)" class="rounded border border-edge px-2 py-0.5 text-[0.78rem] hover:bg-raised">New user</button>
                        <button wire:click="refreshUsers" class="rounded border border-edge px-2 py-0.5 text-[0.78rem] hover:bg-raised">Refresh</button>
                        <button wire:click="close" class="rounded px-1.5 text-muted hover:bg-raised">&times;</button>
                    </div>
                </div>

                @if ($error)
                    <div class="mx-4 mt-3 rounded border border-red-300 bg-red-50 p-2 text-[0.78rem] text-red-700 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300">{{ $error }}</div>
                @endif

                <div class="grid min-h-0 flex-1 grid-cols-1 gap-0 md:grid-cols-2">
                    <div class="min-h-0 overflow-y-auto border-r border-edge/60 p-2">
                        @forelse ($users as $user)
                            <button
                                wire:key="user-{{ $user['user'] }}-{{ $user['host'] }}"
                                wire:click="selectUser(@js($user['user']), @js($user['host']))"
                                class="mb-0.5 block w-full rounded px-2 py-1.5 text-left text-[0.78rem]
                                    {{ $selectedUser === $user['user'] && $selectedHost === $user['host'] ? 'bg-sky-500/15 text-strong' : 'hover:bg-raised text-body' }}"
                            >
                                <span class="font-mono">{{ $user['user'].'@'.$user['host'] }}</span>
                                @if ($user['plugin'])
                                    <span class="ml-2 text-[0.7rem] text-muted">{{ $user['plugin'] }}</span>
                                @endif
                            </button>
                        @empty
                            <div class="p-3 text-[0.78rem] text-muted">No users loaded.</div>
                        @endforelse
                    </div>

                    <div class="min-h-0 overflow-y-auto p-3">
                        @if ($selectedUser === null)
                            <div class="text-[0.78rem] text-muted">Select a user to see grants.</div>
                        @else
                            <div class="mb-2 flex items-center justify-between">
                                <h4 class="font-mono text-sm text-strong">{{ $selectedUser.'@'.$selectedHost }}</h4>
                                <button wire:click="dropSelected" wire:confirm="Drop this MySQL user?" class="rounded border border-red-400 px-2 py-0.5 text-[0.72rem] text-red-600 hover:bg-red-50 dark:border-red-700 dark:text-red-400">Drop</button>
                            </div>
                            <pre class="mb-3 max-h-48 overflow-auto rounded border border-edge bg-chrome p-2 font-mono text-[0.7rem] text-body whitespace-pre-wrap">{{ implode("\n", $grants) }}</pre>

                            <div class="space-y-2 rounded border border-edge p-2">
                                <div class="text-[0.78rem] font-medium text-dim">Grant on database</div>
                                <input type="text" wire:model="grantDatabase" class="input-field" placeholder="database name">
                                <input type="text" wire:model="grantPrivs" class="input-field" placeholder="SELECT, INSERT, UPDATE, DELETE">
                                <button wire:click="grantOnDatabase" class="rounded bg-sky-600 px-3 py-1 text-[0.78rem] text-white hover:bg-sky-500">Grant</button>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($showCreate)
        <div class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60" wire:click="$set('showCreate', false)">
            <div class="w-[min(420px,92vw)] rounded-lg border border-edge bg-surface p-4 shadow-xl" wire:click.stop>
                <h3 class="mb-3 text-sm font-semibold text-strong">Create MySQL user</h3>
                <label class="mb-2 block text-[0.78rem] text-dim">User
                    <input type="text" wire:model="newUser" class="input-field mt-1">
                </label>
                <label class="mb-2 block text-[0.78rem] text-dim">Host
                    <input type="text" wire:model="newHost" class="input-field mt-1" placeholder="%">
                </label>
                <label class="mb-3 block text-[0.78rem] text-dim">Password
                    <input type="password" wire:model="newPassword" class="input-field mt-1">
                </label>
                <div class="flex justify-end gap-2">
                    <button wire:click="$set('showCreate', false)" class="rounded border border-edge px-3 py-1 text-[0.78rem]">Cancel</button>
                    <button wire:click="createUser" class="rounded bg-sky-600 px-3 py-1 text-[0.78rem] text-white">Create</button>
                </div>
            </div>
        </div>
    @endif
</div>
