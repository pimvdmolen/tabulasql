<?php

namespace App\Livewire;

use App\Models\Connection;
use App\Services\QueryRunner;
use App\Services\SchemaExplorer;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

class ForeignKeyManager extends Component
{
    public const RULES = ['RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION'];

    /** @var ?array{connectionId: int, database: string, table: string} */
    public ?array $context = null;

    public array $constraints = [];

    /** @var string[] */
    public array $availableColumns = [];

    /** @var string[] */
    public array $availableTables = [];

    /** @var string[] */
    public array $refColumns = [];

    public string $newColumn = '';

    public string $newRefTable = '';

    public string $newRefColumn = '';

    public string $onDelete = 'RESTRICT';

    public string $onUpdate = 'RESTRICT';

    public ?string $error = null;

    #[On('open-fk-manager')]
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
            $this->constraints = $explorer->foreignKeyConstraints($connection, $this->context['database'], $this->context['table']);
            $this->availableColumns = array_column($explorer->columns($connection, $this->context['database'], $this->context['table']), 'name');
            $this->availableTables = array_column(
                array_filter($explorer->tables($connection, $this->context['database']), fn ($table) => $table['type'] === 'table'),
                'name'
            );
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function updatedNewRefTable(): void
    {
        $this->newRefColumn = '';
        $this->refColumns = [];

        if ($this->newRefTable === '') {
            return;
        }

        try {
            $this->refColumns = array_column(
                app(SchemaExplorer::class)->columns($this->connection(), $this->context['database'], $this->newRefTable),
                'name'
            );
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function addForeignKey(): void
    {
        $this->error = null;

        if ($this->newColumn === '' || $this->newRefTable === '' || $this->newRefColumn === '') {
            $this->error = 'Choose a column, referenced table and referenced column.';

            return;
        }

        $explorer = app(SchemaExplorer::class);
        $name = 'fk_'.$this->context['table'].'_'.$this->newColumn;

        $sql = sprintf(
            'ALTER TABLE %s.%s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s.%s (%s) ON DELETE %s ON UPDATE %s',
            $explorer->quote($this->context['database']), $explorer->quote($this->context['table']),
            $explorer->quote($name),
            $explorer->quote($this->newColumn),
            $explorer->quote($this->context['database']), $explorer->quote($this->newRefTable),
            $explorer->quote($this->newRefColumn),
            in_array($this->onDelete, self::RULES, true) ? $this->onDelete : 'RESTRICT',
            in_array($this->onUpdate, self::RULES, true) ? $this->onUpdate : 'RESTRICT',
        );

        $this->execute($sql, "Foreign key `$name` created.");
        $this->newColumn = '';
        $this->newRefTable = '';
        $this->newRefColumn = '';
    }

    public function dropForeignKey(string $name): void
    {
        $explorer = app(SchemaExplorer::class);

        $sql = sprintf(
            'ALTER TABLE %s.%s DROP FOREIGN KEY %s',
            $explorer->quote($this->context['database']), $explorer->quote($this->context['table']),
            $explorer->quote($name)
        );

        $this->execute($sql, "Foreign key `$name` dropped.");
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
        return view('livewire.foreign-key-manager');
    }
}
