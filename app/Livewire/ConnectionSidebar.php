<?php

namespace App\Livewire;

use App\Models\Connection;
use App\Models\Setting;
use App\Support\ConnectionColor;
use Livewire\Attributes\On;
use Livewire\Component;

class ConnectionSidebar extends Component
{
    public ?int $confirmingDeleteId = null;

    #[On('connection-saved')]
    #[On('tabs-changed')]
    public function refreshList(): void
    {
        // Re-render; the list is queried in render().
    }

    public function openConnection(int $id): void
    {
        // A real Livewire action (rather than a raw $dispatch from Alpine)
        // so wire:loading/wire:target can show a spinner while the new
        // connection-tab component mounts and connects.
        $this->dispatch('open-connection', id: $id);
    }

    public function reorderConnections(int $from, int $to): void
    {
        $connections = Connection::orderBy('sort_order')->orderBy('name')->get();

        if ($from === $to || $from < 0 || $to < 0 || $from >= $connections->count() || $to >= $connections->count()) {
            return;
        }

        $ordered = $connections->values()->all();
        $item = array_splice($ordered, $from, 1)[0];
        array_splice($ordered, $to, 0, [$item]);

        foreach ($ordered as $index => $connection) {
            if ((int) $connection->sort_order !== $index) {
                $connection->forceFill(['sort_order' => $index])->saveQuietly();
            }
        }
    }

    public function duplicateConnection(int $id): void
    {
        $source = Connection::find($id);

        if ($source === null) {
            return;
        }

        $copy = $source->replicate();
        $copy->name = $this->uniqueCopyName($source->name);
        $copy->sort_order = ((int) Connection::max('sort_order')) + 1;
        $copy->color = ConnectionColor::resolve($copy->color, $copy->name);
        $copy->save();

        $this->dispatch('connection-saved');
    }

    private function uniqueCopyName(string $name): string
    {
        $base = preg_replace('/ \(copy(?: \d+)?\)$/', '', $name) ?: $name;
        $candidate = $base.' (copy)';
        $n = 2;

        while (Connection::where('name', $candidate)->exists()) {
            $candidate = $base.' (copy '.$n.')';
            $n++;
        }

        return $candidate;
    }

    public function confirmDelete(int $id): void
    {
        $this->confirmingDeleteId = $id;
    }

    public function deleteConnection(int $id): void
    {
        Connection::find($id)?->delete();
        $this->confirmingDeleteId = null;
        $this->dispatch('connection-deleted', id: $id);
    }

    public function render()
    {
        $openTabs = Setting::get('open_tabs');

        return view('livewire.connection-sidebar', [
            'connections' => Connection::orderBy('sort_order')->orderBy('name')->get(),
            'openIds' => $openTabs['ids'] ?? [],
            'activeId' => $openTabs['active'] ?? null,
        ]);
    }
}
