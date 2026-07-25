<?php

namespace App\Livewire;

use App\Models\Connection;
use App\Services\QueryRunner;
use App\Services\SchemaExplorer;
use Livewire\Attributes\On;
use Livewire\Component;

class CreateTableDialog extends Component
{
    public const TYPES = [
        'INT', 'BIGINT', 'TINYINT', 'SMALLINT', 'DECIMAL(10,2)', 'FLOAT', 'DOUBLE',
        'VARCHAR(255)', 'CHAR(36)', 'TEXT', 'MEDIUMTEXT', 'LONGTEXT',
        'DATE', 'DATETIME', 'TIMESTAMP', 'TIME', 'YEAR',
        'BOOLEAN', 'JSON', 'BLOB', 'LONGBLOB', "ENUM('a','b')",
    ];

    /** @var ?array{connectionId: int, database: string} */
    public ?array $context = null;

    public string $tableName = '';

    /** @var array<int, array{name: string, type: string, nullable: bool, default: string, pk: bool, ai: bool}> */
    public array $columns = [];

    public ?string $error = null;

    #[On('open-create-table')]
    public function open(int $connectionId, string $database): void
    {
        $this->reset();
        $this->context = ['connectionId' => $connectionId, 'database' => $database];
        $this->columns = [
            ['name' => 'id', 'type' => 'INT', 'nullable' => false, 'default' => '', 'pk' => true, 'ai' => true],
        ];
    }

    public function addColumn(): void
    {
        $this->columns[] = ['name' => '', 'type' => 'VARCHAR(255)', 'nullable' => true, 'default' => '', 'pk' => false, 'ai' => false];
    }

    public function removeColumn(int $index): void
    {
        unset($this->columns[$index]);
        $this->columns = array_values($this->columns);
    }

    public function sql(): string
    {
        $explorer = app(SchemaExplorer::class);
        $definitions = [];
        $primary = [];

        foreach ($this->columns as $column) {
            if (trim($column['name']) === '') {
                continue;
            }

            $definition = $explorer->quote(trim($column['name'])).' '.trim($column['type']);
            $definition .= $column['nullable'] && ! $column['pk'] ? ' NULL' : ' NOT NULL';

            if ($column['default'] !== '') {
                $isExpression = in_array(strtoupper($column['default']), ['CURRENT_TIMESTAMP', 'NULL'], true);
                $definition .= ' DEFAULT '.($isExpression ? strtoupper($column['default']) : "'".addslashes($column['default'])."'");
            }

            if ($column['ai']) {
                $definition .= ' AUTO_INCREMENT';
            }

            $definitions[] = $definition;

            if ($column['pk']) {
                $primary[] = $explorer->quote(trim($column['name']));
            }
        }

        if ($primary !== []) {
            $definitions[] = 'PRIMARY KEY ('.implode(', ', $primary).')';
        }

        return sprintf(
            "CREATE TABLE %s.%s (\n  %s\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            $explorer->quote($this->context['database']),
            $explorer->quote(trim($this->tableName)),
            implode(",\n  ", $definitions)
        );
    }

    public function create(): void
    {
        $this->error = null;

        if (trim($this->tableName) === '') {
            $this->error = 'Enter a table name.';

            return;
        }

        if (! array_filter($this->columns, fn ($column) => trim($column['name']) !== '')) {
            $this->error = 'Add at least one column.';

            return;
        }

        $connection = Connection::findOrFail($this->context['connectionId']);
        $result = app(QueryRunner::class)->run($connection, null, $this->sql());

        if (! $result['ok']) {
            $this->error = $result['error'];

            return;
        }

        $this->dispatch('log', connectionId: $connection->id, type: 'success', text: "Table `{$this->tableName}` created.");
        $this->dispatch('schema-changed', connectionId: $connection->id, database: $this->context['database']);
        $this->reset();
    }

    public function close(): void
    {
        $this->reset();
    }

    public function render()
    {
        return view('livewire.create-table-dialog');
    }
}
