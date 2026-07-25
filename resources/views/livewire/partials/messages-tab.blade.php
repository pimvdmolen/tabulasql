<div class="flex h-full flex-col">
    <div class="flex shrink-0 items-center justify-end border-b border-grid px-2 py-1">
        <button wire:click="clearMessages" class="text-[0.78rem] text-muted hover:text-body">Clear</button>
    </div>
    <div class="min-h-0 flex-1 overflow-auto p-2 font-mono text-[0.78rem]">
        @forelse ($messages as $message)
            <div class="flex gap-2 py-0.5">
                <span class="shrink-0 text-faint">{{ $message['time'] }}</span>
                <span class="{{ match ($message['type']) {
                    'error' => 'text-red-600 dark:text-red-400',
                    'success' => 'text-emerald-600 dark:text-emerald-400',
                    default => 'text-dim',
                } }}">{{ $message['text'] }}</span>
            </div>
        @empty
            <div class="p-2 text-faint">No messages yet.</div>
        @endforelse
    </div>
</div>
