<?php

namespace App\Livewire;

use App\Models\Connection;
use App\Models\Setting;
use App\Services\ConnectionManager;
use App\Services\SchemaExplorer;
use App\Services\TableCopier;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

class CopyWizard extends Component
{
    /** @var ?array{connectionId: int, database: string, objects: array} */
    public ?array $context = null;

    /** @var string[] Names of the objects selected for copying. */
    public array $selected = [];

    /** @var string[] Object types collapsed in the tree (e.g. 'procedure'). */
    public array $collapsedGroups = [];

    public ?int $targetConnectionId = null;

    public ?string $targetDatabase = null;

    /** @var string[] */
    public array $targetDatabases = [];

    public bool $withData = true;

    public string $conflict = 'drop';

    /** Rows fetched per batch when copying table data. */
    public int $batchSize = 1000;

    /** Confirmation overlay (summary → live logs). */
    public bool $confirming = false;

    public ?array $summary = null;

    public ?string $error = null;

    /**
     * Loads every copyable object in the source database (not just what was
     * right-clicked), like SQLyog's copy tree: the user sees Tables, Views,
     * Procedures, Functions, Triggers and Events in full, with only the
     * originally-clicked object(s) pre-checked.
     */
    #[On('open-copy-wizard')]
    public function open(int $connectionId, string $database, array $objects): void
    {
        $this->reset();
        $this->restorePreferences();

        $connection = Connection::findOrFail($connectionId);
        $explorer = app(SchemaExplorer::class);

        $this->context = [
            'connectionId' => $connectionId,
            'connectionName' => $connection->name,
            'database' => $database,
            'objects' => [
                ...$explorer->tables($connection, $database),
                ...array_map(fn ($name) => ['name' => $name, 'type' => 'procedure'], $explorer->procedures($connection, $database)),
                ...array_map(fn ($name) => ['name' => $name, 'type' => 'function'], $explorer->functions($connection, $database)),
                ...array_map(fn ($trigger) => ['name' => $trigger['name'], 'type' => 'trigger'], $explorer->triggers($connection, $database)),
                ...array_map(fn ($name) => ['name' => $name, 'type' => 'event'], $explorer->events($connection, $database)),
            ],
        ];
        $this->selected = array_column($objects, 'name');
    }

    private function restorePreferences(): void
    {
        $this->withData = (bool) Setting::get('copy_with_data', true);
        $conflict = Setting::get('copy_conflict', 'drop');
        $this->conflict = in_array($conflict, ['skip', 'drop'], true) ? $conflict : 'drop';
        $batch = (int) Setting::get('copy_batch_size', TableCopier::CHUNK_ROWS);
        $this->batchSize = in_array($batch, TableCopier::CHUNK_OPTIONS, true) ? $batch : TableCopier::CHUNK_ROWS;
    }

    public function updatedWithData(mixed $value): void
    {
        Setting::set('copy_with_data', (bool) $value);
    }

    public function updatedConflict(string $value): void
    {
        if (in_array($value, ['skip', 'drop'], true)) {
            Setting::set('copy_conflict', $value);
        }
    }

    public function updatedBatchSize(mixed $value): void
    {
        $batch = (int) $value;
        if (in_array($batch, TableCopier::CHUNK_OPTIONS, true)) {
            $this->batchSize = $batch;
            Setting::set('copy_batch_size', $batch);
        }
    }

    /**
     * Toggle every object of one type on or off together, like the group
     * checkbox in SQLyog's copy tree: selects the whole group if any are
     * unselected, clears it if all are already selected.
     */
    public function toggleGroup(string $type): void
    {
        if ($this->context === null) {
            return;
        }

        $names = array_column(
            array_filter($this->context['objects'], fn ($object) => $object['type'] === $type),
            'name'
        );

        if (count(array_intersect($names, $this->selected)) === count($names)) {
            $this->selected = array_values(array_diff($this->selected, $names));
        } else {
            $this->selected = array_values(array_unique([...$this->selected, ...$names]));
        }
    }

    /**
     * Expand/collapse one group's items in the tree. Independent of
     * toggleGroup(), which (de)selects them instead.
     */
    public function toggleGroupExpanded(string $type): void
    {
        if (in_array($type, $this->collapsedGroups, true)) {
            $this->collapsedGroups = array_values(array_diff($this->collapsedGroups, [$type]));
        } else {
            $this->collapsedGroups[] = $type;
        }
    }

    public function updatedTargetConnectionId(): void
    {
        $this->targetDatabase = null;
        $this->targetDatabases = [];
        $this->error = null;

        if ($this->targetConnectionId === null) {
            return;
        }

        try {
            $target = Connection::findOrFail($this->targetConnectionId);
            $this->targetDatabases = array_values(array_diff(
                app(SchemaExplorer::class)->databases($target),
                ['information_schema', 'performance_schema', 'mysql', 'sys']
            ));
        } catch (Throwable $e) {
            $this->error = 'Could not connect to the target: '.$e->getMessage();
        }
    }

