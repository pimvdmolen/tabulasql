<?php

namespace App\Livewire;

use App\Models\Connection;
use App\Services\ConnectionManager;
use App\Services\QueryRunner;
use App\Services\SchemaExplorer;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

class ObjectExplorer extends Component
{
    #[Locked]
    public int $connectionId;

    public string $search = '';

    public bool $searchRegex = false;

    /** @var string[] */
    public array $databases = [];

    /** Database name => list of tables/views (see SchemaExplorer::tables). */
    public array $loadedTables = [];

    /** Database name => ['procedures' => [...], 'functions' => [...], 'triggers' => [...], 'events' => [...]]. */
    public array $loadedRoutines = [];

    /** @var string[] Expanded database names. */
    public array $expandedDatabases = [];

    /** "db.table" keys => ['columns' => [...], 'indexes' => [...]] */
    public array $loadedTableDetails = [];

    /** @var string[] Expanded "db.table" keys. */
    public array $expandedTables = [];

    public ?string $activeDatabase = null;

    /** "database.table" of the table currently shown in Table Data. */
    public ?string $activeTable = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->loadDatabases();
    }

    public function loadDatabases(): void
    {
        try {
            $this->databases = app(SchemaExplorer::class)->databases($this->connection());
            $this->error = null;
        } catch (Throwable $e) {
            $this->databases = [];
            $this->error = $e->getMessage();
        }

        if ($this->activeDatabase === null) {
            $connection = $this->connection();
            $default = $connection->database ?? $connection->default_database;

            if ($default !== null && in_array($default, $this->databases, true)) {
                $this->toggleDatabase($default);
            }
        }
    }

    public function toggleDatabase(string $database): void
    {
        if (in_array($database, $this->expandedDatabases, true)) {
            $this->expandedDatabases = array_values(array_diff($this->expandedDatabases, [$database]));

            return;
        }

        $this->expandedDatabases[] = $database;
        $this->setActiveDatabase($database);

        if (! isset($this->loadedTables[$database])) {
            $this->refreshTables($database);
        }
    }

    private function setActiveDatabase(string $database): void
    {
        if ($this->activeDatabase !== $database) {
            $this->activeDatabase = $database;
            $this->dispatch('database-activated', connectionId: $this->connectionId, database: $database);
        }
    }

    #[On('schema-changed')]
    public function handleSchemaChanged(int $connectionId, string $database): void
    {
        if ($connectionId === $this->connectionId) {
            $this->refreshTables($database);
        }
    }

    #[On('databases-changed')]
    public function handleDatabasesChanged(int $connectionId): void
    {
        if ($connectionId === $this->connectionId) {
            $this->loadDatabases();
        }
    }

    public function refreshTables(string $database): void
    {
        $explorer = app(SchemaExplorer::class);
        $explorer->forgetDatabase($this->connection(), $database);

        try {
            $this->loadedTables[$database] = $explorer->tables($this->connection(), $database);
            $this->loadedRoutines[$database] = [
                'procedures' => $explorer->procedures($this->connection(), $database),
                'functions' => $explorer->functions($this->connection(), $database),
                'triggers' => $explorer->triggers($this->connection(), $database),
                'events' => $explorer->events($this->connection(), $database),
            ];
            $this->error = null;
        } catch (Throwable $e) {
            $this->loadedTables[$database] = [];
            $this->loadedRoutines[$database] = ['procedures' => [], 'functions' => [], 'triggers' => [], 'events' => []];
            $this->error = $e->getMessage();
        }
    }

    public function toggleTable(string $database, string $table): void
    {
        $key = "$database.$table";

        if (in_array($key, $this->expandedTables, true)) {
            $this->expandedTables = array_values(array_diff($this->expandedTables, [$key]));

            return;
        }

        $this->expandedTables[] = $key;

        if (! isset($this->loadedTableDetails[$key])) {
            try {
                $explorer = app(SchemaExplorer::class);
                $this->loadedTableDetails[$key] = [
                    'columns' => $explorer->columns($this->connection(), $database, $table),
                    'indexes' => $explorer->indexes($this->connection(), $database, $table),
                ];
            } catch (Throwable $e) {
                $this->loadedTableDetails[$key] = ['columns' => [], 'indexes' => []];
                $this->error = $e->getMessage();
            }
        }
    }

    public function selectTable(string $database, string $table): void
    {
        $this->setActiveDatabase($database);
        $this->activeTable = "$database.$table";
        $this->dispatch('table-selected', connectionId: $this->connectionId, database: $database, table: $table);
    }

    /**
     * Move to the previous/next table in the active database (in the same
     * order shown in the tree) and load it, like SQLyog's arrow-key object
     * navigation. No-op past either end of the list.
     */
    public function navigateTable(string $direction): void
    {
        if ($this->activeTable === null || $this->activeDatabase === null) {
            return;
        }

        $database = $this->activeDatabase;
        $prefix = "$database.";

        if (! str_starts_with($this->activeTable, $prefix)) {
            return;
        }

        $currentTable = substr($this->activeTable, strlen($prefix));

        // Match the tree's own display order: the Tables section
        // (alphabetical) followed by the Views section (alphabetical),
        // not a single alphabetical merge of both.
        $items = $this->filteredTables($database);
        $tables = array_column(array_values(array_filter($items, fn ($item) => $item['type'] === 'table')), 'name');
        $views = array_column(array_values(array_filter($items, fn ($item) => $item['type'] === 'view')), 'name');
        $ordered = [...$tables, ...$views];

        $index = array_search($currentTable, $ordered, true);

        if ($index === false) {
            return;
        }

        $target = $ordered[$direction === 'up' ? $index - 1 : $index + 1] ?? null;

        if ($target !== null) {
            $this->selectTable($database, $target);
        }
    }

    public function refresh(): void
    {
        $this->loadedTables = [];
        $this->loadedTableDetails = [];
        $this->loadDatabases();

        foreach ($this->expandedDatabases as $database) {
            $this->refreshTables($database);
        }
    }

    /**
     * Tables for a database with the search filter applied.
     */
    public function filteredTables(string $database): array
    {
        $tables = $this->loadedTables[$database] ?? [];

        if ($this->search === '') {
            return $tables;
        }

        return array_values(array_filter($tables, function ($table) {
            if ($this->searchRegex) {
                return @preg_match('/'.str_replace('/', '\/', $this->search).'/i', $table['name']) === 1;
            }

            return stripos($table['name'], $this->search) !== false;
        }));
    }

    // ------------------------------------------------------------------
    // Copy between connections

    /** @var string[] Checked tree items as "database|name|type". */
    public array $checked = [];

    public function clearChecked(): void
    {
        $this->checked = [];
    }

    /**
     * Open the CopyWizard for the checked objects, or for a single
     * right-clicked table when nothing relevant is checked.
     */
    public function openCopyWizard(?string $database = null, ?string $table = null, ?string $type = null): void
    {
        $objects = [];
        $sourceDatabase = null;

        foreach ($this->checked as $key) {
            [$db, $name, $objectType] = explode('|', $key, 3);
            $sourceDatabase ??= $db;

            if ($db === $sourceDatabase) {
                $objects[] = ['name' => $name, 'type' => $objectType];
            }
        }

        if ($objects === [] && $table !== null) {
            $sourceDatabase = $database;
            $objects[] = ['name' => $table, 'type' => $type ?? 'table'];
        }

        if ($objects === [] || $sourceDatabase === null) {
            return;
        }

        $this->dispatch('open-copy-wizard', connectionId: $this->connectionId, database: $sourceDatabase, objects: $objects);
    }

    /**
     * Copy wizard for a whole database (right-click on the database node):
     * offers every table and view with everything preselected.
     */
    public function openCopyWizardForDatabase(string $database): void
    {
        if (! isset($this->loadedTables[$database])) {
            $this->refreshTables($database);
        }

        $objects = array_map(
            fn ($table) => ['name' => $table['name'], 'type' => $table['type']],
            $this->loadedTables[$database] ?? []
        );

        if ($objects === []) {
            $this->dispatch('log', connectionId: $this->connectionId, type: 'info', text: "Database `$database` has no tables to copy.");

            return;
        }

        $this->dispatch('open-copy-wizard', connectionId: $this->connectionId, database: $database, objects: $objects);
    }

    // ------------------------------------------------------------------
    // Destructive/DDL operations (context menu)

    private const DROP_KEYWORDS = [
        'table' => 'TABLE',
        'view' => 'VIEW',
        'procedure' => 'PROCEDURE',
        'function' => 'FUNCTION',
        'trigger' => 'TRIGGER',
        'event' => 'EVENT',
    ];

    /** @var ?array{type: string, database: string, table: ?string, kind: string, input: string} */
    public ?array $operation = null;

    public function startOperation(string $type, string $database, ?string $table, string $kind = 'table'): void
    {
        if (! in_array($type, ['rename', 'truncate', 'drop', 'drop-database', 'truncate-database', 'empty-database'], true)) {
            return;
        }

        $this->operation = [
            'type' => $type,
            'database' => $database,
            'table' => $table,
            'kind' => $kind,
            'input' => $type === 'rename' ? (string) $table : '',
        ];
    }

    public function cancelOperation(): void
    {
        $this->operation = null;
    }

    public function executeOperation(): void
    {
        if ($this->operation === null) {
            return;
        }

        ['type' => $type, 'database' => $database, 'table' => $table, 'kind' => $kind, 'input' => $input] = $this->operation;

        // Guards: destructive ops require typing the exact object name.
        if ($type === 'drop' && $input !== $table) {
            $this->error = 'Type the exact name to confirm the drop.';

            return;
        }

        if (in_array($type, ['drop-database', 'truncate-database', 'empty-database'], true) && $input !== $database) {
            $this->error = 'Type the exact database name to confirm.';

            return;
        }

        if ($type === 'rename' && (trim($input) === '' || $input === $table)) {
            $this->error = 'Enter a new table name.';

            return;
        }

        if (in_array($type, ['truncate-database', 'empty-database'], true)) {
            $this->runDatabaseWideOperation($type, $database);

            return;
        }

        $explorer = app(SchemaExplorer::class);
        $quotedTarget = $table === null ? null : $explorer->quote($database).'.'.$explorer->quote($table);
        $dropKeyword = self::DROP_KEYWORDS[$kind] ?? 'TABLE';

        $sql = match ($type) {
            'rename' => sprintf('RENAME TABLE %s TO %s', $quotedTarget, $explorer->quote($database).'.'.$explorer->quote(trim($input))),
            'truncate' => sprintf('TRUNCATE TABLE %s', $quotedTarget),
            'drop' => sprintf('DROP %s %s', $dropKeyword, $quotedTarget),
            'drop-database' => sprintf('DROP DATABASE %s', $explorer->quote($database)),
        };

        $result = app(QueryRunner::class)->run($this->connection(), null, $sql);

        if ($result['ok']) {
            $this->dispatch('log', connectionId: $this->connectionId, type: 'success', text: "$sql. OK ({$result['duration_ms']} ms)");
            $this->operation = null;
            $this->error = null;

            if ($type === 'drop-database') {
                $this->expandedDatabases = array_values(array_diff($this->expandedDatabases, [$database]));
                unset($this->loadedTables[$database]);
                $this->loadDatabases();

                if ($this->activeTable !== null && str_starts_with($this->activeTable, "$database.")) {
                    $this->activeTable = null;
                }
            } else {
                $this->refreshTables($database);

                if ($type === 'drop' && $this->activeTable === "$database.$table") {
                    $this->activeTable = null;
                }
            }
        } else {
            $this->error = $result['error'];
        }
    }

    /**
     * Truncate/empty an entire database (screenshot-5 style "clear this out
     * before I copy fresh objects in" operation): truncate leaves structure
     * intact and only clears table data, empty drops every table, view and
     * routine so the database itself is all that remains.
     */
    private function runDatabaseWideOperation(string $type, string $database): void
    {
        $connection = $this->connection();
        $explorer = app(SchemaExplorer::class);
        $db = app(ConnectionManager::class)->db($connection, $database);

        try {
            $db->statement('SET FOREIGN_KEY_CHECKS = 0');

            foreach ($explorer->tables($connection, $database) as $object) {
                $quoted = $explorer->quote($object['name']);

                if ($type === 'truncate-database') {
                    if ($object['type'] === 'table') {
                        $db->statement("TRUNCATE TABLE $quoted");
                    }
                } else {
                    $db->statement(sprintf('DROP %s IF EXISTS %s', $object['type'] === 'view' ? 'VIEW' : 'TABLE', $quoted));
                }
            }

            if ($type === 'empty-database') {
                foreach ($explorer->procedures($connection, $database) as $name) {
                    $db->statement('DROP PROCEDURE IF EXISTS '.$explorer->quote($name));
                }

                foreach ($explorer->functions($connection, $database) as $name) {
                    $db->statement('DROP FUNCTION IF EXISTS '.$explorer->quote($name));
                }

                foreach ($explorer->triggers($connection, $database) as $trigger) {
                    $db->statement('DROP TRIGGER IF EXISTS '.$explorer->quote($trigger['name']));
                }

                foreach ($explorer->events($connection, $database) as $name) {
                    $db->statement('DROP EVENT IF EXISTS '.$explorer->quote($name));
                }
            }

            $db->statement('SET FOREIGN_KEY_CHECKS = 1');
        } catch (Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }

        $label = $type === 'truncate-database' ? 'Truncated' : 'Emptied';
        $this->dispatch('log', connectionId: $this->connectionId, type: 'success', text: "$label database `$database`.");
        $this->operation = null;
        $this->error = null;
        $this->refreshTables($database);

        if ($this->activeTable !== null && str_starts_with($this->activeTable, "$database.")) {
            $this->activeTable = null;
        }
    }

    private function connection(): Connection
    {
        return Connection::findOrFail($this->connectionId);
    }

    public function render()
    {
        return view('livewire.object-explorer');
    }
}
