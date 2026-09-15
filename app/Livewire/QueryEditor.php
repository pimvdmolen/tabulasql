<?php

namespace App\Livewire;

use App\Models\Connection;
use App\Models\QueryHistory;
use App\Models\SavedQuery;
use App\Services\AiSqlGenerator;
use App\Services\QueryRunner;
use App\Services\SchemaExplorer;
use App\Services\SqlFormatterService;
use App\Services\SqlSplitter;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

class QueryEditor extends Component
{
    #[Locked]
    public int $connectionId;

    /** @var array<int, array{id: int, title: string, sql: string}> */
    public array $tabs = [];

    public int $activeTab = 1;

    public int $nextTabId = 2;

    public ?string $activeDatabase = null;

    public bool $limitResults = true;

    public bool $showHistory = false;

    public string $historySearch = '';

    public bool $showSavedQueries = false;

    public string $savedSearch = '';

    public bool $showAiPrompt = false;

    public string $aiPrompt = '';

    public ?string $aiError = null;

    public bool $showSaveDialog = false;

    public string $saveTitle = '';

    public string $pendingSaveSql = '';

    public function mount(): void
    {
        $this->tabs = [['id' => 1, 'title' => 'Query 1', 'sql' => '']];
    }

    #[On('database-activated')]
    public function setDatabase(int $connectionId, string $database): void
    {
        if ($connectionId !== $this->connectionId) {
            return;
        }

        $next = $database !== '' ? $database : null;

        if ($this->activeDatabase === $next) {
            return;
        }

        $this->activeDatabase = $next;
        $this->pushSchema();
    }

    /**
     * Send the table/column map of the active database to the editors for
     * autocompletion.
     */
    public function pushSchema(): void
    {
        if ($this->activeDatabase === null) {
            return;
        }

        try {
            $connection = Connection::findOrFail($this->connectionId);
            $rows = app(SchemaExplorer::class)->allColumns($connection, $this->activeDatabase);
        } catch (Throwable) {
            return;
        }

        $this->dispatch(
            'sql-schema',
            connectionId: $this->connectionId,
            schema: $rows,
            driver: $connection->driverName(),
        );
    }

    /**
     * Execute a script (called from CodeMirror with the current content or
     * selection). Splits statements, runs sequentially, logs each to
     * Messages and sends the last resultset to Table Data.
     */
    public function run(string $sql): void
    {
        $connection = Connection::findOrFail($this->connectionId);
        $statements = app(SqlSplitter::class)->split($sql);

        if ($statements === []) {
            $this->dispatch('log', connectionId: $this->connectionId, type: 'info', text: 'Nothing to execute.');

            return;
        }

        $runner = app(QueryRunner::class);
        $lastResult = null;

        foreach ($statements as $statement) {
            $injected = false;

            if ($this->limitResults) {
                ['sql' => $statement, 'injected' => $injected] = $runner->injectLimit($statement);
            }

            $result = $runner->run($connection, $this->activeDatabase, $statement);
            $lastResult = $result;

            $summary = mb_substr(preg_replace('/\s+/', ' ', $statement), 0, 80);

            if (! $result['ok']) {
                $this->dispatch('log', connectionId: $this->connectionId, type: 'error', text: "$summary. {$result['error']}");

                continue;
            }

            $text = $result['is_select']
                ? "$summary. {$result['row_count']} row(s) in {$result['duration_ms']} ms"
                : "$summary. {$result['affected']} row(s) affected in {$result['duration_ms']} ms";

            if ($injected) {
                $text .= ' (LIMIT 500 added, disable "Limit results" to fetch all)';
            }

            $this->dispatch('log', connectionId: $this->connectionId, type: 'success', text: $text);
        }

        // Surface the last statement in Table Data when it's a SELECT (to
        // show its rows) or an error (so a failed query doesn't just get
        // silently parked in the Messages log).
        if ($lastResult !== null && ($lastResult['is_select'] || ! $lastResult['ok'])) {
            $key = 'query_result_'.$this->getId();
            Cache::put($key, $lastResult, now()->addMinutes(30));
            $this->dispatch('query-result', connectionId: $this->connectionId, key: $key);
        }
    }

