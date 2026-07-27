<?php

namespace App\Livewire;

use App\Models\Connection;
use App\Services\ConnectionManager;
use Livewire\Attributes\On;
use Livewire\Component;

class Workspace extends Component
{
    /** @var array<int, array{id: int, name: string, color: ?string}> */
    public array $openTabs = [];

    public ?int $activeTabId = null;

    /** @var array<int, int> Remount counter per open connection (forces reconnect). */
    public array $tabVersions = [];

    public function mount(): void
    {
        // Restore the tabs that were open when the app was last used.
        $saved = \App\Models\Setting::get('open_tabs');

        if (! is_array($saved)) {
            return;
        }

        $connections = Connection::whereIn('id', $saved['ids'] ?? [])->get(['id', 'name', 'color'])->keyBy('id');

        foreach ($saved['ids'] ?? [] as $id) {
            if (isset($connections[$id])) {
                $this->openTabs[] = [
                    'id' => $connections[$id]->id,
                    'name' => $connections[$id]->name,
                    'color' => $connections[$id]->color,
                ];
            }
        }

        $this->activeTabId = collect($this->openTabs)->contains('id', $saved['active'] ?? null)
            ? $saved['active']
            : ($this->openTabs[0]['id'] ?? null);
    }

    private function persistTabs(): void
    {
        \App\Models\Setting::set('open_tabs', [
            'ids' => array_column($this->openTabs, 'id'),
            'active' => $this->activeTabId,
        ]);

        // Let the sidebar re-render its open/closed indicators.
        $this->dispatch('tabs-changed');
    }

    #[On('open-connection')]
    public function openConnection(int $id): void
    {
        $connection = Connection::find($id);

        if ($connection === null) {
            return;
        }

        if (! collect($this->openTabs)->contains('id', $id)) {
            $this->openTabs[] = [
                'id' => $connection->id,
                'name' => $connection->name,
                'color' => $connection->color,
            ];
        }

        $this->activeTabId = $id;
        $this->persistTabs();
    }

    public function activateTab(int $id): void
    {
        if (collect($this->openTabs)->contains('id', $id)) {
            $this->activeTabId = $id;
            $this->persistTabs();
        }
    }

    #[On('close-connection')]
    public function closeConnection(int $id): void
    {
        $this->closeTab($id);
    }

    public function closeTab(int $id): void
    {
        $this->openTabs = array_values(array_filter($this->openTabs, fn ($tab) => $tab['id'] !== $id));

        if ($this->activeTabId === $id) {
            $this->activeTabId = $this->openTabs === [] ? null : end($this->openTabs)['id'];
        }

        $connection = Connection::find($id);

        if ($connection !== null) {
            app(ConnectionManager::class)->disconnect($connection);
        }

        unset($this->tabVersions[$id]);

        $this->persistTabs();
    }

    #[On('connection-saved')]
    public function refreshTabNames(?int $id = null): void
    {
        $names = Connection::whereIn('id', array_column($this->openTabs, 'id'))
            ->get(['id', 'name', 'color'])
            ->keyBy('id');

        $this->openTabs = array_values(array_map(fn ($tab) => [
            'id' => $tab['id'],
            'name' => $names[$tab['id']]->name ?? $tab['name'],
            'color' => $names[$tab['id']]->color ?? $tab['color'],
        ], $this->openTabs));

        // Edited connection that is currently open: drop the old session
        // (PDO + SSH tunnel) and remount the tab so it reconnects fresh.
        if ($id !== null && collect($this->openTabs)->contains('id', $id)) {
            $connection = Connection::find($id);

            if ($connection !== null) {
                app(ConnectionManager::class)->disconnect($connection);
            }

            $this->tabVersions[$id] = ($this->tabVersions[$id] ?? 0) + 1;
        }
    }

    #[On('connection-deleted')]
    public function handleDeleted(int $id): void
    {
        if (collect($this->openTabs)->contains('id', $id)) {
            $this->closeTab($id);
        }
    }

    public function render()
    {
        return view('livewire.workspace');
    }
}
