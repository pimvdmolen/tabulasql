<?php

namespace App\Livewire;

use App\Models\Connection;
use App\Services\QueryRunner;
use App\Services\SchemaExplorer;
use Livewire\Attributes\On;
use Livewire\Component;

class CreateDatabaseDialog extends Component
{
    public const CHARSETS = ['utf8mb4', 'utf8', 'latin1'];

    public const COLLATIONS = [
        'utf8mb4' => ['utf8mb4_unicode_ci', 'utf8mb4_general_ci', 'utf8mb4_bin', 'utf8mb4_0900_ai_ci'],
        'utf8' => ['utf8_unicode_ci', 'utf8_general_ci', 'utf8_bin'],
        'latin1' => ['latin1_swedish_ci', 'latin1_bin'],
    ];

    public ?int $connectionId = null;

    public string $databaseName = '';

    public string $charset = 'utf8mb4';

    public string $collation = 'utf8mb4_unicode_ci';

    public ?string $error = null;

    #[On('open-create-database')]
    public function open(int $connectionId): void
    {
        $this->reset();
        $this->connectionId = $connectionId;
    }

    public function updatedCharset(): void
    {
        $this->collation = self::COLLATIONS[$this->charset][0] ?? '';
    }

    public function sql(): string
    {
        $explorer = app(SchemaExplorer::class);

        return sprintf(
            'CREATE DATABASE %s CHARACTER SET %s COLLATE %s',
            $explorer->quote(trim($this->databaseName)),
            $this->charset,
            $this->collation
        );
    }

    public function create(): void
    {
        $this->error = null;

        if (trim($this->databaseName) === '') {
            $this->error = 'Enter a database name.';

            return;
        }

        $connection = Connection::findOrFail($this->connectionId);
        $result = app(QueryRunner::class)->run($connection, null, $this->sql());

        if (! $result['ok']) {
            $this->error = $result['error'];

            return;
        }

        $this->dispatch('log', connectionId: $connection->id, type: 'success', text: "Database `{$this->databaseName}` created.");
        $this->dispatch('databases-changed', connectionId: $connection->id);
        $this->reset();
    }

    public function close(): void
    {
        $this->reset();
    }

    public function render()
    {
        return view('livewire.create-database-dialog');
    }
}