    /**
     * EXPLAIN the first statement of the given SQL.
     * MySQL gets FORMAT=JSON so the results panel can show a plan tree.
     */
    public function explain(string $sql): void
    {
        $statements = app(SqlSplitter::class)->split($sql);

        if ($statements === []) {
            return;
        }

        $connection = Connection::findOrFail($this->connectionId);
        $prefix = match ($connection->driverName()) {
            'mysql' => 'EXPLAIN FORMAT=JSON ',
            'pgsql' => 'EXPLAIN (FORMAT JSON) ',
            default => 'EXPLAIN ',
        };

        $this->run($prefix.$statements[0]);
    }

    public function formatSql(string $sql): string
    {
        return app(SqlFormatterService::class)->format($sql);
    }

    public function openSaveDialog(string $sql): void
    {
        $this->pendingSaveSql = $sql;
        $this->saveTitle = '';
        $this->showSaveDialog = true;
        $this->showHistory = false;
        $this->showSavedQueries = false;
        $this->showAiPrompt = false;
    }

    public function saveCurrentQuery(): void
    {
        $title = trim($this->saveTitle);
        $sql = trim($this->pendingSaveSql);

        if ($title === '' || $sql === '') {
            return;
        }

        SavedQuery::create([
            'connection_id' => $this->connectionId,
            'title' => $title,
            'sql' => $sql,
            'database' => $this->activeDatabase,
        ]);

        $this->showSaveDialog = false;
        $this->pendingSaveSql = '';
        $this->saveTitle = '';
        $this->showSavedQueries = true;
        $this->dispatch('log', connectionId: $this->connectionId, type: 'success', text: 'Query saved as "'.$title.'".');
    }

    public function insertSavedQuery(int $id): void
    {
        $entry = SavedQuery::where('connection_id', $this->connectionId)->find($id);

        if ($entry === null) {
            return;
        }

        $this->dispatch('sql-insert', connectionId: $this->connectionId, tabId: $this->activeTab, sql: $entry->sql);
        $this->showSavedQueries = false;
    }

    public function deleteSavedQuery(int $id): void
    {
        SavedQuery::where('connection_id', $this->connectionId)->whereKey($id)->delete();
    }

    #[On('open-ai-prompt')]
    public function openAiPrompt(int $connectionId = 0): void
    {
        if ($connectionId !== 0 && $connectionId !== $this->connectionId) {
            return;
        }

        $this->showAiPrompt = true;
        $this->showHistory = false;
        $this->showSavedQueries = false;
        $this->aiError = null;
    }

    public function generateAiSql(): void
    {
        $this->aiError = null;

        if ($this->activeDatabase === null || $this->activeDatabase === '') {
            $this->aiError = 'Expand a database in the sidebar first. AI only uses tables and columns from the open database.';

            return;
        }

        $connection = Connection::findOrFail($this->connectionId);
        $schema = [];

        try {
            foreach (app(SchemaExplorer::class)->allColumns($connection, $this->activeDatabase) as $table => $columns) {
                $schema[] = ['table' => $table, 'columns' => $columns];
            }
        } catch (Throwable $e) {
            $this->aiError = 'Could not load schema for `'.$this->activeDatabase.'`: '.$e->getMessage();

            return;
        }

        if ($schema === []) {
            $this->aiError = 'Database `'.$this->activeDatabase.'` has no tables for AI to use. Pick another database or create tables first.';

            return;
        }

        $result = app(AiSqlGenerator::class)->generate($connection, $this->activeDatabase, $this->aiPrompt, $schema);

        if (! ($result['ok'] ?? false)) {
            $this->aiError = $result['error'] ?? 'AI request failed.';

            return;
        }

        $this->dispatch('sql-insert', connectionId: $this->connectionId, tabId: $this->activeTab, sql: $result['sql']);
        $this->showAiPrompt = false;
        $this->dispatch('log', connectionId: $this->connectionId, type: 'success', text: 'AI filled the editor. Review before running.');
    }

