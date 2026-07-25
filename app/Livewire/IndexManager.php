<?php

namespace App\Livewire;

use App\Models\Connection;
use App\Services\QueryRunner;
use App\Services\SchemaExplorer;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

class IndexManager extends Component
{
    /** @var ?array{connectionId: int, database: string, table: string} */
    public ?array $context = null;

    public array $indexes = [];

    /** @var string[] */
    public array $availableColumns = [];

    public string $newName = '';

    /** @var string[] */
    public array $newColumns = [];

    public bool $newUnique = false;

    public ?string $confirmingDrop = null;

    public ?string $error = null;

    #[On('open-index-manager')]
    public function open(int $connectionId, string $database, string $table): void
    {
        $this->reset();
        $this->context = ['connectionId' => $connectionId, 'database' => $database, 'table' => $table];
        $this->load();
    }

    private function load(): void
    {
        try {
            $connection = $this->connection();
            $explorer = app(SchemaExplorer::class);
            $this->indexes = $explorer->indexes($connection, $this->context['database'], $this->context['table']);
            $this->availableColumns = array_column($explorer->columns($connection, $this->context['database'], $this->context['table']), 'name');
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function addIndex(): void
    {
        $this->error = null;

        if ($this->newColumns === []) {
            $this->error = 'Select at least one column.';

            return;
        }

        $explorer = app(SchemaExplorer::class);
        $name = trim($this->newName) !== '' ? trim($this->newName) : 'idx_'.implode('_', $this->newColumns);

        $sql = sprintf(
            'CREATE %sINDEX %s ON %s.%s (%s)',
            $this->newUnique ? 'UNIQUE ' : '',
            $explorer->quote($name),
            $explorer->quote($this->context['database']),
            $explorer->quote($this->context['table']),
            implode(', ', array_map($explorer->quote(...), $this->newColumns))
        );

        $this->execute($sql, "Index `$name` created.");
        $this->newName = '';
        $this->newColumns = [];
        $this->newUnique = false;
    }

    public function dropIndex(string $name): void
    {
        $this->confirmingDrop = null;
        $explorer = app(SchemaExplorer::class);
        $quotedTarget = $explorer->quote($this->context['database']).'.'.$explorer->quote($this->context['table']);

        $sql = $name === 'PRIMARY'
            ? "ALTER TABLE $quotedTarget DROP PRIMARY KEY"
            : sprintf('DROP INDEX %s ON %s', $explorer->quote($name), $quotedTarget);

        $this->execute($sql, "Index `$name` dropped.");
    }

    private function execute(string $sql, string $successMessage): void
    {
        $result = app(QueryRunner::class)->run($this->connection(), null, $sql);

        if (! $result['ok']) {
            $this->error = $result['error'];

            return;
        }

        app(SchemaExplorer::class)->forgetTable($this->connection(), $this->context['database'], $this->context['table']);
        $this->dispatch('log', connectionId: $this->context['connectionId'], type: 'success', text: $successMessage);
        $this->load();
    }

    private function connection(): Connection
    {
        return Connection::findOrFail($this->context['connectionId']);
    }

    public function close(): void
    {
        $this->reset();
    }

    public function render()
    {
        return view('livewire.index-manager');
    }
}
