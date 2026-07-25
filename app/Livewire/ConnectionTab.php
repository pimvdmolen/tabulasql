<?php

namespace App\Livewire;

use App\Models\Connection;
use App\Services\ConnectionManager;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ConnectionTab extends Component
{
    #[Locked]
    public int $connectionId;

    public bool $connected = false;

    public ?string $connectionError = null;

    public ?string $serverVersion = null;

    public function mount(): void
    {
        $connection = Connection::find($this->connectionId);

        if ($connection === null) {
            $this->connectionError = 'Connection no longer exists.';

            return;
        }

        $result = app(ConnectionManager::class)->test($connection);
        $this->connected = $result['ok'];
        $this->serverVersion = $result['version'];
        $this->connectionError = $result['ok'] ? null : $result['message'];
    }

    public function retry(): void
    {
        $this->mount();
    }

    /**
     * @return ?array{pid: int, port: int, alive: bool}
     */
    public function tunnelStatus(): ?array
    {
        $connection = Connection::find($this->connectionId);

        if ($connection === null || ! $connection->use_ssh) {
            return null;
        }

        return app(\App\Services\SshTunnel::class)->status($connection->id);
    }

    public function render()
    {
        return view('livewire.connection-tab');
    }
}