    #[Computed]
    public function savedQueries()
    {
        return SavedQuery::where('connection_id', $this->connectionId)
            ->when($this->savedSearch !== '', function ($query) {
                $term = '%'.$this->savedSearch.'%';

                return $query->where(function ($inner) use ($term) {
                    $inner->where('title', 'like', $term)->orWhere('sql', 'like', $term);
                });
            })
            ->latest()
            ->limit(100)
            ->get();
    }

    public function updateSql(int $tabId, string $sql): void
    {
        foreach ($this->tabs as &$tab) {
            if ($tab['id'] === $tabId) {
                $tab['sql'] = $sql;
            }
        }
    }

    public function addTab(): void
    {
        $id = $this->nextTabId++;
        $this->tabs[] = ['id' => $id, 'title' => 'Query '.(count($this->tabs) + 1), 'sql' => ''];
        $this->activeTab = $id;
    }

    public function closeTab(int $tabId): void
    {
        if (count($this->tabs) === 1) {
            return;
        }

        $this->tabs = array_values(array_filter($this->tabs, fn ($tab) => $tab['id'] !== $tabId));

        if ($this->activeTab === $tabId) {
            $this->activeTab = end($this->tabs)['id'];
        }

        $this->renumberTabTitles();
    }

    private function renumberTabTitles(): void
    {
        foreach ($this->tabs as $index => &$tab) {
            $tab['title'] = 'Query '.($index + 1);
        }
        unset($tab);
    }

    public function activateTab(int $tabId): void
    {
        $this->activeTab = $tabId;
    }

    #[On('open-in-query-tab')]
    public function openInQueryTab(int $connectionId, string $database, string $table): void
    {
        if ($connectionId !== $this->connectionId) {
            return;
        }

        $this->addTab();
        $connection = Connection::findOrFail($this->connectionId);
        $explorer = app(SchemaExplorer::class);
        $from = $connection->driverName() === 'sqlite'
            ? $explorer->quote($table, $connection)
            : $explorer->quote($database, $connection).'.'.$explorer->quote($table, $connection);
        $this->dispatch(
            'sql-insert',
            connectionId: $this->connectionId,
            tabId: $this->activeTab,
            sql: sprintf('SELECT * FROM %s LIMIT 100;', $from),
        );
    }

    #[On('paste-sql-template')]
    public function pasteSqlTemplate(int $connectionId, string $database, string $table, string $kind): void
    {
        if ($connectionId !== $this->connectionId) {
            return;
        }

        $connection = Connection::findOrFail($this->connectionId);
        $explorer = app(SchemaExplorer::class);
        $q = fn (string $id) => $explorer->quote($id, $connection);
        $quotedTarget = $connection->driverName() === 'sqlite'
            ? $q($table)
            : $q($database).'.'.$q($table);

        try {
            if ($kind === 'CREATE') {
                $template = $explorer->ddl($connection, $database, $table).';';
            } else {
                $columns = array_column($explorer->columns($connection, $database, $table), 'name');
                $quoted = array_map($q, $columns);

                $template = match ($kind) {
                    'SELECT' => sprintf("SELECT %s\nFROM %s\nWHERE ;", implode(', ', $quoted), $quotedTarget),
                    'INSERT' => sprintf(
                        "INSERT INTO %s\n    (%s)\nVALUES\n    (%s);",
                        $quotedTarget, implode(', ', $quoted),
                        implode(', ', array_fill(0, count($quoted), "''"))
                    ),
                    'UPDATE' => sprintf(
                        "UPDATE %s\nSET\n%s\nWHERE ;",
                        $quotedTarget,
                        implode(",\n", array_map(fn ($column) => "    $column = ", $quoted))
                    ),
                    'DELETE' => sprintf("DELETE FROM %s\nWHERE ;", $quotedTarget),
                    default => '',
                };
            }
        } catch (Throwable $e) {
            $this->dispatch('log', connectionId: $this->connectionId, type: 'error', text: $e->getMessage());

            return;
        }

        if ($template !== '') {
            $this->dispatch('sql-insert', connectionId: $this->connectionId, tabId: $this->activeTab, sql: $template);
        }
    }

