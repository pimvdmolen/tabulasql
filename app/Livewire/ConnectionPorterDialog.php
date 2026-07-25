<?php

namespace App\Livewire;

use App\Models\Connection;
use App\Services\ConnectionPorter;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class ConnectionPorterDialog extends Component
{
    use WithFileUploads;

    /** 'export', 'import' or null (closed). */
    public ?string $mode = null;

    // --- Export state ---

    /** @var int[] */
    public array $selectedIds = [];

    public bool $encrypt = true;

    public string $passphrase = '';

    public string $passphraseConfirm = '';

    // --- Import state ---

    public $upload = null;

    public string $importPassphrase = '';

    public bool $uploadIsEncrypted = false;

    /** @var array<int, array{data: array, exists: bool, action: string}>|null */
    public ?array $preview = null;

    public ?string $error = null;

    public ?string $summary = null;

    #[On('open-export-connections')]
    public function openExport(): void
    {
        $this->resetState();
        $this->mode = 'export';
        $this->selectedIds = Connection::pluck('id')->all();
    }

    #[On('open-import-connections')]
    public function openImport(): void
    {
        $this->resetState();
        $this->mode = 'import';
    }

    public function close(): void
    {
        $this->resetState();
    }

    public function export()
    {
        $this->error = null;

        if ($this->selectedIds === []) {
            $this->error = 'Select at least one connection.';

            return;
        }

        if ($this->encrypt) {
            if (strlen($this->passphrase) < 4) {
                $this->error = 'Choose a passphrase of at least 4 characters.';

                return;
            }

            if ($this->passphrase !== $this->passphraseConfirm) {
                $this->error = 'The passphrases do not match.';

                return;
            }
        }

        $connections = Connection::whereIn('id', $this->selectedIds)->orderBy('name')->get();
        $contents = app(ConnectionPorter::class)->export(
            $connections,
            $this->encrypt ? $this->passphrase : null
        );

        $filename = 'connections-'.now()->format('Y-m-d').($this->encrypt ? '.dbmconn' : '.json');
        $this->close();

        return response()->streamDownload(function () use ($contents) {
            echo $contents;
        }, $filename);
    }

    public function updatedUpload(): void
    {
        $this->error = null;
        $this->preview = null;

        if ($this->upload === null) {
            return;
        }

        $this->uploadIsEncrypted = app(ConnectionPorter::class)->isEncrypted(
            file_get_contents($this->upload->getRealPath())
        );

        if (! $this->uploadIsEncrypted) {
            $this->readFile();
        }
    }

    public function readFile(): void
    {
        $this->error = null;

        if ($this->upload === null) {
            $this->error = 'Choose a file first.';

            return;
        }

        try {
            $imported = app(ConnectionPorter::class)->import(
                file_get_contents($this->upload->getRealPath()),
                $this->importPassphrase === '' ? null : $this->importPassphrase
            );
        } catch (Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }

        $existing = Connection::pluck('id', 'name');

        $this->preview = array_map(fn (array $data) => [
            'data' => $data,
            'exists' => isset($existing[$data['name'] ?? '']),
            'action' => isset($existing[$data['name'] ?? '']) ? 'skip' : 'import',
        ], $imported);
    }

    public function import(): void
    {
        if ($this->preview === null) {
            return;
        }

        $imported = 0;
        $overwritten = 0;
        $skipped = 0;

        foreach ($this->preview as $row) {
            $data = $row['data'];

            match ($row['action']) {
                'import' => (function () use ($data, &$imported) {
                    $data['name'] = $this->uniqueName($data['name'] ?? 'Imported connection');
                    Connection::create($data);
                    $imported++;
                })(),
                'overwrite' => (function () use ($data, &$overwritten) {
                    Connection::where('name', $data['name'] ?? '')->first()?->update($data);
                    $overwritten++;
                })(),
                default => $skipped++,
            };
        }

        $this->preview = null;
        $this->upload = null;
        $this->summary = "Imported $imported, overwrote $overwritten, skipped $skipped.";
        $this->dispatch('connection-saved');
    }

    private function uniqueName(string $name): string
    {
        $candidate = $name;
        $suffix = 2;

        while (Connection::where('name', $candidate)->exists()) {
            $candidate = "$name ($suffix)";
            $suffix++;
        }

        return $candidate;
    }

    private function resetState(): void
    {
        $this->reset();
    }

    public function render()
    {
        return view('livewire.connection-porter-dialog', [
            'connections' => $this->mode === 'export' ? Connection::orderBy('name')->get() : collect(),
        ]);
    }
}
