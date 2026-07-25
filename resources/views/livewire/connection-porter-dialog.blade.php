<div>
    @if ($mode !== null)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/60" wire:keydown.escape.window="close">
            <div class="max-h-[90vh] w-[min(520px,92vw)] overflow-y-auto rounded-lg border border-edge bg-surface shadow-xl">
                <div class="flex items-center justify-between border-b border-edge/60 px-4 py-3">
                    <h2 class="text-sm font-semibold text-strong">
                        {{ $mode === 'export' ? 'Export Connections' : 'Import Connections' }}
                    </h2>
                    <button wire:click="close" class="rounded px-1.5 text-muted hover:bg-raised hover:text-body">&times;</button>
                </div>

                <div class="space-y-3 px-4 py-4">
                    @if ($error !== null)
                        <div class="rounded border border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-950/40 px-3 py-2 text-[0.78rem] text-red-700 dark:text-red-300">{{ $error }}</div>
                    @endif
                    @if ($summary !== null)
                        <div class="rounded border border-emerald-300 bg-emerald-50 dark:border-emerald-700 dark:bg-emerald-950/50 px-3 py-2 text-[0.78rem] text-emerald-700 dark:text-emerald-300">{{ $summary }}</div>
                    @endif

                    @if ($mode === 'export')
                        <div class="max-h-48 space-y-0.5 overflow-y-auto rounded border border-edge/60 p-2">
                            @forelse ($connections as $connection)
                                <label class="flex items-center gap-2 rounded px-1 py-0.5 text-sm text-body hover:bg-raised" wire:key="exp-{{ $connection->id }}">
                                    <input type="checkbox" wire:model="selectedIds" value="{{ $connection->id }}" class="rounded border-edge bg-raised">
                                    <span class="size-2 rounded-full" style="background: {{ $connection->color ?? '#64748b' }}"></span>
                                    {{ $connection->name }}
                                </label>
                            @empty
                                <div class="p-2 text-[0.78rem] text-muted">No saved connections.</div>
                            @endforelse
                        </div>

                        <label class="flex items-center gap-2">
                            <input type="checkbox" wire:model.live="encrypt" class="rounded border-edge bg-raised">
                            <span class="text-sm text-body">Encrypt with a passphrase <span class="text-muted">(recommended)</span></span>
                        </label>

                        @if ($encrypt)
                            <div class="flex gap-3">
                                <label class="block flex-1">
                                    <span class="mb-1 block text-[0.78rem] text-dim">Passphrase</span>
                                    <input type="password" wire:model="passphrase" class="input-field" autocomplete="new-password">
                                </label>
                                <label class="block flex-1">
                                    <span class="mb-1 block text-[0.78rem] text-dim">Confirm</span>
                                    <input type="password" wire:model="passphraseConfirm" class="input-field" autocomplete="new-password">
                                </label>
                            </div>
                        @else
                            <div class="rounded border border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-950/40 px-3 py-2 text-[0.78rem] text-amber-700 dark:text-amber-300">
                                ⚠ Plain JSON stores all passwords readable on disk. Anyone with the file can use them.
                            </div>
                        @endif
                    @else
                        {{-- Import --}}
                        <label class="block">
                            <span class="mb-1 block text-[0.78rem] text-dim">Export file (.dbmconn or .json)</span>
                            <input type="file" wire:model="upload" accept=".dbmconn,.json"
                                class="w-full text-[0.78rem] text-dim file:mr-3 file:rounded file:border file:border-edge file:bg-raised file:px-3 file:py-1.5 file:text-[0.78rem] file:text-body">
                        </label>
                        <div wire:loading wire:target="upload" class="text-[0.78rem] text-sky-600 dark:text-sky-400">Uploading…</div>

                        @if ($upload !== null && $uploadIsEncrypted && $preview === null)
                            <div class="flex items-end gap-3">
                                <label class="block flex-1">
                                    <span class="mb-1 block text-[0.78rem] text-dim">Passphrase</span>
                                    <input type="password" wire:model="importPassphrase" wire:keydown.enter="readFile" class="input-field" autocomplete="off">
                                </label>
                                <button wire:click="readFile" class="rounded bg-sky-600 px-4 py-1.5 text-[0.78rem] font-medium text-white hover:bg-sky-500">Unlock</button>
                            </div>
                        @endif

                        @if ($preview !== null)
                            <div class="max-h-64 overflow-y-auto rounded border border-edge/60">
                                <table class="w-full text-[0.78rem]">
                                    <thead class="bg-chrome text-left text-dim">
                                        <tr>
                                            <th class="px-2 py-1">Connection</th>
                                            <th class="px-2 py-1">Host</th>
                                            <th class="px-2 py-1 w-36">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($preview as $index => $row)
                                            <tr class="border-t border-edge/40 text-body" wire:key="imp-{{ $index }}">
                                                <td class="px-2 py-1">
                                                    {{ $row['data']['name'] ?? '?' }}
                                                    @if ($row['exists'])
                                                        <span class="ml-1 rounded bg-amber-100 px-1 text-amber-700 dark:bg-amber-950 dark:text-amber-400">name exists</span>
                                                    @endif
                                                </td>
                                                <td class="px-2 py-1 text-muted">{{ $row['data']['host'] ?? '' }}:{{ $row['data']['port'] ?? '' }}</td>
                                                <td class="px-2 py-1">
                                                    <select wire:model="preview.{{ $index }}.action" class="w-full rounded border border-edge bg-raised px-1 py-0.5 text-[0.78rem] text-body">
                                                        <option value="import">{{ $row['exists'] ? 'Import as copy' : 'Import' }}</option>
                                                        @if ($row['exists'])
                                                            <option value="overwrite">Overwrite</option>
                                                        @endif
                                                        <option value="skip">Skip</option>
                                                    </select>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    @endif
                </div>

                <div class="flex justify-end gap-2 border-t border-edge/60 px-4 py-3">
                    <button wire:click="close" class="rounded border border-edge px-3 py-1.5 text-[0.78rem] text-body hover:bg-raised">
                        {{ $summary !== null ? 'Close' : 'Cancel' }}
                    </button>
                    @if ($mode === 'export')
                        <button wire:click="export" wire:loading.attr="disabled"
                            class="rounded bg-sky-600 px-4 py-1.5 text-[0.78rem] font-medium text-white hover:bg-sky-500 disabled:opacity-50">Export</button>
                    @elseif ($preview !== null)
                        <button wire:click="import" wire:loading.attr="disabled"
                            class="rounded bg-sky-600 px-4 py-1.5 text-[0.78rem] font-medium text-white hover:bg-sky-500 disabled:opacity-50">Import</button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