    /**
     * Validate options and open the confirmation overlay.
     */
    public function askConfirm(): void
    {
        $this->error = null;
        $this->summary = null;

        if ($this->context === null || $this->targetConnectionId === null || ! $this->targetDatabase) {
            $this->error = 'Choose a target connection and database.';

            return;
        }

        if ($this->selected === []) {
            $this->error = 'Select at least one object.';

            return;
        }

        $source = Connection::findOrFail($this->context['connectionId']);
        $target = Connection::findOrFail($this->targetConnectionId);

        if ($source->id === $target->id && $this->context['database'] === $this->targetDatabase) {
            $this->error = 'Source and target are the same database.';

            return;
        }

        $this->confirming = true;
    }

    public function cancelConfirm(): void
    {
        $this->confirming = false;
    }

    public function runCopy(): void
    {
        $this->error = null;
        $this->summary = null;

        if ($this->context === null || $this->targetConnectionId === null || ! $this->targetDatabase) {
            $this->error = 'Choose a target connection and database.';
            $this->confirming = false;

            return;
        }

        if ($this->selected === []) {
            $this->error = 'Select at least one object.';
            $this->confirming = false;

            return;
        }

        $source = Connection::findOrFail($this->context['connectionId']);
        $target = Connection::findOrFail($this->targetConnectionId);

        if ($source->id === $target->id && $this->context['database'] === $this->targetDatabase) {
            $this->error = 'Source and target are the same database.';
            $this->confirming = false;

            return;
        }

        $this->stream(to: 'copy-progress', content: '', replace: true);

        try {
            $this->summary = app(TableCopier::class)->copy(
                $source,
                $this->context['database'],
                array_values(array_filter(
                    $this->context['objects'],
                    fn ($object) => in_array($object['name'], $this->selected, true)
                )),
                $target,
                $this->targetDatabase,
                $this->withData,
                $this->conflict,
                fn (string $message) => $this->stream(
                    to: 'copy-progress',
                    content: '<div>'.e($message).'</div>',
                    replace: false,
                ),
                chunkRows: $this->batchSize,
            );
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            $this->confirming = false;

            return;
        }

        $this->dispatch(
            'log',
            connectionId: $this->context['connectionId'],
            type: $this->summary['failed'] > 0 ? 'error' : 'success',
            text: sprintf(
                'Copy to `%s`: %d copied, %d skipped, %d failed, %d row(s).',
                $this->targetDatabase,
                $this->summary['copied'], $this->summary['skipped'],
                $this->summary['failed'], $this->summary['rows']
            ),
        );

        $this->confirming = false;
    }

    /**
     * Raises max_allowed_packet on the target and retries just the table(s)
     * that failed for being too large, offered after runCopy() reports
     * $summary['packetTooLarge']. The failing tables were only just created
     * by this same run, so dropping and recreating them is safe.
     */
    public function fixPacketLimitAndRetry(): void
    {
        if (empty($this->summary['packetTooLarge']) || $this->targetConnectionId === null) {
            return;
        }

        $target = Connection::findOrFail($this->targetConnectionId);
        $manager = app(ConnectionManager::class);
        $suggested = max(array_column($this->summary['packetTooLarge'], 'suggested'));

        try {
            $manager->db($target)->statement("SET GLOBAL max_allowed_packet = {$suggested}");
        } catch (Throwable $e) {
            $this->error = 'Could not raise max_allowed_packet automatically on the target (this needs the '
                .'SUPER or SYSTEM_VARIABLES_ADMIN privilege): '.$e->getMessage().' Ask a database admin to run '
                ."`SET GLOBAL max_allowed_packet = {$suggested};` on the target server (or set "
                .'`max_allowed_packet = '.intdiv($suggested, 1024 * 1024)."M` under [mysqld] in its config and "
                .'restart it), then press Copy again.';

            return;
        }

        // SET GLOBAL only applies to new connections, so force one.
        $manager->reconnect($target, $this->targetDatabase);

        $this->selected = array_keys($this->summary['packetTooLarge']);
        $this->conflict = 'drop';
        Setting::set('copy_conflict', 'drop');
        // Open the progress overlay first, then start the copy on the next
        // tick so the wire:stream target is in the DOM.
        $this->confirming = true;
        $this->js('queueMicrotask(() => $wire.runCopy())');
    }

    public function close(): void
    {
        $this->reset();
    }

    public function targetConnectionName(): ?string
    {
        if ($this->targetConnectionId === null) {
            return null;
        }

        return Connection::find($this->targetConnectionId)?->name;
    }

    public function render()
    {
        return view('livewire.copy-wizard', [
            'connections' => $this->context === null ? collect() : Connection::orderBy('name')->get(),
            'targetConnectionName' => $this->targetConnectionName(),
        ]);
    }
}
