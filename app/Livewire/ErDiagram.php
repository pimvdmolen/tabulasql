<?php

namespace App\Livewire;

use App\Models\Connection;
use App\Services\ErDiagramBuilder;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

class ErDiagram extends Component
{
    public bool $open = false;

    public int $connectionId = 0;

    public string $database = '';

    /** @var array{tables: array, edges: array}|null */
    public ?array $graph = null;

    public ?string $error = null;

    #[On('open-er-diagram')]
    public function open(int $connectionId, string $database): void
    {
        $this->connectionId = $connectionId;
        $this->database = $database;
        $this->error = null;
        $this->graph = null;
        $this->open = true;
        $this->loadGraph();
    }

    public function loadGraph(): void
    {
        try {
            $connection = Connection::findOrFail($this->connectionId);
            $this->graph = app(ErDiagramBuilder::class)->build($connection, $this->database);
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            $this->graph = null;
        }
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function render()
    {
        return view('livewire.er-diagram');
    }
}
