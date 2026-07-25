<?php

namespace App\Livewire;

use App\Models\Connection;
use App\Services\SchemaExplorer;
use App\Services\SqlDumper;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

class ExportWizard extends Component
{
    /** @var ?array{connectionId: int, database: string} */
    public ?array $context = null;

    /** @var array<int, array{name: string, type: string}> */
    public array $objects = [];

    /** @var string[] Selected object names. */
    public array $selected = [];

    public bool $withStructure = true;

    public bool $withData = true;

    public bool $dropIfExists = false;

    public bool $createDatabase = false;

    public ?string $error = null;

    #[On('open-export-wizard')]
    public function open(int $connectionId, string $database, ?array $objects = null): void
    {
        $this->reset();
        $this->context = ['connectionId' => $connectionId, 'database' => $database];

        try {
            $connection = Connection::findOrFail($connectionId);
            $this->objects = array_map(
                fn ($table) => ['name' => $table['name'], 'type' => $table['type']],
                app(SchemaExplorer::class)->tables($connection, $database)
            );
        } catch (Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->selected = $objects === null
            ? array_column($this->objects, 'name')
            : array_column($objects, 'name');
    }

    public function runExport()
    {
        $this->error = null;

        if ($this->context === null || $this->selected === []) {
            $this->error = 'Select at least one object.';

            return;
        }

        if (! $this->withStructure && ! $this->withData) {
            $this->error = 'Choose structure, data, or both.';

            return;
        }

        $connection = Connection::findOrFail($this->context['connectionId']);
        $database = $this->context['database'];
        $objects = array_values(array_filter($this->objects, fn ($object) => in_array($object['name'], $this->selected, true)));

        $stream = fopen('php://temp/maxmemory:16777216', 'w+');

        $this->stream(to: 'export-progress', content: '', replace: true);

        try {
            $summary = app(SqlDumper::class)->dump(
                $connection, $database, $objects, $stream,
                structure: $this->withStructure,
                data: $this->withData,
                dropIfExists: $this->dropIfExists,
                createDatabase: $this->createDatabase,
                progress: fn (string $message) => $this->stream(to: 'export-progress', content: '<div>'.e($message).'</div>', replace: false),
            );
        } catch (Throwable $e) {
            fclose($stream);
            $this->error = $e->getMessage();

            return;
        }

        $this->dispatch('log', connectionId: $connection->id, type: $summary['errors'] === [] ? 'success' : 'error', text: sprintf(
            'Exported `%s`: %d table(s), %d view(s), %d row(s)%s.',
            $database, $summary['tables'], $summary['views'], $summary['rows'],
            $summary['errors'] === [] ? '' : ', '.count($summary['errors']).' error(s)'
        ));

        rewind($stream);
        $filename = $database.'-'.now()->format('Ymd-His').'.sql';
        $this->context = null;

        return response()->streamDownload(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        }, $filename);
    }

    public function close(): void
    {
        $this->reset();
    }

    public function render()
    {
        return view('livewire.export-wizard');
    }
}