    /**
     * Create/Alter for views, procedures, functions, triggers and events:
     * opens a fresh tab with either a DDL skeleton (create-*) or the
     * object's fetched CREATE statement (alter-*), same "edit the DDL
     * yourself" convention as pasteSqlTemplate()'s CREATE kind for tables.
     */
    #[On('paste-routine-template')]
    public function pasteRoutineTemplate(int $connectionId, string $database, string $kind, ?string $name = null, ?string $table = null): void
    {
        if ($connectionId !== $this->connectionId) {
            return;
        }

        $connection = Connection::findOrFail($this->connectionId);
        $explorer = app(SchemaExplorer::class);
        $quotedDb = $explorer->quote($database, $connection);
        $q = fn (string $id) => $explorer->quote($id, $connection);

        try {
            $template = match ($kind) {
                'create-view' => "CREATE VIEW {$quotedDb}.".$q('new_view')." AS\nSELECT ;",
                'alter-view' => $explorer->ddl($connection, $database, (string) $name).';',
                'create-procedure' => "CREATE PROCEDURE {$quotedDb}.".$q('new_procedure')."()\nBEGIN\n\nEND",
                'alter-procedure' => $explorer->procedureDdl($connection, $database, (string) $name).';',
                'create-function' => "CREATE FUNCTION {$quotedDb}.".$q('new_function')."() RETURNS INT DETERMINISTIC\nBEGIN\n    RETURN 0;\nEND",
                'alter-function' => $explorer->functionDdl($connection, $database, (string) $name).';',
                'create-trigger' => sprintf(
                    "CREATE TRIGGER %s.%s BEFORE INSERT ON %s.%s\nFOR EACH ROW\nBEGIN\n\nEND",
                    $quotedDb, $q('new_trigger'), $quotedDb, $q($table ?? 'table_name')
                ),
                'alter-trigger' => $explorer->triggerDdl($connection, $database, (string) $name).';',
                'create-event' => "CREATE EVENT {$quotedDb}.".$q('new_event')." ON SCHEDULE EVERY 1 DAY DO\nBEGIN\n\nEND",
                'alter-event' => $explorer->eventDdl($connection, $database, (string) $name).';',
                default => '',
            };
        } catch (Throwable $e) {
            $this->dispatch('log', connectionId: $this->connectionId, type: 'error', text: $e->getMessage());

            return;
        }

        if ($template !== '') {
            $this->addTab();
            $this->dispatch('sql-insert', connectionId: $this->connectionId, tabId: $this->activeTab, sql: $template);
        }
    }

    public function insertFromHistory(int $historyId): void
    {
        $entry = QueryHistory::find($historyId);

        if ($entry === null) {
            return;
        }

        $this->dispatch('sql-insert', connectionId: $this->connectionId, tabId: $this->activeTab, sql: $entry->query);
        $this->showHistory = false;
    }

    #[Computed]
    public function history()
    {
        return QueryHistory::where('connection_id', $this->connectionId)
            ->when($this->historySearch !== '', fn ($query) => $query->where('query', 'like', '%'.$this->historySearch.'%'))
            ->latest('executed_at')
            ->limit(100)
            ->get();
    }

    public function render()
    {
        return view('livewire.query-editor');
    }
}
