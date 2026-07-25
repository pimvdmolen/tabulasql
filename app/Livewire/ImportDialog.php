<?php

namespace App\Livewire;

use App\Models\Connection;
use App\Services\SqlImporter;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class ImportDialog extends Component
{
    use WithFileUploads;

    /** @var ?array{connectionId: int, database: ?string} */
    public ?array $context = null;

    public $upload = null;

    public ?array $summary = null;

    public ?string $error = null;

    #[On('open-import-dialog')]
    public function open(int $connectionId, ?string $database = null): void
    {
        $this->reset();
        $this->context = ['connectionId' => $connectionId, 'database' => $database];
    }

    public function runImport(): void
    {
        $this->error = null;
        $this->summary = null;

        if ($this->context === null || $this->upload === null) {
            $this->error = 'Choose a .sql file first.';

            return;
        }

        $connection = Connection::findOrFail($this->context['connectionId']);

        $this->stream(to: 'import-progress', content: '', replace: true);

        try {
            $this->summary = app(SqlImporter::class)->import(
                $connection,
                $this->context['database'],
                file_get_contents($this->upload->getRealPath()),
                fn (string $message) => $this->stream(to: 'import-progress', content: '<div>'.e($message).'</div>', replace: false),
            );
        } catch (Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->dispatch('log', connectionId: $connection->id, type: $this->summary['failed'] > 0 ? 'error' : 'success', text: sprintf(
            'Import into `%s`: %d/%d statement(s) executed, %d failed, %d ms.',
            $this->context['database'] ?? '(no database)',
            $this->summary['executed'], $this->summary['statements'],
            $this->summary['failed'], $this->summary['duration_ms']
        ));

        foreach ($this->summary['errors'] as $line => $message) {
            $this->dispatch('log', connectionId: $connection->id, type: 'error', text: "Statement $line: $message");
        }
    }

    public function close(): void
    {
        $this->reset();
    }

    public function render()
    {
        return view('livewire.import-dialog');
    }
}
